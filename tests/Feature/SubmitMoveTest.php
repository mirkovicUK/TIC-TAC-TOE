<?php

declare(strict_types=1);

use App\Domain\TicTacToe\Mark;
use App\Domain\TicTacToe\WinningLine;
use App\Games\GameState;
use App\Games\JoinCode;
use App\Games\MintedToken;
use App\Games\MoveOutcome;
use App\Games\PlayerTokens;
use App\Models\Game;
use App\Models\Move;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

// Feature: remote-tic-tac-toe, Property 9: Rejected requests change nothing
// Feature: remote-tic-tac-toe, Property 12: The Version_Counter increments exactly
// once per committed state-changing operation
//
// Validates: Requirements 3.6, 4.2, 4.3, 4.4, 4.5, 4.6, 4.7, 5.5, 6.2, 6.4
//
/*
 * Task 6.6 — a Move through the HTTP surface.
 *
 * WHAT THIS FILE IS FOR, GIVEN `SubmitMoveMechanismTest` EXISTS. That file covers
 * the *mechanism* at close range: the four guards and their order, the absence of
 * any `SELECT`, the two statements of the accepted path, both unique indexes
 * mapping to `conflict`, one winning line, the draw, and the corruption rollback.
 * None of that is re-asserted here. What it structurally cannot say is anything
 * about a REQUEST, and three claims live only here:
 *
 *   - **Requirement 3.6** — a `mark` in the payload is ignored outright. Inside
 *     `SubmitMove` the acting Mark is a parameter and there is no payload in
 *     scope, so the claim is unfalsifiable in that file: substituting
 *     `Mark::forSequenceIndex($sequenceIndex)` for `$actingMark` leaves the whole
 *     of it green, because the turn guard makes the two provably equal. This is
 *     the ONLY place in the plan where Requirement 3.6 has an assertion at all.
 *   - **`cell_index` arrives uncast** (Req 4.4). `SubmitMove::handle()` already
 *     takes `mixed`, so nothing inside it can tell whether its caller cast the
 *     value first. Only a request can: `'4'` must be refused while `4` is
 *     accepted, and `'banana'` must not become cell `0`.
 *   - **Requirement 5.5** — a rejection arrives *together with the current state*.
 *     That is a claim about the 303, the flash and the GET that follows, which is
 *     transport and therefore not expressible at the service level: `MoveOutcome`
 *     is fieldless on purpose (see its docblock).
 *
 * The win sweep is here rather than there for the same reason: what a client
 * receives is `props.game.winningLines`, and Requirement 6.3 is about **every**
 * completed line reaching the Player. The mechanism test pins one line and the
 * draw against the two persisted columns; this pins all eight lines, the
 * double-diagonal position and the draw as the representation delivers them.
 *
 * TWO PLAYERS MEANS TWO PLAYER_SESSIONS, BECAUSE THE ACTING MARK COMES FROM THE
 * PLAYER_TOKEN — see `submitMoveActAs()` for how they are established and why this
 * file does not resume a stored session the way `ConcurrencyTest` does.
 *
 * WHAT IS DELIBERATELY NOT HERE. `conflict` — two calls from one observed snapshot
 * is task 6.8's `ConcurrencyTest`, and a single request cannot reach it.
 * `not_authorised`, which `acting.player` settles before this controller runs and
 * which `ResolveActingPlayerTest` and `EntryRoutesTest` cover (Req 3.9).
 * `rate_limited`, which is framework middleware attached at task 9.4 and asserted at 9.6.
 */

uses(RefreshDatabase::class);

/**
 * A saved Game and the two Player_Tokens bound to it, with no session written.
 *
 * The tokens are minted and their hashes assigned directly rather than issued
 * through a real create-plus-join, because a join writes whichever session happens
 * to be current and there are two here — and because the Game_State is a parameter,
 * which no sequence of real requests can produce for `won` or `drawn` without
 * playing a Game first.
 *
 * `version_counter` starts at 1, not 0: an `active` Game has had exactly one
 * committed state-changing operation, the join (Req 2.6), so 1 is the honest
 * starting value and it makes Property 12's arithmetic below legible.
 * `last_activity_at` is backdated so an accepted Move visibly moves it and a
 * refused one visibly does not.
 *
 * @return array{game: Game, tokens: array{x: MintedToken, o: MintedToken}}
 */
function submitMoveFixture(GameState $state = GameState::Active, ?Mark $winningMark = null): array
{
    $tokens = new PlayerTokens;
    $x = $tokens->mint();
    $o = $tokens->mint();

    $game = new Game;
    $game->id = Str::uuid7()->toString();
    $game->join_code = JoinCode::generate()->stored;
    $game->state = $state;
    $game->winning_mark = $winningMark;
    $game->version_counter = 1;
    $game->x_token_hash = $x->hash;
    // The CHECK on `games` forbids an occupied O slot while a Game waits for an
    // opponent, which is also what that state means (Req 2.1).
    $game->o_token_hash = $state === GameState::WaitingForOpponent ? null : $o->hash;
    $game->last_activity_at = now()->subMinutes(5);
    $game->save();

    return ['game' => $game, 'tokens' => ['x' => $x, 'o' => $o]];
}

/**
 * Starts a fresh Player_Session holding exactly one Player_Token — the one bound to
 * `$mark` on the fixture's Game — and leaves it in effect for the next request.
 *
 * THIS IS WHAT MAKES THE CALLERS BELOW TWO PLAYERS RATHER THAN ONE PLAYER POSTING
 * TWICE. A session holds at most one Player_Token per Game (`player_tokens.{gameId}`
 * is one key), so "X posts, then O posts" is two sessions presenting two
 * credentials, and the Mark each request acts under is whichever credential it
 * presented and nothing else (Req 3.2). `PlayerTokens::remember()` does the writing,
 * so the key and the value are the ones the application itself would have stored.
 *
 * IT DOES NOT SAVE AND RESUME SESSIONS, WHICH IS THE ONE PLACE THIS DIVERGES FROM
 * `ConcurrencyTest`, AND THE DIVERGENCE IS DELIBERATE. That file suspends session A
 * through the session handler and resumes it later, because its claim is about the
 * winner's credential SURVIVING the loser's request — a claim about what `JoinGame`
 * wrote, which needs both sessions to still exist afterwards. No claim in this file
 * is about a stored credential surviving anything; every claim is about which token
 * a request PRESENTS. So each switch starts a clean session and writes the token it
 * means to present, which says exactly that and nothing more.
 *
 * It is also the only form that holds whatever session driver is in effect.
 * Resuming a suspended session requires the handler to hand back a payload it was
 * given under another id, which `ArraySessionHandler` does and
 * `DatabaseSessionHandler` does not when both writes come from one process — and
 * `SESSION_DRIVER=array` from `phpunit.xml` is not something a test that runs late
 * in the suite can rely on, because `SqliteConnectionSettingsTest` clears that
 * variable and the value in `.env` (`database`) takes over for every test after it.
 * Nothing here is ever read back out of the handler, so the driver cannot matter.
 *
 * `Session::flush()` IS WHAT MAKES THE PREVIOUS PLAYER'S TOKEN GO AWAY, and it also
 * discards flash data — so nothing may switch between a rejected POST and the GET
 * that follows it. That GET is Requirement 5.5's assertion and it must run in the
 * session the redirect was issued to. `submitMoveGet()` therefore never switches.
 *
 * @param  array{game: Game, tokens: array{x: MintedToken, o: MintedToken}}  $fixture
 */
function submitMoveActAs(array $fixture, Mark $mark): void
{
    Session::flush();

    // 40 alphanumeric characters is what `Store::isValidId()` accepts; anything
    // else is silently replaced by a generated id, and two switches would then be
    // indistinguishable from two switches that failed.
    Session::setId(Str::random(40));
    Session::start();

    (new PlayerTokens)->remember($fixture['game']->id, $fixture['tokens'][$mark->value]);
}

/**
 * Records `$cellIndices` as a contiguous Move_List from zero, the way a sequence of
 * accepted Moves would leave the table.
 */
function submitMoveSeed(Game $game, int ...$cellIndices): void
{
    foreach (array_values($cellIndices) as $position => $cellIndex) {
        $move = new Move;
        $move->game_id = $game->id;
        $move->cell_index = $cellIndex;
        $move->sequence_index = $position;
        $move->save();
    }
}

/**
 * `POST /games/{game}/moves` as `$acting`, with `$payload` as the request body and
 * nothing added to it.
 *
 * @param  array{game: Game, tokens: array{x: MintedToken, o: MintedToken}}  $fixture
 * @param  array<string, mixed>  $payload
 * @return TestResponse<Response>
 */
function submitMovePost(array $fixture, Mark $acting, array $payload): TestResponse
{
    submitMoveActAs($fixture, $acting);

    return post('/games/'.$fixture['game']->id.'/moves', $payload);
}

/**
 * `GET /games/{game}` in whatever session is in effect — deliberately without a
 * switch, so it can follow a redirect and read the flashed outcome.
 *
 * @return TestResponse<Response>
 */
function submitMoveGet(Game $game): TestResponse
{
    return get('/games/'.$game->id);
}

/**
 * The Inertia props a response carries, read from the real payload.
 *
 * @param  TestResponse<Response>  $response
 * @return array<string, mixed>
 */
function submitMoveProps(TestResponse $response): array
{
    $page = AssertableInertia::fromTestResponse($response)->toArray();

    return is_array($page['props'] ?? null) ? $page['props'] : [];
}

/**
 * The `game` prop out of `$props`, or an empty array if there is none — which every
 * caller asserts against, so a missing representation fails loudly instead of
 * making the assertions about it vacuous.
 *
 * @param  array<string, mixed>  $props
 * @return array<string, mixed>
 */
function submitMoveRepresentation(array $props): array
{
    $representation = $props['game'] ?? null;

    return is_array($representation) ? $representation : [];
}

/**
 * The `game` prop of `GET /games/{game}` in the session in effect.
 *
 * @return array<string, mixed>
 */
function submitMoveViewOf(Game $game): array
{
    return submitMoveRepresentation(submitMoveProps(submitMoveGet($game)));
}

/**
 * The four columns of the `games` row a Move may move, read straight from the table
 * rather than through a model, so a stale in-memory instance cannot make an
 * assertion pass.
 *
 * `last_activity_at` is included because a refusal must not move it either: it is
 * written by the same UPDATE as the Version_Counter, so a rejection that reached
 * the write shows here even if it somehow left the other three alone.
 *
 * @return array{state: string, winning_mark: string|null, version_counter: int, last_activity_at: string}
 */
function submitMoveRowOf(string $gameId): array
{
    $row = (array) DB::table('games')->where('id', $gameId)->first();

    return [
        'state' => (string) $row['state'],
        'winning_mark' => is_string($row['winning_mark']) ? $row['winning_mark'] : null,
        'version_counter' => (int) $row['version_counter'],
        'last_activity_at' => (string) $row['last_activity_at'],
    ];
}

/**
 * The persisted Move_List as `[sequence_index => cell_index]`, ordered — so an
 * assertion against it pins the Cells, their Sequence_Indexes and the contiguity of
 * those indexes from zero at once (Req 4.2).
 *
 * @return array<int, int>
 */
function submitMoveListOf(string $gameId): array
{
    $list = [];

    foreach (DB::table('moves')->where('game_id', $gameId)->orderBy('sequence_index')->get() as $row) {
        $list[(int) $row->sequence_index] = (int) $row->cell_index;
    }

    return $list;
}

/**
 * The Cells of the `moves` prop, in the order the client receives them.
 *
 * `-1` stands in for anything that is not an integer, so a malformed entry fails a
 * comparison rather than being quietly skipped out of one.
 *
 * @return list<int>
 */
function submitMoveReportedCells(mixed $moves): array
{
    if (! is_array($moves)) {
        return [-1];
    }

    $cells = [];

    foreach ($moves as $move) {
        $cell = is_array($move) ? ($move['cell'] ?? null) : null;
        $cells[] = is_int($cell) ? $cell : -1;
    }

    return $cells;
}

/**
 * The `mark` values of the `moves` prop, in order.
 *
 * @return list<string>
 */
function submitMoveReportedMarks(mixed $moves): array
{
    if (! is_array($moves)) {
        return ['(no moves prop)'];
    }

    $marks = [];

    foreach ($moves as $move) {
        $mark = is_array($move) ? ($move['mark'] ?? null) : null;
        $marks[] = is_string($mark) ? $mark : '(not a mark)';
    }

    return $marks;
}

/**
 * The `winningLines` prop as a canonical set: each line's cells sorted, then the
 * lines sorted against each other.
 *
 * NORMALISED SO THE ASSERTION IS ABOUT THE SET AND NOT ABOUT `WinningLine`'S
 * DECLARATION ORDER, which is not something Requirement 6.3 says anything about. It
 * remains a claim about *every* line, because the comparison is an equality against
 * the whole expected set: a serialiser reporting only the first line it found fails
 * it, which is what the double-diagonal test below demonstrates.
 *
 * @return list<list<int>>
 */
function submitMoveReportedLines(mixed $lines): array
{
    if (! is_array($lines)) {
        return [[-1]];
    }

    $normalised = [];

    foreach ($lines as $line) {
        if (! is_array($line)) {
            $normalised[] = [-1];

            continue;
        }

        $cells = [];

        foreach ($line as $cell) {
            $cells[] = is_int($cell) ? $cell : -1;
        }

        sort($cells);
        $normalised[] = $cells;
    }

    sort($normalised);

    return $normalised;
}

/**
 * `$line`'s cells, sorted, in the shape `submitMoveReportedLines()` returns.
 *
 * @return list<int>
 */
function submitMoveExpectedLine(WinningLine $line): array
{
    $cells = $line->cells();
    sort($cells);

    return $cells;
}

/**
 * The alternating order of play in which X completes `$line` with its third Move:
 * `[[X, a], [O, o1], [X, b], [O, o2], [X, c]]`.
 *
 * O's two Cells are the lowest-numbered Cells off the line, which is safe for every
 * one of the eight lines rather than a coincidence for some of them: O plays only
 * twice before X's third Move, and two Cells cannot complete a line. X cannot
 * complete one early either, for the same reason. So the only Move in the order that
 * ends the Game is the last, which is what the win assertions require and what
 * `submitMovePlay()` verifies by insisting every Move was accepted.
 *
 * @return list<array{Mark, int}>
 */
function submitMoveOrderCompleting(WinningLine $line): array
{
    $offLine = array_values(array_diff(range(0, 8), $line->cells()));

    $order = [];

    foreach ($line->cells() as $position => $cell) {
        $order[] = [Mark::X, $cell];

        if ($position < 2) {
            $opponentCell = array_shift($offLine);

            if ($opponentCell === null) {
                throw new RuntimeException('a Winning_Line left fewer than two Cells free, which is not a 3x3 board');
            }

            $order[] = [Mark::O, $opponentCell];
        }
    }

    return $order;
}

/**
 * Posts every Move in `$order` from the session of the Mark that owns it, and
 * returns the Version_Counter observed after each one.
 *
 * IT ASSERTS THAT EVERY MOVE WAS ACCEPTED, which is the non-vacuity guard for every
 * test that uses it: a position that ended early, a Mark posted out of turn or a
 * Cell already taken arrives as a flashed outcome, and the sweeps below would
 * otherwise go on to assert a terminal state the *fixture* rather than the
 * application had produced.
 *
 * @param  array{game: Game, tokens: array{x: MintedToken, o: MintedToken}}  $fixture
 * @param  list<array{Mark, int}>  $order
 * @return list<int>
 */
function submitMovePlay(array $fixture, array $order): array
{
    $game = $fixture['game'];
    $versions = [];

    foreach ($order as $position => [$mark, $cell]) {
        submitMovePost($fixture, $mark, ['cell_index' => $cell])
            ->assertStatus(303)
            ->assertRedirect(url('/games/'.$game->id))
            ->assertSessionMissing('outcome')
            ->assertSessionHasNoErrors();

        $versions[] = submitMoveRowOf($game->id)['version_counter'];

        expect(submitMoveListOf($game->id))->toHaveCount(
            $position + 1,
            sprintf('Move %d of the order was answered with a redirect but recorded nothing', $position),
        );
    }

    return $versions;
}

/**
 * Every assertion Property 9 and Requirement 5.5 make about ONE refused Move,
 * posted by a Player of the Game who is authorised to be there.
 *
 * Three claims, and the third is the one this file exists to be able to make:
 *
 *   - the transport is the design's — a 303 back to the game page with the outcome
 *     flashed, never a 4xx and never a 422 validation payload;
 *   - the Move_List, Game_State, winning Mark and Version_Counter are identical
 *     before and after (Property 9), read from the table rather than from a model;
 *   - the GET that follows the redirect carries the outcome TOGETHER WITH the
 *     current state (Req 5.5), asserted against the state as it was before the
 *     refusal — so a redirect that dropped the flash, or a page that arrived with
 *     no representation, fails here.
 *
 * The GET runs in the session the POST was made from, which is why nothing switches
 * in between: a switch would discard the flash and the outcome assertion would fail
 * for a reason that has nothing to do with the subject.
 *
 * It takes the WHOLE payload rather than a Cell, because one of the payloads that
 * must be refused carries a `mark` field (Req 3.6), and a helper that composed the
 * body itself could not express it.
 *
 * @param  array{game: Game, tokens: array{x: MintedToken, o: MintedToken}}  $fixture
 * @param  array<string, mixed>  $payload
 */
function submitMoveRefused(array $fixture, Mark $acting, array $payload, MoveOutcome $expected): void
{
    $game = $fixture['game'];

    $before = submitMoveRowOf($game->id);
    $listBefore = submitMoveListOf($game->id);

    submitMovePost($fixture, $acting, $payload)
        ->assertStatus(303)
        ->assertRedirect(url('/games/'.$game->id))
        ->assertSessionHas('outcome', $expected->value)
        // A Form Request or a `validate()` call would answer a bad Cell with a 422
        // and an error bag, which is a second vocabulary for a condition the design
        // gives exactly one outcome.
        ->assertSessionHasNoErrors();

    expect(submitMoveListOf($game->id))->toBe($listBefore, 'the refused request changed the Move_List (Property 9)')
        ->and(submitMoveRowOf($game->id))->toBe($before, 'the refused request changed the Game row (Property 9, Property 12)');

    $props = submitMoveProps(submitMoveGet($game));
    $representation = submitMoveRepresentation($props);

    expect($representation)->not->toBe([], 'the redirect did not deliver a representation, so Requirement 5.5 has nothing to carry the outcome alongside')
        ->and($props['outcome'] ?? null)->toBe($expected->value, 'the outcome did not reach the page the redirect landed on (Req 5.5)')
        ->and($representation['state'] ?? null)->toBe($before['state'], 'the state delivered with the outcome is not the current Game_State (Req 5.5)')
        ->and($representation['version'] ?? null)->toBe($before['version_counter'], 'the Version_Counter delivered with the outcome is not the current one (Req 5.5, 8.3)')
        ->and($representation['winningMark'] ?? null)->toBe($before['winning_mark'], 'the winning Mark delivered with the outcome is not the current one (Req 5.5)')
        ->and(submitMoveReportedCells($representation['moves'] ?? null))->toBe(array_values($listBefore), 'the Move_List delivered with the outcome is not the current one (Req 5.5)');
}

/*
 * ─────────────────────────────────────────────────────────────────────────────
 * 1. A `mark` IN THE PAYLOAD IS IGNORED OUTRIGHT (Req 3.6, 3.2).
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * A PAYLOAD MARK CANNOT GRANT A TURN. O posts into a Game where X is to move, and
 * the payload names a Mark: the one that IS to move, O's own, something that is not
 * a Mark at all, and an array. Every case must be `not_your_turn`.
 *
 * THE FIRST CASE IS THE ONE THAT BITES, and it is worth being exact about why the
 * others do not. `{"mark": "x"}` names the Mark_To_Move, so a controller reading
 * the payload instead of `ResolveActingPlayer::resolved($request)->mark` would pass
 * the turn guard and RECORD the Move. Verified by mutation: substituting
 * `Mark::tryFrom(...payload...) ?? $resolved->mark` for the acting Mark turns this
 * case's answer from `not_your_turn` into an accepted Move — and turns the next
 * test's two accepted Moves into `not_your_turn` refusals. Nothing else in the
 * suite noticed; the mutation was reverted. The other three cases cannot separate
 * the two sources, since they name a Mark that is not to move or no Mark at all,
 * and they are here because Requirement 3.6 is about a `mark` of ANY shape being
 * ignored — a payload that produced a 500 or a 422 for `["x"]` fails here.
 */
it('refuses the player who is not to move whatever mark the payload names, and records nothing', function (mixed $payloadMark) {
    $fixture = submitMoveFixture();
    $game = $fixture['game'];

    // O is a Player of this Game and it is not O's turn — asserted before the
    // refusal, so `not_your_turn` cannot be mistaken for `not_authorised` or for an
    // answer about an unreachable route.
    submitMoveActAs($fixture, Mark::O);
    $opening = submitMoveViewOf($game);

    expect($opening['yourMark'] ?? null)->toBe('o', 'the posting session does not hold the O Player_Token, so this tests authorisation rather than turn ownership')
        ->and($opening['markToMove'] ?? null)->toBe('x', 'X is not the Mark_To_Move, so a payload naming `x` would not be naming the turn it cannot grant')
        ->and($opening['isYourTurn'] ?? null)->toBeFalse('it is the posting Player\'s turn, so there is no turn for a payload to grant');

    submitMoveRefused($fixture, Mark::O, ['cell_index' => 4, 'mark' => $payloadMark], MoveOutcome::NotYourTurn);

    expect(submitMoveListOf($game->id))->toBe([], 'a payload Mark granted a turn the Player_Token does not (Req 3.6)');
})->with([
    'the mark that is to move' => ['x'],
    'the posting player\'s own mark' => ['o'],
    'not a mark at all' => ['banana'],
    'an array' => [['x']],
]);

/*
 * AND A PAYLOAD MARK CANNOT CHANGE THE MARK RECORDED (Req 3.6, 3.2, 4.2).
 *
 * The other direction, and the one that needs an ACCEPTED Move to be observable.
 * Each Player posts in turn while naming their opponent's Mark in the payload, and
 * both Moves are recorded under the Mark bound to the posting session's
 * Player_Token.
 *
 * WHY THIS BITES rather than restating parity. The Mark of a Move is the parity of
 * its Sequence_Index and is never stored, so there is no column here for a payload
 * to corrupt — which is exactly why the assertion is on ACCEPTANCE. A controller
 * taking the Mark from the payload would hand `SubmitMove` the Mark that is not to
 * move, the turn guard would refuse, and both posts below would answer
 * `not_your_turn` over an empty Move_List. That is what the mutation described
 * above produced, in that shape.
 *
 * The representation is read after each Move, because "recorded under the token's
 * Mark" is a claim about what the Players see: the Cell shows the poster's Mark, the
 * Move_List reports it, and the turn has passed to the other Mark.
 */
it('records a move under the mark bound to the posting session, not the mark in the payload', function () {
    $fixture = submitMoveFixture();
    $game = $fixture['game'];

    // ---- X posts, naming O in the payload. ----
    submitMovePost($fixture, Mark::X, ['cell_index' => 0, 'mark' => 'o'])
        ->assertStatus(303)
        ->assertRedirect(url('/games/'.$game->id))
        ->assertSessionMissing('outcome');

    $afterX = submitMoveViewOf($game);
    $boardAfterX = is_array($afterX['board'] ?? null) ? $afterX['board'] : [];

    expect(submitMoveListOf($game->id))->toBe([0 => 0], 'X\'s Move was not recorded at Sequence_Index 0 (Req 3.6, 4.2)')
        ->and($afterX['yourMark'] ?? null)->toBe('x', 'the posting session is not the X Player, so the claims below are about the wrong Mark')
        ->and($boardAfterX[0] ?? null)->toBe('x', 'the Cell X took is not held by X, so the payload Mark reached the board (Req 3.6)')
        ->and(submitMoveReportedMarks($afterX['moves'] ?? null))->toBe(['x'], 'the recorded Move is not reported under X (Req 3.6)')
        ->and($afterX['markToMove'] ?? null)->toBe('o', 'the turn did not pass to O after X moved (Req 4.1)')
        ->and($afterX['version'] ?? null)->toBe(2, 'the accepted Move did not increment the Version_Counter by one (Req 4.7)');

    // ---- O posts, naming X in the payload. ----
    submitMovePost($fixture, Mark::O, ['cell_index' => 4, 'mark' => 'x'])
        ->assertStatus(303)
        ->assertSessionMissing('outcome');

    $afterO = submitMoveViewOf($game);
    $boardAfterO = is_array($afterO['board'] ?? null) ? $afterO['board'] : [];

    expect(submitMoveListOf($game->id))->toBe([0 => 0, 1 => 4], 'O\'s Move was not recorded at Sequence_Index 1 (Req 3.6, 4.2)')
        ->and($afterO['yourMark'] ?? null)->toBe('o')
        ->and($boardAfterO[4] ?? null)->toBe('o', 'the Cell O took is not held by O, so the payload Mark reached the board (Req 3.6)')
        ->and(submitMoveReportedMarks($afterO['moves'] ?? null))->toBe(['x', 'o'], 'the Move_List is not reported under the Marks that posted it (Req 3.6)')
        ->and($afterO['markToMove'] ?? null)->toBe('x')
        ->and($afterO['version'] ?? null)->toBe(3, 'the second accepted Move did not increment the Version_Counter by one (Req 4.7, Property 12)');
});

/*
 * ─────────────────────────────────────────────────────────────────────────────
 * 2. `cell_index` ARRIVES UNCAST (Req 4.4).
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * `'4'`, `'banana'` and an array are each `invalid_move` on a 303 — not a 422, not
 * an error bag, and emphatically not a Move.
 *
 * THE CONTRAST AT THE END IS WHAT MAKES THIS AN ASSERTION ABOUT THE CAST rather
 * than about a route that refuses everything: the integer `4`, posted from the same
 * session into the same Game, IS accepted and lands in cell 4. So `'4'` was refused
 * for being a string, which is the whole of the claim. Verified by fixture:
 * substituting the integer `4` for the string `'4'` in the dataset fails the
 * `invalid_move` expectation, so the outcome really does turn on the type.
 *
 * `'banana'` is the case with teeth in production: `$request->integer('cell_index')`
 * turns it into `0` — a legal, free Cell — and records a Move in the top-left
 * corner. Verified by mutation: swapping `input()` for `integer()` in
 * `SubmitMoveController` fails all three cases here (each of them as an *accepted*
 * Move, so on the flashed-outcome assertion first) plus the Property 12 test below,
 * and nothing else in the suite. The mutation was reverted.
 */
it('hands cell_index over uncast, so a string or an array is invalid_move and never a 422', function (mixed $cellIndex) {
    $fixture = submitMoveFixture();
    $game = $fixture['game'];

    submitMoveRefused($fixture, Mark::X, ['cell_index' => $cellIndex], MoveOutcome::InvalidMove);

    expect(submitMoveListOf($game->id))->toBe([], 'a cell_index that is not an integer was cast into a legal Cell and recorded (Req 4.4)');

    // The same session, the same Game, an integer: accepted. Without this, every
    // assertion above would also hold for a route that refused all Moves.
    submitMovePost($fixture, Mark::X, ['cell_index' => 4])
        ->assertStatus(303)
        ->assertSessionMissing('outcome');

    expect(submitMoveListOf($game->id))->toBe([0 => 4], 'the integer 4 was not accepted, so the refusals above are not about the type of the payload value');
})->with([
    'a numeric string' => ['4'],
    'a string that is not a number' => ['banana'],
    'an array' => [['4']],
]);

/*
 * ─────────────────────────────────────────────────────────────────────────────
 * 3. THE WIN TRANSITION, ALL EIGHT LINES, AS THE CLIENT RECEIVES IT (Req 6.2,
 *    6.3, 4.2, 4.7).
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Five Moves posted through HTTP, alternating Players, for each of the eight
 * Winning_Lines. `SubmitMoveMechanismTest` pins the transition and the two
 * persisted columns for ONE line, and `GameRepresentationTest` pins the
 * serialisation of a won board from a hand-built Move_List. What is asserted here is
 * the two together, over all eight lines and produced by real requests: the `state`
 * and `winning_mark` that `SubmitMove` wrote at the fifth Move agree with the line
 * the engine re-derives from the persisted row, and the three values a Player
 * actually receives — `state`, `winningMark` and the full `winningLines` set — are
 * read from the LOSER's session, since Requirement 6.3 is about the representation
 * returned to a Player of the Game.
 *
 * The dataset is derived from `WinningLine::all()` rather than written out, so a
 * line added to the enum is swept without anyone remembering to extend this test;
 * the count is asserted inside the test so the sweep cannot silently shrink either.
 */
it('sets the state, the winning mark and the completed line for each of the eight winning lines', function (WinningLine $line) {
    $fixture = submitMoveFixture();
    $game = $fixture['game'];

    expect(WinningLine::all())->toHaveCount(8, 'the eight Winning_Lines of Requirement 6.3 are no longer eight, so this sweep no longer covers them');

    $order = submitMoveOrderCompleting($line);

    expect($order)->toHaveCount(5, 'the order of play is not five Moves, so the last Move is not X\'s third');

    $before = submitMoveRowOf($game->id);
    $versions = submitMovePlay($fixture, $order);
    $row = submitMoveRowOf($game->id);

    submitMoveActAs($fixture, Mark::O);
    $representation = submitMoveViewOf($game);
    $board = is_array($representation['board'] ?? null) ? $representation['board'] : [];

    expect($row['state'])->toBe(GameState::Won->value, 'the Move completing a Winning_Line did not set the Game_State to won (Req 6.2)')
        ->and($row['winning_mark'])->toBe(Mark::X->value, 'the winning Mark was not recorded (Req 6.2)')
        ->and($row['last_activity_at'])->toBeGreaterThan($before['last_activity_at'], 'the accepted Moves did not move last_activity_at')
        // Property 12: five accepted Moves over a Game that had joined once.
        ->and($versions)->toBe([2, 3, 4, 5, 6], 'the Version_Counter did not increase by exactly one per accepted Move (Req 4.7, Property 12)')
        // Req 4.2, and contiguity from zero with it.
        ->and(array_keys(submitMoveListOf($game->id)))->toBe([0, 1, 2, 3, 4], 'the Sequence_Indexes are not 0..4 contiguously (Req 4.2)')
        // And the three values the losing Player receives.
        ->and($representation['yourMark'] ?? null)->toBe('o', 'the winning state is being read from the winner\'s own session, so it says nothing about both Players (Req 6.3)')
        ->and($representation['state'] ?? null)->toBe('won', 'the Player who lost was not told the Game is won (Req 6.3)')
        ->and($representation['winningMark'] ?? null)->toBe('x', 'the winning Mark did not reach the Player (Req 6.3)')
        ->and(submitMoveReportedLines($representation['winningLines'] ?? null))->toBe(
            [submitMoveExpectedLine($line)],
            'the completed Winning_Line did not reach the Player as its three Cells (Req 6.3)',
        );

    foreach ($line->cells() as $cell) {
        expect($board[$cell] ?? null)->toBe('x', "cell {$cell} of the completed Winning_Line is not held by the winner");
    }
})->with(function (): array {
    $cases = [];

    foreach (WinningLine::all() as $line) {
        $cases[$line->name] = [$line];
    }

    return $cases;
});

/*
 * THE DOUBLE-DIAGONAL POSITION — the one that makes "every completed line" a claim
 * rather than a form of words (Req 6.3).
 *
 * X takes 0, 2, 6 and 8 while O takes 1, 3, 5 and 7, and X's ninth Move into the
 * centre completes BOTH diagonals at once. The board is full, so this is also the
 * case that distinguishes `won` from `drawn` on a nine-Move Move_List.
 *
 * THIS IS WHERE THE FULL-SET ASSERTION EARNS ITS KEEP. A serialiser reporting the
 * first line it found passes the eight-line sweep above and everything else in this
 * file, and fails here — verified by mutation, by wrapping `GameRepresentation`'s
 * `winningLines` in `array_slice(..., 0, 1)`, which failed this test and
 * `GameRepresentationTest`'s own double-win test and nothing else. The mutation was
 * reverted.
 *
 * THAT SECOND FAILURE IS THE HONEST LIMIT OF THIS TEST'S NOVELTY, and it is worth
 * naming: the *serialisation* of a double win is already covered there, from a
 * hand-built Move_List. What is new here is that the position is REACHED by nine
 * posted Moves — so the `won` state and the winning Mark that `SubmitMove` wrote at
 * the ninth Move agree with the two lines the engine re-derives from the persisted
 * row, which no hand-built snapshot can say.
 */
it('reports both completed lines when one move closes two diagonals at once', function () {
    $fixture = submitMoveFixture();
    $game = $fixture['game'];

    $versions = submitMovePlay($fixture, [
        [Mark::X, 0], [Mark::O, 1],
        [Mark::X, 2], [Mark::O, 3],
        [Mark::X, 6], [Mark::O, 5],
        [Mark::X, 8], [Mark::O, 7],
        [Mark::X, 4],
    ]);

    $row = submitMoveRowOf($game->id);
    $representation = submitMoveViewOf($game);
    $board = is_array($representation['board'] ?? null) ? $representation['board'] : [];

    expect($row['state'])->toBe(GameState::Won->value, 'a full board completing two lines was not won (Req 6.2)')
        ->and($row['winning_mark'])->toBe(Mark::X->value)
        ->and($versions)->toBe([2, 3, 4, 5, 6, 7, 8, 9, 10], 'the Version_Counter did not increase by exactly one per accepted Move (Property 12)')
        ->and($representation['state'] ?? null)->toBe('won')
        ->and($representation['winningMark'] ?? null)->toBe('x')
        ->and(submitMoveReportedLines($representation['winningLines'] ?? null))->toBe(
            [
                submitMoveExpectedLine(WinningLine::MainDiagonal),
                submitMoveExpectedLine(WinningLine::AntiDiagonal),
            ],
            'only one of the two completed Winning_Lines reached the Player (Req 6.3)',
        )
        ->and(array_filter($board, static fn (mixed $cell): bool => $cell === null))->toBe([], 'the board is not full, so this is not the double-diagonal position');
});

/*
 * THE NINE-MOVE DRAW (Req 6.4).
 *
 * X on 0, 2, 3, 7, 8 and O on 1, 4, 5, 6 — a full board completing no line, played
 * through HTTP in an order where no prefix wins either. `submitMovePlay()` insists
 * every Move was accepted, so an early win would surface as a `game_ended` refusal
 * on the following Move rather than as a quietly different final state.
 */
it('sets the state to drawn on a ninth accepted move that completes no line', function () {
    $fixture = submitMoveFixture();
    $game = $fixture['game'];

    $versions = submitMovePlay($fixture, [
        [Mark::X, 0], [Mark::O, 1],
        [Mark::X, 2], [Mark::O, 4],
        [Mark::X, 3], [Mark::O, 5],
        [Mark::X, 7], [Mark::O, 6],
        [Mark::X, 8],
    ]);

    $row = submitMoveRowOf($game->id);
    $representation = submitMoveViewOf($game);
    $board = is_array($representation['board'] ?? null) ? $representation['board'] : [];

    expect($row['state'])->toBe(GameState::Drawn->value, 'a full board completing no Winning_Line was not drawn (Req 6.4)')
        ->and($row['winning_mark'])->toBeNull('a drawn Game recorded a winning Mark')
        ->and($versions)->toBe([2, 3, 4, 5, 6, 7, 8, 9, 10], 'the Version_Counter did not increase by exactly one per accepted Move (Property 12)')
        ->and(submitMoveListOf($game->id))->toBe([0 => 0, 1 => 1, 2 => 2, 3 => 4, 4 => 3, 5 => 5, 6 => 7, 7 => 6, 8 => 8], 'the persisted Move_List is not the nine Cells that were posted (Req 4.2)')
        ->and($representation['state'] ?? null)->toBe('drawn', 'the draw did not reach the Player (Req 6.4)')
        ->and($representation['winningMark'] ?? null)->toBeNull('a drawn Game reported a winning Mark')
        ->and(submitMoveReportedLines($representation['winningLines'] ?? null))->toBe([], 'a drawn Game reported a completed Winning_Line')
        ->and(array_filter($board, static fn (mixed $cell): bool => $cell === null))->toBe([], 'the board is not full, so this is not a draw');
});

/*
 * ─────────────────────────────────────────────────────────────────────────────
 * 4. EVERY REJECTION CHANGES NOTHING AND ARRIVES WITH THE CURRENT STATE
 *    (Property 9, Req 5.5, 4.3, 4.4, 4.5, 4.6).
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * One test per condition rather than one dataset over all of them, because each
 * needs a different fixture and the precondition that makes it non-vacuous differs
 * with it. The shared half — the transport, the four untouched values, and the
 * outcome arriving on the following GET together with the current state — is
 * `submitMoveRefused()`.
 *
 * `conflict` is absent by design: it needs two calls from one observed snapshot,
 * which is task 6.8. `not_authorised` is absent because `acting.player` answers it
 * before this controller runs (Req 3.9).
 */
it('refuses a move into a game still waiting for an opponent, leaving everything as it was', function () {
    $fixture = submitMoveFixture(GameState::WaitingForOpponent);
    $game = $fixture['game'];

    // The Creator moving into their own waiting Game passes every other guard — the
    // Mark_To_Move on an empty Move_List is X — so this is `game_not_started` rather
    // than `not_your_turn` only if the state guard comes first.
    submitMoveActAs($fixture, Mark::X);
    $opening = submitMoveViewOf($game);

    expect($opening['state'] ?? null)->toBe('waiting_for_opponent', 'the fixture Game is not waiting for an opponent (Req 4.5)')
        ->and($opening['yourMark'] ?? null)->toBe('x')
        ->and($opening['markToMove'] ?? null)->toBe('x', 'X is not the Mark_To_Move, so the turn guard could explain this refusal');

    submitMoveRefused($fixture, Mark::X, ['cell_index' => 4], MoveOutcome::GameNotStarted);
});

it('refuses a move into a game that has ended, leaving everything as it was', function (GameState $state, ?Mark $winningMark) {
    $fixture = submitMoveFixture($state, $winningMark);
    $game = $fixture['game'];

    // A coherent terminal row rather than a bare state: the won board has X holding
    // the top row, the drawn one is full. So the representation the refusal arrives
    // with has a real Move_List, a real winning Mark and real Winning_Lines to be
    // wrong about.
    if ($state === GameState::Won) {
        submitMoveSeed($game, 0, 3, 1, 4, 2);
    } else {
        submitMoveSeed($game, 0, 1, 2, 4, 3, 5, 7, 6, 8);
    }

    submitMoveActAs($fixture, Mark::O);
    $opening = submitMoveViewOf($game);

    expect($opening['state'] ?? null)->toBe($state->value, 'the fixture Game is not in a Terminal_State (Req 4.6)')
        ->and($opening['winningMark'] ?? null)->toBe($winningMark?->value, 'the fixture Game does not report the winner it recorded')
        ->and(submitMoveReportedMarks($opening['moves'] ?? null))->not->toBe([], 'the terminal fixture has an empty Move_List, so there is no state for the refusal to arrive with');

    // On the won board cell 5 is FREE, so `game_ended` there cannot be explained by
    // occupancy or by anything except the state. The drawn board has no free Cell at
    // all, so cell 0 is occupied — and the answer must still be `game_ended` rather
    // than `invalid_move`, which is the design's guard order (state before cell
    // validity) seen from the HTTP surface.
    submitMoveRefused($fixture, Mark::O, ['cell_index' => $state === GameState::Won ? 5 : 0], MoveOutcome::GameEnded);
})->with([
    'won' => [GameState::Won, Mark::X],
    'drawn' => [GameState::Drawn, null],
]);

it('refuses a move from the player who is not to move, leaving everything as it was', function () {
    $fixture = submitMoveFixture();
    $game = $fixture['game'];

    submitMoveSeed($game, 0);

    submitMoveActAs($fixture, Mark::X);
    $opening = submitMoveViewOf($game);

    expect($opening['markToMove'] ?? null)->toBe('o', 'O is not the Mark_To_Move, so X posting below would be posting in turn')
        ->and($opening['yourMark'] ?? null)->toBe('x')
        ->and($opening['isYourTurn'] ?? null)->toBeFalse();

    // Cell 4 is free, so nothing about the Cell can explain the refusal.
    submitMoveRefused($fixture, Mark::X, ['cell_index' => 4], MoveOutcome::NotYourTurn);
});

it('refuses an occupied cell and a cell outside the board, leaving everything as it was', function () {
    $fixture = submitMoveFixture();
    $game = $fixture['game'];

    submitMoveSeed($game, 0);

    submitMoveActAs($fixture, Mark::O);
    $opening = submitMoveViewOf($game);
    $board = is_array($opening['board'] ?? null) ? $opening['board'] : [];

    expect($opening['isYourTurn'] ?? null)->toBeTrue('it is not the posting Player\'s turn, so these refusals would be not_your_turn rather than invalid_move')
        ->and($board[0] ?? null)->toBe('x', 'cell 0 is not occupied by the opponent, so the occupancy refusal asserts nothing (Req 4.3)');

    // The opponent's Cell (Req 4.3), then both boundaries either side of the range
    // (Req 4.4) — an off-by-one in the comparison is the mistake worth catching.
    submitMoveRefused($fixture, Mark::O, ['cell_index' => 0], MoveOutcome::InvalidMove);
    submitMoveRefused($fixture, Mark::O, ['cell_index' => 9], MoveOutcome::InvalidMove);
    submitMoveRefused($fixture, Mark::O, ['cell_index' => -1], MoveOutcome::InvalidMove);
});

/*
 * ─────────────────────────────────────────────────────────────────────────────
 * 5. PROPERTY 12 OVER A MIXED SEQUENCE.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * The property is "exactly once per COMMITTED state-changing operation", so the
 * sweeps above establish the increments and the refusals above establish the
 * non-increments — but neither says the two hold in each other's presence, and
 * neither covers a state request at all. This interleaves them on one Game and
 * asserts the Version_Counter after every single request.
 *
 * The GET is in it deliberately: a poll runs at least every two seconds per Player
 * (Req 8.1), so a state request that moved the counter would make the client's
 * change detector useless and would break Property 11's "byte-identical
 * irrespective of any Version_Counter presented with the request".
 */
it('moves the version counter once per accepted move, and not at all for a rejection or a state request', function () {
    $fixture = submitMoveFixture();
    $game = $fixture['game'];

    $version = static fn (): int => submitMoveRowOf($game->id)['version_counter'];

    expect($version())->toBe(1, 'the fixture does not start at the one committed operation an active Game has had');

    submitMoveActAs($fixture, Mark::X);
    submitMoveGet($game)->assertOk();

    expect($version())->toBe(1, 'a state request moved the Version_Counter (Property 12)');

    submitMovePost($fixture, Mark::X, ['cell_index' => 'banana'])->assertStatus(303);

    expect($version())->toBe(1, 'a rejected request moved the Version_Counter (Property 9, Property 12)');

    submitMovePost($fixture, Mark::X, ['cell_index' => 4])->assertSessionMissing('outcome');

    expect($version())->toBe(2, 'an accepted Move did not move the Version_Counter (Req 4.7)');

    submitMovePost($fixture, Mark::X, ['cell_index' => 0])->assertSessionHas('outcome', 'not_your_turn');

    expect($version())->toBe(2, 'a request refused as not_your_turn moved the Version_Counter (Property 9)');

    submitMovePost($fixture, Mark::O, ['cell_index' => 4])->assertSessionHas('outcome', 'invalid_move');

    expect($version())->toBe(2, 'a request refused as invalid_move moved the Version_Counter (Property 9)');

    submitMovePost($fixture, Mark::O, ['cell_index' => 0])->assertSessionMissing('outcome');

    expect($version())->toBe(3, 'the second accepted Move did not move the Version_Counter (Req 4.7)')
        ->and(submitMoveListOf($game->id))->toBe([0 => 4, 1 => 0], 'the two accepted Moves are not the only Moves recorded');

    // Read back through the representation as well, since Requirement 8.3 is about
    // the value the client is given rather than about the column.
    expect(submitMoveViewOf($game)['version'] ?? null)
        ->toBe(3, 'the Version_Counter reported to the client is not the persisted one (Req 8.3)');
});
