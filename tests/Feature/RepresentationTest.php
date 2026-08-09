<?php

declare(strict_types=1);

use App\Domain\TicTacToe\Analysis;
use App\Domain\TicTacToe\InvalidMoveList;
use App\Domain\TicTacToe\Mark;
use App\Domain\TicTacToe\Move as DomainMove;
use App\Domain\TicTacToe\MoveList;
use App\Domain\TicTacToe\RulesEngine;
use App\Domain\TicTacToe\WinningLine;
use App\Games\GameState;
use App\Games\JoinCode;
use App\Games\MintedToken;
use App\Games\PlayerTokens;
use App\Models\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

// Feature: remote-tic-tac-toe, Property 11: The representation is the derivation
//
// Validates: Requirements 6.3, 6.7, 7.12, 8.3, 8.4
//
/*
 * The representation a Player receives over `GET /games/{game}` against
 * `RulesEngine::analyse` applied to the same Game's persisted Move_List. The engine
 * is the oracle and is called here independently, over rows read back from `moves`,
 * so no case compares the response to a board this file wrote down.
 *
 * Every fixture board is PLAYED, through `POST /games/{game}/moves` and two
 * Player_Sessions, rather than assembled. That is what makes "the persisted
 * `winning_mark` equals the derived winner" a claim about the application: a fixture
 * setting the column from `Analysis::winner()` — which is what
 * `GameRepresentationTest` does, correctly, for its own purpose — would make the
 * comparison true by construction.
 *
 * What is deliberately left to `GameRepresentationTest`, which drives the serialiser
 * directly: the exact key set and field types of the design's `GameProps`; the
 * derived-versus-persisted split, shown there by writing a row whose `winning_mark`
 * disagrees with the derived winner; the `waiting_for_opponent` gate on
 * `joinCode`/`joinUrl`; `lastMoveAt`'s format; the absence of token values (Req 8.7,
 * also `VisibilityTest`'s); the query count; and that `of()`'s signature cannot
 * express a conditional request. `VisibilityTest` owns the complementary half of this
 * file: what a REFUSAL must not contain. Nothing of that matrix is rebuilt here.
 *
 * `str_contains`/`in_array` with an explicit message where a search is needed, never
 * `not->toContain($needle, $message)`: that expectation takes variadic needles and no
 * message argument, so the message is silently asserted as a second needle.
 */

uses(RefreshDatabase::class);

/*
 * `throttle:state` and `throttle:move` count into the default cache store.
 * `phpunit.xml` sets `CACHE_STORE=array`, but `SqliteConnectionSettingsTest` clears
 * environment variables mid-run and the `.env` values can take over for every test
 * after it, which would make the window shared rather than per-test. The version-value
 * case issues eight state requests against one subject, well inside the 120 per minute
 * of `AppServiceProvider`'s `state` limiter, so this is insurance.
 */
beforeEach(function (): void {
    config(['cache.default' => 'array', 'cache.limiter' => null]);
});

/**
 * The boards this file sweeps, as the Cell_Indexes of a legal Move_List in
 * Sequence_Index order.
 *
 * The empty list is an `active` Game nobody has moved in, not a
 * `waiting_for_opponent` one: that state is not a domain concept and does not
 * correspond to any `Outcome` (see the class docblock on `App\Games\GameState`), so
 * comparing it against the derivation would assert a mismatch the design intends.
 *
 * `a double winning line` is `X0 O1 X2 O3 X6 O5 X8 O7 X4` — X's ninth Move at Cell 4
 * completes both diagonals. It is reachable in legal play, which is why Requirement
 * 6.3 is plural and why `winningLines` is a list.
 *
 * O wins on an even-length Move_List, since O's Moves sit at odd Sequence_Indexes:
 * X takes 4, 5, 6 (no line) while O completes the top row at Sequence_Index 5.
 *
 * @return array<string, list<int>>
 */
function derivationBoards(): array
{
    return [
        'an empty Move_List' => [],
        'a mid-game board' => [4, 0, 8],
        'a board X won' => [0, 3, 1, 4, 2],
        'a board O won' => [4, 0, 5, 1, 6, 2],
        'a drawn board' => [0, 1, 2, 4, 3, 5, 7, 6, 8],
        'a double winning line' => [0, 1, 2, 3, 6, 5, 8, 7, 4],
    ];
}

/**
 * The board names as a dataset, so a failure names its own board.
 *
 * The name is passed rather than the list because a `list<int>` arriving as a dataset
 * parameter cannot carry its element type; the body looks the list up again.
 *
 * @return array<string, array{string}>
 */
function derivationBoardCases(): array
{
    $cases = [];

    foreach (array_keys(derivationBoards()) as $name) {
        $cases[$name] = [$name];
    }

    return $cases;
}

/**
 * The Cell_Indexes of `$name`'s board.
 *
 * @return list<int>
 */
function derivationCellsFor(string $name): array
{
    return derivationBoards()[$name] ?? throw new RuntimeException("there is no board named {$name}");
}

/**
 * An `active` Game with `$cellIndices` PLAYED into it through the move route, and the
 * two Player_Tokens bound to it.
 *
 * The row is created directly rather than through `POST /games` plus a join, because a
 * feature test has one session and a real join writes whichever session is current —
 * handing the browser under test the opponent's credential. `version_counter` starts at
 * one for the join (Req 2.6); every increment after that is `SubmitMove`'s (Req 4.7),
 * and so are `state` and `winning_mark`.
 *
 * Each POST is asserted to be a 303 with nothing flashed, which is the guard that every
 * Move was accepted: a rejection flashes `outcome`, so a board that could not be played
 * fails here rather than becoming a quietly shorter Move_List.
 *
 * @param  list<int>  $cellIndices
 * @return array{game: Game, x: MintedToken, o: MintedToken}
 */
function derivationFixture(array $cellIndices): array
{
    $tokens = new PlayerTokens;
    $x = $tokens->mint();
    $o = $tokens->mint();

    $game = new Game;
    $game->id = Str::uuid7()->toString();
    $game->join_code = JoinCode::generate()->stored;
    $game->state = GameState::Active;
    $game->winning_mark = null;
    $game->version_counter = 1;
    $game->x_token_hash = $x->hash;
    $game->o_token_hash = $o->hash;
    $game->last_activity_at = now()->subMinutes(5);
    $game->save();

    foreach ($cellIndices as $sequenceIndex => $cellIndex) {
        derivationSession($game->id, Mark::forSequenceIndex($sequenceIndex) === Mark::X ? $x : $o);

        post('/games/'.$game->id.'/moves', ['cell_index' => $cellIndex])
            ->assertStatus(303)
            ->assertSessionMissing('outcome');
    }

    $game->refresh();

    return ['game' => $game, 'x' => $x, 'o' => $o];
}

/**
 * Starts a fresh Player_Session holding exactly `$token` for `$gameId`.
 *
 * Fresh rather than cleared, so a token another Mark put in the session cannot survive
 * into this one: session attributes persist across requests within a test, which is
 * what makes a presented credential reach the request at all and also what makes the
 * flush necessary between the two Players.
 *
 * `Store::isValidId()` accepts 40 alphanumeric characters and silently replaces
 * anything else with a generated id, which would make a failed switch look like a
 * successful one.
 */
function derivationSession(string $gameId, MintedToken $token): void
{
    Session::flush();
    Session::setId(Str::random(40));
    Session::start();

    (new PlayerTokens)->remember($gameId, $token);
}

/**
 * The persisted Move_List of `$gameId`, carried verbatim.
 *
 * `fromMoves()` and not `fromCellIndices()`, for `GameSnapshot::of()`'s reason: the
 * latter renumbers Sequence_Indexes 0..n-1 and would silently repair a gap, making an
 * ill-formed Move_List look well formed to the oracle.
 */
function derivationPersistedMoveList(string $gameId): MoveList
{
    $moves = [];

    $rows = DB::table('moves')
        ->where('game_id', $gameId)
        ->orderBy('sequence_index')
        ->pluck('cell_index', 'sequence_index');

    foreach ($rows as $sequenceIndex => $cellIndex) {
        $moves[] = new DomainMove((int) $cellIndex, (int) $sequenceIndex);
    }

    return MoveList::fromMoves($moves);
}

/**
 * The Cell_Indexes of `$gameId`'s persisted Move_List, in Sequence_Index order.
 *
 * @return list<int>
 */
function derivationPersistedCells(string $gameId): array
{
    return derivationPersistedMoveList($gameId)->cellIndices();
}

/**
 * `RulesEngine::analyse` over `$moveList` — the oracle, called independently of the
 * response under test and never mocked.
 */
function derivationAnalyse(MoveList $moveList): Analysis
{
    $analysis = RulesEngine::analyse($moveList);

    if ($analysis instanceof InvalidMoveList) {
        throw new RuntimeException('the persisted Move_List is not well formed, so there is no derivation to compare a representation against');
    }

    return $analysis;
}

/**
 * The four persisted facts of `$gameId` that the representation reads from the row
 * rather than from the derivation, plus the Game_Id of its Rematch.
 *
 * `rematch_of_game_id` lives on the *rematch* row, so the Rematch is found by looking
 * for a row pointing back at this one; reading `$gameId`'s own column would get the
 * direction exactly wrong.
 *
 * @return array{state: string, winningMark: string|null, version: int, rematchGameId: string|null}
 */
function derivationRowOf(string $gameId): array
{
    $state = DB::table('games')->where('id', $gameId)->value('state');
    $winningMark = DB::table('games')->where('id', $gameId)->value('winning_mark');
    $version = DB::table('games')->where('id', $gameId)->value('version_counter');
    $rematchId = DB::table('games')->where('rematch_of_game_id', $gameId)->value('id');

    expect($state)->toBeString('there is no games row for this Game, or games.state did not read back as a string')
        ->and($version)->toBeInt('games.version_counter did not read back as an integer');

    return [
        'state' => is_string($state) ? $state : '',
        'winningMark' => is_string($winningMark) ? $winningMark : null,
        'version' => is_int($version) ? $version : -1,
        'rematchGameId' => is_string($rematchId) ? $rematchId : null,
    ];
}

/**
 * The Inertia page object `$response` carries, read from the real payload.
 *
 * Two shapes, because the polling path is a different response.
 * `AssertableInertia::fromTestResponse()` reads the `page` view data and works only for
 * the HTML document; an Inertia XHR — which is what a partial reload is — answers with
 * the page object as the JSON body and no view at all.
 *
 * @param  TestResponse<Response>  $response
 * @return array<string, mixed>
 */
function derivationPageOf(TestResponse $response): array
{
    if (! str_contains((string) $response->headers->get('Content-Type'), 'application/json')) {
        return AssertableInertia::fromTestResponse($response)->toArray();
    }

    $decoded = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

    return is_array($decoded) ? $decoded : [];
}

/**
 * The Inertia props `$response` carries.
 *
 * @param  TestResponse<Response>  $response
 * @return array<string, mixed>
 */
function derivationPropsOf(TestResponse $response): array
{
    $page = derivationPageOf($response);

    return is_array($page['props'] ?? null) ? $page['props'] : [];
}

/**
 * The `game` prop of `$response`, or an empty array if it carries none.
 *
 * @param  TestResponse<Response>  $response
 * @return array<string, mixed>
 */
function derivationGamePropOf(TestResponse $response): array
{
    $props = derivationPropsOf($response);

    return is_array($props['game'] ?? null) ? $props['game'] : [];
}

/**
 * The nine cells of `$derived`, as the representation spells them.
 *
 * @return list<string|null>
 */
function derivationBoardOf(Analysis $derived): array
{
    $board = [];

    for ($cellIndex = 0; $cellIndex < 9; $cellIndex++) {
        $board[] = $derived->board->occupantOf($cellIndex)?->value;
    }

    return $board;
}

/**
 * The Moves of `$moveList` as the representation spells them, with each Mark derived
 * from Sequence_Index parity (Req 11.4) rather than read from anywhere.
 *
 * @return list<array{cell: int, sequence: int, mark: string}>
 */
function derivationMovesOf(MoveList $moveList): array
{
    $moves = [];

    foreach ($moveList as $move) {
        $moves[] = ['cell' => $move->cellIndex, 'sequence' => $move->sequenceIndex, 'mark' => $move->mark()->value];
    }

    return $moves;
}

/**
 * The completed Winning_Lines of `$derived` as their Cell triples, in the engine's own
 * order — not sorted, so the two sides of the comparison are the same list rather than
 * the same set.
 *
 * @return list<array{int, int, int}>
 */
function derivationLinesOf(Analysis $derived): array
{
    return array_map(static fn (WinningLine $line): array => $line->cells(), $derived->winningLines);
}

/**
 * Every label the detector below can report, which the falsification case at the foot
 * of this file asserts it does report.
 *
 * @return list<string>
 */
function derivationLabels(): array
{
    return [
        'the Board',
        'the Move_List',
        'the Mark_To_Move',
        'the terminal result',
        'the Winning_Line set',
        'the winning Mark returned',
        'the persisted winning Mark',
        'the viewing Player\'s own Mark',
        'whose turn it is',
        'the Version_Counter',
        'the Rematch Game_Id',
    ];
}

/**
 * Every way `$props` fails to be the derivation, as a label-to-explanation map. Empty
 * is what Property 11 requires of a response to a Player.
 *
 * One function rather than a run of expectations per case, so the same comparisons can
 * be pointed at inputs that are known to disagree — which is what shows they are
 * capable of failing. Strict `!==` throughout: an array comparison that is not strict
 * would accept `'0'` for `0` and a reordered `winningLines` for the derived one.
 *
 * `state` is compared against `GameState::fromOutcome($derived->outcome)` because the
 * representation has no `outcome` field: the terminal result reaches the client as
 * `state` plus `winningMark`.
 *
 * @param  array<string, mixed>  $props
 * @param  array{state: string, winningMark: string|null, version: int, rematchGameId: string|null}  $row
 * @return array<string, string>
 */
function derivationDivergences(array $props, Analysis $derived, MoveList $moveList, Mark $yourMark, array $row): array
{
    $found = [];

    $expected = [
        'the Board' => ['board', derivationBoardOf($derived)],
        'the Move_List' => ['moves', derivationMovesOf($moveList)],
        'the Mark_To_Move' => ['markToMove', $derived->markToMove->value],
        'the terminal result' => ['state', GameState::fromOutcome($derived->outcome)->value],
        'the Winning_Line set' => ['winningLines', derivationLinesOf($derived)],
        'the winning Mark returned' => ['winningMark', $derived->winner()?->value],
        'the viewing Player\'s own Mark' => ['yourMark', $yourMark->value],
        'whose turn it is' => ['isYourTurn', $derived->markToMove === $yourMark],
        'the Version_Counter' => ['version', $row['version']],
        'the Rematch Game_Id' => ['rematchGameId', $row['rematchGameId']],
    ];

    foreach ($expected as $label => [$key, $value]) {
        if (! array_key_exists($key, $props)) {
            $found[$label] = 'the representation carries no `'.$key.'` at all';

            continue;
        }

        if ($props[$key] !== $value) {
            $found[$label] = 'the representation reports '.json_encode($props[$key]).' as `'.$key.'` where the derivation gives '.json_encode($value);
        }
    }

    // Not a field of the representation: the CLAIM that the column the Game_Service
    // wrote agrees with what the engine derives from the Move_List it wrote alongside
    // it. `GameRepresentation` reads the column and reconciles nothing, so this is the
    // only place the two sources are compared.
    if ($row['winningMark'] !== $derived->winner()?->value) {
        $found['the persisted winning Mark'] = 'games.winning_mark holds '.json_encode($row['winningMark']).' where the derived winner is '.json_encode($derived->winner()?->value);
    }

    return $found;
}

/*
 * ─────────────────────────────────────────────────────────────────────────────
 * THE REPRESENTATION IS THE DERIVATION (Req 6.3, 6.7, 7.12, 8.3).
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Six boards, each played through the move route, each served to BOTH Players and
 * compared against `RulesEngine::analyse` over the rows read back from `moves`.
 *
 * Both Players matter for two of the claims. `isYourTurn` is asserted for each, and
 * asserted to hold for exactly one of them, so a serialiser returning a constant fails
 * whichever constant it returns. And everything except `yourMark` and `isYourTurn` is
 * asserted identical between the two, which is Property 11's "for any Player": the
 * Board a Player is shown does not depend on which Player is asking.
 *
 * The two guards before the comparison: the persisted Move_List is the one this case
 * reasons about — read from the table, so a rejected Move would fail here rather than
 * silently shortening the board — and the response carries a representation at all.
 */
it('returns the derivation of the persisted move list to both players of the game', function (string $board) {
    $cells = derivationCellsFor($board);
    $fixture = derivationFixture($cells);
    $game = $fixture['game'];

    expect(derivationPersistedCells($game->id))->toBe($cells, "the persisted Move_List is not {$board}, so this case is not the one it names");

    $moveList = derivationPersistedMoveList($game->id);
    $derived = derivationAnalyse($moveList);
    $row = derivationRowOf($game->id);

    /** @var array<string, array<string, mixed>> $seen */
    $seen = [];

    foreach ([Mark::X, Mark::O] as $mark) {
        derivationSession($game->id, $mark === Mark::X ? $fixture['x'] : $fixture['o']);

        $response = get('/games/'.$game->id);

        $response->assertOk();

        $props = derivationGamePropOf($response);

        expect($props)->not->toBe([], "{$board}: the response to the {$mark->value} Player carries no game prop, so there is nothing to compare against the derivation");

        $divergences = derivationDivergences($props, $derived, $moveList, $mark, $row);

        expect($divergences)->toBe(
            [],
            "{$board}, as seen by the {$mark->value} Player: the representation is not the derivation of the persisted Move_List — ".implode('; ', $divergences),
        );

        $seen[$mark->value] = $props;
    }

    $forX = $seen['x'];
    $forO = $seen['o'];

    // Requirement 6.7's "whether that Mark belongs to the viewing Player": exactly one
    // of the two Players is to move, on every board including a finished one, where
    // `markToMove` names who would have moved next (Req 4.1).
    $turns = [$forX['isYourTurn'] ?? null, $forO['isYourTurn'] ?? null];

    expect(in_array($turns, [[true, false], [false, true]], true))->toBeTrue(
        "{$board}: isYourTurn did not hold for exactly one of the two Players — ".json_encode($turns).' — so it is not markToMove === yourMark (Req 6.7)',
    );

    $perViewer = ['yourMark' => null, 'isYourTurn' => null];

    expect(array_diff_key($forX, $perViewer))->toBe(
        array_diff_key($forO, $perViewer),
        "{$board}: the two Players were served different representations of the same Game in a field other than yourMark and isYourTurn",
    );
})->with(fn (): array => derivationBoardCases());

/*
 * ─────────────────────────────────────────────────────────────────────────────
 * THE PERSISTED WINNING MARK IS THE DERIVED WINNER (Req 6.3).
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * The column against the engine, on a Game the application itself finished. `SubmitMove`
 * writes `games.winning_mark` from `Analysis::winner()` inside the move transaction and
 * `GameRepresentation` reads the column back without reconciling it against anything
 * (see that class's docblock), so this is the one comparison that would notice the two
 * drifting apart.
 *
 * Both null on the drawn board and on the unfinished one, which is why they are here:
 * a comparison over won boards alone would pass for an implementation that stored the
 * Mark to move.
 */
it('persists a winning mark equal to the derived winner, and none where the derivation has no winner', function () {
    $expected = [
        'a board X won' => 'x',
        'a board O won' => 'o',
        'a drawn board' => null,
        'a mid-game board' => null,
    ];

    foreach ($expected as $board => $winner) {
        $cells = derivationCellsFor($board);
        $fixture = derivationFixture($cells);
        $game = $fixture['game'];

        expect(derivationPersistedCells($game->id))->toBe($cells, "the persisted Move_List is not {$board}, so this iteration asserts nothing about it");

        $derived = derivationAnalyse(derivationPersistedMoveList($game->id));
        $row = derivationRowOf($game->id);

        derivationSession($game->id, $fixture['x']);

        $props = derivationGamePropOf(get('/games/'.$game->id));

        expect($props)->not->toBe([], "{$board}: the response carries no game prop");

        // Spelled out per board as well as derived, so a derivation that agreed with a
        // column both got wrong still fails.
        expect($derived->winner()?->value)->toBe($winner, "{$board}: the engine does not report {$board} as won by ".json_encode($winner).', so the comparison below is not the one this iteration names')
            ->and($row['winningMark'])->toBe($winner, "{$board}: games.winning_mark is not the derived winner (Req 6.2, 6.3)")
            // `array_key_exists` first, then the value: `$props['winningMark'] ?? null`
            // would report a missing key as a null winner and pass on a drawn board.
            ->and(array_key_exists('winningMark', $props))->toBeTrue("{$board}: the representation carries no winningMark at all")
            ->and($props['winningMark'])->toBe($winner, "{$board}: the representation did not return the derived winner");
    }
});

/*
 * ─────────────────────────────────────────────────────────────────────────────
 * THE VERSION_COUNTER IS ON EVERY RESPONSE DESCRIBING THE GAME (Req 8.3).
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Three families of response describe a Game, and all three are exercised here. The
 * full page load; the partial reload the Web_Client polls with (`only: ['game']`,
 * the polling path), which is a different code path through
 * Inertia and the one that carries the Version_Counter roughly once a second; and the
 * GET that follows a rejected action, which the design's outcome table says delivers the
 * outcome together with the current state.
 *
 * The partial reload must present the asset version the application reports, because a
 * mismatch answers 409 and no representation. That is Inertia's OWN version field and
 * has nothing to do with the Game's Version_Counter — see the version-value case below.
 *
 * The counter is asserted equal to one plus the Move count, which is the join (Req 2.6)
 * plus one per accepted Move (Req 4.7), so a `version` key defaulting to zero or echoing
 * the Move count fails rather than passing.
 */
it('carries the version counter on the page, on a poll, and on the response after a rejected move', function () {
    $cells = derivationCellsFor('a board X won');
    $fixture = derivationFixture($cells);
    $game = $fixture['game'];

    $row = derivationRowOf($game->id);

    expect(derivationPersistedCells($game->id))->toBe($cells, 'the persisted Move_List is not the won board this case reasons about')
        ->and($row['version'])->toBe(1 + count($cells), 'the persisted Version_Counter is not the join plus one per accepted Move, so comparing a response against it would not pin Req 8.3')
        ->and($row['state'])->toBe('won', 'the fixture Game is not in a Terminal_State, so the rejected move below would not be refused');

    derivationSession($game->id, $fixture['x']);

    $page = get('/games/'.$game->id);

    $page->assertOk();

    $assetVersion = AssertableInertia::fromTestResponse($page)->toArray()['version'] ?? null;

    expect($assetVersion)->toBeString('the Inertia page object carries no asset version, so the poll below cannot present one');

    $poll = get('/games/'.$game->id, [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => is_string($assetVersion) ? $assetVersion : '',
        'X-Inertia-Partial-Component' => 'Game',
        'X-Inertia-Partial-Data' => 'game',
    ]);

    $poll->assertOk();

    // A rejected action in the same session, so the flash survives into the GET that
    // follows the redirect. The Game is terminal, and `SubmitMove`'s game-ended guard
    // precedes its turn guard, so the Mark presented does not change the outcome.
    post('/games/'.$game->id.'/moves', ['cell_index' => 5])->assertStatus(303);

    $afterRejection = get('/games/'.$game->id);

    $afterRejection->assertOk();

    expect(derivationPropsOf($afterRejection)['outcome'] ?? null)->toBe(
        'game_ended',
        'the move was not refused, so the response below is not the one that follows a rejected action',
    );

    foreach (['the page load' => $page, 'a poll' => $poll, 'the response after a rejected move' => $afterRejection] as $family => $response) {
        $props = derivationGamePropOf($response);

        expect($props)->not->toBe([], "{$family} carries no game prop, so it does not describe the Game at all")
            ->and(array_key_exists('version', $props))->toBeTrue("{$family} carries no Version_Counter (Req 8.3)")
            ->and($props['version'])->toBe($row['version'], "{$family} did not carry the current Version_Counter (Req 8.3)");
    }
});

/*
 * ─────────────────────────────────────────────────────────────────────────────
 * THE REMATCH GAME_ID APPEARS ONCE A REMATCH EXISTS (Req 7.12).
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Both halves, in one fixture: absent before, present to both Players after. The Rematch
 * is created through its own route rather than inserted, so "a Rematch exists" is a fact
 * about the application rather than about this file; `RematchTest` owns everything else
 * about that route, and nothing of it is re-asserted here.
 *
 * The Rematch's Game_Id is read from the row that points back at the preceding Game,
 * which is also the guard that exactly one exists (Req 7.8) — a serialiser reading
 * `$game->rematch_of_game_id` rather than the back-reference would report null here and
 * the Rematch's own id on the Rematch.
 */
it('reports the rematch game id to both players once a rematch exists, and not before', function () {
    $cells = derivationCellsFor('a drawn board');
    $fixture = derivationFixture($cells);
    $game = $fixture['game'];

    expect(derivationPersistedCells($game->id))->toBe($cells, 'the persisted Move_List is not the drawn board this case reasons about')
        ->and(derivationRowOf($game->id)['state'])->toBe('drawn', 'the fixture Game is not in a Terminal_State, so a Rematch could not be created for it (Req 7.10)')
        ->and(DB::table('games')->where('rematch_of_game_id', $game->id)->count())->toBe(0, 'the fixture already has a Rematch, so the "not before" half asserts nothing');

    foreach ([Mark::X, Mark::O] as $mark) {
        derivationSession($game->id, $mark === Mark::X ? $fixture['x'] : $fixture['o']);

        $props = derivationGamePropOf(get('/games/'.$game->id));

        expect($props)->not->toBe([], 'the response carries no game prop')
            ->and(array_key_exists('rematchGameId', $props))->toBeTrue("the {$mark->value} Player's representation carries no rematchGameId at all")
            ->and($props['rematchGameId'])->toBeNull("the {$mark->value} Player was told of a Rematch that does not exist");
    }

    derivationSession($game->id, $fixture['x']);

    post('/games/'.$game->id.'/rematch')->assertStatus(303)->assertSessionMissing('outcome');

    $rematchId = DB::table('games')->where('rematch_of_game_id', $game->id)->value('id');

    expect(DB::table('games')->where('rematch_of_game_id', $game->id)->count())->toBe(1, 'the rematch route did not leave exactly one Rematch of this Game (Req 7.8)')
        ->and($rematchId)->toBeString('the Rematch has no Game_Id, so the comparisons below would pass against nothing');

    // The other direction, before the session is switched: this session holds a token for
    // the Rematch, minted by the request above (Req 7.6), and the Rematch has no Rematch
    // of its own. Reading `$game->rematch_of_game_id` rather than the back-reference gets
    // these two exactly the wrong way round, and both halves are needed to catch it.
    $rematchPage = get('/games/'.(is_string($rematchId) ? $rematchId : ''));

    $rematchPage->assertOk();

    $onRematch = derivationGamePropOf($rematchPage);

    expect(array_key_exists('rematchGameId', $onRematch))->toBeTrue('the Rematch\'s own representation carries no rematchGameId at all')
        ->and($onRematch['rematchGameId'])->toBeNull('the Rematch reported itself as its own Rematch: the back-reference was read in the wrong direction');

    foreach ([Mark::X, Mark::O] as $mark) {
        derivationSession($game->id, $mark === Mark::X ? $fixture['x'] : $fixture['o']);

        $props = derivationGamePropOf(get('/games/'.$game->id));

        expect($props['rematchGameId'] ?? null)->toBe(
            $rematchId,
            "the {$mark->value} Player was not given the Game_Id of the Rematch (Req 7.12)",
        );
    }
});

/*
 * ─────────────────────────────────────────────────────────────────────────────
 * THE RESPONSE IS THE SAME WHATEVER VERSION THE REQUEST PRESENTS (Req 8.4).
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * The application defines no channel for a client to present a Version_Counter — there
 * is no route parameter, no query parameter and no header it reads, which is a
 * deliberate decision. So the criterion is asserted the only way an absence can be: a request
 * presents one through every channel a real client COULD use, and the representation
 * comes back identical to the one for a request that presents none.
 *
 * Three channels, seven presentations. A query parameter, since that is where a poll
 * would put it and it survives Inertia's partial reload untouched. `If-None-Match`,
 * since an ETag is what a Version_Counter would become if the design had taken
 * the conditional-request alternative; `*` is included because it matches unconditionally.
 * `If-Modified-Since` a year ahead, which is the other conditional-request channel and
 * the one that would answer 304 if anything honoured it. And a bespoke header, standing
 * for a client that invented its own.
 *
 * What is compared is the `game` prop, byte for byte. For the four presentations that
 * leave the request URI alone the WHOLE body is compared as well, which is the stronger
 * claim and the one Property 11 states. It is not made for the query-parameter
 * presentations because Inertia's page object echoes the request URI in its `url` field
 * (`node_modules/@inertiajs/…` has the client read it back), so `?version=3` changes the
 * envelope by construction while leaving the representation identical — the finding is
 * that the version reaches nothing else.
 *
 * Headers are excluded from the comparison and only headers: `x-ratelimit-remaining`
 * decrements per request under `throttle:state`, and `Set-Cookie` differs whenever the
 * session is touched.
 */
it('returns an identical representation whatever version value the request presents, with no etag and no not-modified path', function () {
    $cells = derivationCellsFor('a mid-game board');
    $fixture = derivationFixture($cells);
    $game = $fixture['game'];

    $row = derivationRowOf($game->id);
    $version = $row['version'];

    expect(derivationPersistedCells($game->id))->toBe($cells, 'the persisted Move_List is not the board this case reasons about')
        ->and($version)->toBeGreaterThan(0, 'the Version_Counter is zero, so presenting it would be indistinguishable from presenting nothing');

    derivationSession($game->id, $fixture['x']);

    $baseline = get('/games/'.$game->id);

    $baseline->assertOk();

    $expected = derivationGamePropOf($baseline);

    expect($expected)->toHaveCount(14, 'the baseline response does not carry the whole representation, so comparing others against it would prove little')
        ->and($expected['board'] ?? null)->toBe(['o', null, null, null, 'x', null, null, null, 'x'], 'the baseline board is not the played board, so the comparisons below are not about a real representation')
        ->and($baseline->headers->has('ETag'))->toBeFalse('the game page carries an ETag, which is the conditional-request path the design forbids (Req 8.4)');

    $reference = (string) json_encode($expected);
    $referenceBody = (string) $baseline->getContent();

    expect($referenceBody)->not->toBe('', 'the baseline response is empty, so comparing bodies against it would prove nothing');

    /** @var array<string, array{query: array<string, string>, headers: array<string, string>}> $presentations */
    $presentations = [
        'a query parameter carrying the current Version_Counter' => ['query' => ['version' => (string) $version], 'headers' => []],
        'a query parameter carrying a stale Version_Counter' => ['query' => ['version' => '0'], 'headers' => []],
        'a query parameter carrying a Version_Counter from the future' => ['query' => ['version' => (string) ($version + 1000)], 'headers' => []],
        'an If-None-Match header carrying the current Version_Counter' => ['query' => [], 'headers' => ['If-None-Match' => '"'.$version.'"']],
        'an If-None-Match header matching anything' => ['query' => [], 'headers' => ['If-None-Match' => '*']],
        'an If-Modified-Since header a year ahead of the last Move' => ['query' => [], 'headers' => ['If-Modified-Since' => now()->addYear()->toRfc7231String()]],
        'an X-Game-Version header carrying the current Version_Counter' => ['query' => [], 'headers' => ['X-Game-Version' => (string) $version]],
    ];

    foreach ($presentations as $presentation => $request) {
        $url = '/games/'.$game->id.($request['query'] === [] ? '' : '?'.http_build_query($request['query']));

        $response = get($url, $request['headers']);

        expect($response->getStatusCode())->toBe(
            200,
            "a state request presenting {$presentation} was answered with ".$response->getStatusCode().' rather than the full representation (Req 8.4)',
        )->and($response->headers->has('ETag'))->toBeFalse("the response to {$presentation} carries an ETag (Req 8.4)")
            ->and((string) json_encode(derivationGamePropOf($response)))->toBe(
                $reference,
                "the representation differed when the request presented {$presentation} (Req 8.4)",
            );

        if ($request['query'] === []) {
            expect((string) $response->getContent())->toBe(
                $referenceBody,
                "the response body differed when the request presented {$presentation} (Req 8.4)",
            );
        }
    }

    // Inertia's own `version` is the ASSET version and must stay unrelated to the
    // Version_Counter. Wiring the counter into `HandleInertiaRequests::version()` would
    // give the application a conditional-request path after all — a stale value there is
    // answered 409 with no props at all — so the two are asserted independent: same asset
    // version across two Games whose counters differ.
    $other = derivationFixture(derivationCellsFor('a board X won'));

    derivationSession($other['game']->id, $other['x']);

    $otherPage = get('/games/'.$other['game']->id);

    $otherPage->assertOk();

    $otherRow = derivationRowOf($other['game']->id);
    $assetVersion = AssertableInertia::fromTestResponse($baseline)->toArray()['version'] ?? null;
    $otherAssetVersion = AssertableInertia::fromTestResponse($otherPage)->toArray()['version'] ?? null;

    expect($otherRow['version'])->not->toBe($version, 'the two Games have the same Version_Counter, so comparing their asset versions proves nothing')
        ->and($assetVersion)->toBeString('the page object carries no asset version')
        ->and($otherAssetVersion)->toBe($assetVersion, 'the Inertia asset version varies with the Game, so the Version_Counter has been wired into it and a stale one would be answered 409 (Req 8.4)')
        ->and($assetVersion)->not->toBe((string) $version, 'the Inertia asset version is the Game Version_Counter');
});

/*
 * ─────────────────────────────────────────────────────────────────────────────
 * THE COMPARISONS FIRE WHEN THERE IS A DISAGREEMENT TO FIND.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Every case above asserts `derivationDivergences()` is empty, and an empty result is
 * the kind of answer a broken detector also gives. So the same function is pointed at
 * inputs that are known to disagree, one perturbation at a time, and each is asserted to
 * report its own label — with the union asserted to cover every label the detector can
 * produce, so a comparison added without a perturbation to exercise it fails here.
 *
 * The perturbations are to the EXPECTATION rather than to the application: a derivation
 * of a different Move_List, the other Player's Mark, a Version_Counter that is not the
 * persisted one, a Rematch the response does not report, and a `winning_mark` that is not
 * the derived winner. The response is a real one throughout, and the control at the top
 * shows the truthful inputs report nothing.
 */
it('reports every divergence it searches for when the response and the derivation disagree', function () {
    $fixture = derivationFixture(derivationCellsFor('a board X won'));
    $game = $fixture['game'];

    $moveList = derivationPersistedMoveList($game->id);
    $derived = derivationAnalyse($moveList);
    $row = derivationRowOf($game->id);

    derivationSession($game->id, $fixture['x']);

    $props = derivationGamePropOf(get('/games/'.$game->id));

    expect($props)->not->toBe([], 'the response carries no game prop, so the perturbations below would all fire for the wrong reason')
        ->and(derivationDivergences($props, $derived, $moveList, Mark::X, $row))->toBe([], 'the truthful inputs already disagree, so nothing below distinguishes a perturbation from the baseline');

    // Two Moves, so the Mark_To_Move, the terminal result, the Winning_Line set and the
    // winner all differ from the won board the response describes.
    $otherList = MoveList::fromCellIndices(4, 0);
    $otherDerived = derivationAnalyse($otherList);

    expect($otherDerived->markToMove)->not->toBe($derived->markToMove, 'the two Move_Lists have the same Mark_To_Move, so that label could not fire')
        ->and($otherDerived->outcome)->not->toBe($derived->outcome, 'the two Move_Lists have the same Outcome, so the terminal result could not fire');

    $perturbations = [
        'a derivation of a different Move_List' => [
            'found' => derivationDivergences($props, $otherDerived, $otherList, Mark::X, $row),
            'labels' => ['the Board', 'the Move_List', 'the Mark_To_Move', 'the terminal result', 'the Winning_Line set', 'the winning Mark returned', 'the persisted winning Mark'],
        ],
        'the other Player\'s Mark' => [
            'found' => derivationDivergences($props, $derived, $moveList, Mark::O, $row),
            'labels' => ['the viewing Player\'s own Mark', 'whose turn it is'],
        ],
        'a Version_Counter other than the persisted one' => [
            'found' => derivationDivergences($props, $derived, $moveList, Mark::X, [...$row, 'version' => $row['version'] + 1]),
            'labels' => ['the Version_Counter'],
        ],
        'a Rematch the response does not report' => [
            'found' => derivationDivergences($props, $derived, $moveList, Mark::X, [...$row, 'rematchGameId' => Str::uuid7()->toString()]),
            'labels' => ['the Rematch Game_Id'],
        ],
        'a persisted winning Mark that is not the derived winner' => [
            'found' => derivationDivergences($props, $derived, $moveList, Mark::X, [...$row, 'winningMark' => 'o']),
            'labels' => ['the persisted winning Mark'],
        ],
    ];

    $reported = [];

    foreach ($perturbations as $perturbation => $case) {
        $missed = array_values(array_diff($case['labels'], array_keys($case['found'])));

        expect($missed)->toBe(
            [],
            "the comparisons did not notice {$perturbation}, so the cases above prove nothing about: ".implode(', ', $missed),
        );

        $reported = [...$reported, ...array_keys($case['found'])];
    }

    $reported = array_values(array_unique($reported));
    $labels = derivationLabels();

    sort($reported);
    sort($labels);

    expect($reported)->toBe(
        $labels,
        'the perturbations do not exercise every comparison the detector makes: '.implode(', ', array_diff($labels, $reported)),
    );
});
