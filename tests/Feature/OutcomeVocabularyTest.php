<?php

declare(strict_types=1);

use App\Domain\TicTacToe\Mark;
use App\Games\GameSnapshot;
use App\Games\GameState;
use App\Games\JoinCode;
use App\Games\MintedToken;
use App\Games\MoveAccepted;
use App\Games\MoveOutcome;
use App\Games\PlayerTokens;
use App\Games\SubmitMove;
use App\Models\ExpiryRecord;
use App\Models\Game;
use App\Models\Move;
use Illuminate\Cache\ArrayStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\Response as StatusCodes;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

// Feature: remote-tic-tac-toe, Property 16: Rejection outcomes are pairwise distinct
//
// Validates: Requirements 2.2, 2.3, 3.3, 3.5, 4.3, 4.4, 4.5, 4.6, 5.4, 7.10, 7.11,
// 10.6, 13.6, 13.7, 13.8, 14.3
//
/*
 * The rejection vocabulary as a whole: eleven conditions, one scenario each, and the
 * eleven values they produce asserted pairwise distinct (Req 14.3).
 *
 * Every value below is read off what the application surfaced — a flashed `outcome`,
 * an `outcome` prop, or a status — and never off the enum the application would have
 * derived it from. `expect(MoveOutcome::Conflict->value)->toBe('conflict')` is the
 * edit that turns this file into eleven tautologies: it asserts a case against itself
 * and holds however the rejections behave.
 *
 * The cross-site request forgery rejection is excluded, and Requirement 14.3 mandates
 * the exclusion rather than it being an omission. It could not be exercised anyway:
 * `PreventRequestForgery::handle()` proceeds when `runningUnitTests()` holds, ahead of
 * the origin and token checks
 * (`vendor/laravel/framework/src/Illuminate/Foundation/Http/Middleware/PreventRequestForgery.php:97`),
 * and that is true of every request the suite makes.
 *
 * `rate_limited` is the one condition with no value in the application's vocabulary.
 * No enum in `App\Games` carries it, `resources/js/lib/outcomes.ts` does not list it,
 * and nothing emits it: the refusal is `Illuminate\Routing\Middleware\ThrottleRequests`
 * answering 429 before any controller runs, which is what the design's outcome table
 * records as "framework response". So its scenario observes the status, reports it as
 * `status:429`, and asserts that no outcome value is flashed — the assertion that
 * fails if the application ever gains one and this account goes stale.
 *
 * Where two criteria name one value the scenario exercises both and asserts they
 * agree, because the vocabulary has one value per outcome rather than one per
 * condition: `not_authorised` for a tokenless Move (Req 3.3) and a tokenless Rematch
 * (Req 7.11), `invalid_move` for an occupied Cell (Req 4.3) and a non-integer one
 * (Req 4.4), `not_recognised` for an unmatched Join_Code (Req 2.2) and an unknown
 * Game_Id (Req 13.8), `game_expired` for a state request (Req 13.6) and a move
 * request (Req 13.7).
 *
 * What is not re-asserted here, and where it lives: the rate-limit thresholds and
 * both boundaries are `RateLimitTest`'s (Req 14.4); the visibility table row by row
 * is `GameResolverTest`'s; the conflict mechanism and the Move_List it leaves behind
 * are `SubmitMoveMechanismTest`'s and `ConcurrencyTest`'s; the sweep that writes a
 * real Expiry_Record is `SweepExpiredGamesTest`'s.
 */

uses(RefreshDatabase::class);

/**
 * The value the design's outcome table assigns to `$condition`.
 *
 * Property 16 names each rejection by the value it produces, so for ten of the eleven
 * the condition and the value are the same string — a literal from that table, not a
 * value the application computed. `rate_limited` is the exception described in the
 * file docblock.
 */
function outcomeVocabularyValueFor(string $condition): string
{
    return $condition === 'rate_limited' ? 'status:429' : $condition;
}

/**
 * A saved Game in `$state` whose Move_List is `$cells` recorded contiguously from
 * zero, with both Player_Tokens bound to it and nothing written to any session.
 *
 * The hashes are assigned directly rather than issued through a real create and join:
 * `PlayerTokens::issue()` writes whichever session is current, and no sequence of
 * real requests produces a `won` row without playing a Game first.
 *
 * `version_counter` is one for the join (Req 2.6) plus one per accepted Move
 * (Req 4.7), and `last_activity_at` is backdated, so "unchanged" below compares
 * against a state a real Game would be in rather than against round numbers.
 *
 * @param  list<int>  $cells
 * @return array{game: Game, tokens: array{x: MintedToken, o: MintedToken}}
 */
function outcomeVocabularyGame(GameState $state = GameState::Active, array $cells = []): array
{
    $tokens = new PlayerTokens;
    $x = $tokens->mint();
    $o = $tokens->mint();

    $game = new Game;
    $game->id = Str::uuid7()->toString();
    $game->join_code = JoinCode::generate()->stored;
    $game->state = $state;
    // The CHECK on `games` pairs `state = 'won'` with a non-null `winning_mark` in the
    // same row, and forbids one in every other state.
    $game->winning_mark = $state === GameState::Won ? Mark::X : null;
    $game->x_token_hash = $x->hash;
    // And the one-directional CHECK forbids an occupied O slot while a Game waits for
    // an opponent, which is also what that state means (Req 2.1).
    $game->o_token_hash = $state === GameState::WaitingForOpponent ? null : $o->hash;
    $game->version_counter = $state === GameState::WaitingForOpponent ? 0 : 1 + count($cells);
    $game->last_activity_at = now()->subMinutes(5);
    $game->save();

    foreach ($cells as $position => $cell) {
        $move = new Move;
        $move->game_id = $game->id;
        $move->cell_index = $cell;
        $move->sequence_index = $position;
        $move->save();
    }

    return ['game' => $game, 'tokens' => ['x' => $x, 'o' => $o]];
}

/**
 * Starts a fresh Player_Session holding `$holding`, a Game_Id to `MintedToken` map —
 * empty for a session that is a Player of nothing.
 *
 * Fresh rather than suspended and resumed, as in `SubmitMoveTest`: no claim here is
 * about a credential surviving another request, only about which one a request
 * presents. The flush also discards the previous scenario's flashed `outcome`, so a
 * value read after a request below is that request's own.
 *
 * @param  array<string, MintedToken>  $holding
 */
function outcomeVocabularySession(array $holding = []): void
{
    Session::flush();

    // `Store::isValidId()` accepts 40 alphanumeric characters and silently replaces
    // anything else with a generated id, which would make a failed switch look like a
    // successful one.
    Session::setId(Str::random(40));
    Session::start();

    $tokens = new PlayerTokens;

    foreach ($holding as $gameId => $token) {
        $tokens->remember($gameId, $token);
    }
}

/**
 * The Inertia props a response carries, read from the real payload.
 *
 * @param  TestResponse<Response>  $response
 * @return array<string, mixed>
 */
function outcomeVocabularyProps(TestResponse $response): array
{
    $page = AssertableInertia::fromTestResponse($response)->toArray();

    return is_array($page['props'] ?? null) ? $page['props'] : [];
}

/**
 * The outcome value `$response` surfaced, read from whichever transport the design's
 * table assigns the rejection: the flashed `outcome` for the two redirect families,
 * the `outcome` prop of the `NotAPlayer` page for 403, 404 and 410, and the status
 * for a rejection the application answers with no outcome value at all.
 *
 * `Session::get()` rather than `assertSessionHas()`, because the flashed value is the
 * subject here rather than the assertion. A flashed value is still readable after the
 * request that set it and is aged out by `Store::save()` at the end of the request
 * after that, so each scenario starts a session of its own rather than relying on
 * that timing.
 *
 * The sentinels are phrases rather than empty strings so that a scenario which
 * silently succeeded contributes something legible to the distinctness set instead of
 * a value that might happen to be distinct.
 *
 * @param  TestResponse<Response>  $response
 */
function outcomeVocabularySurfaced(TestResponse $response): string
{
    $status = $response->getStatusCode();

    if ($status === StatusCodes::HTTP_SEE_OTHER) {
        $flashed = Session::get('outcome');

        return is_string($flashed) ? $flashed : 'a 303 carrying no flashed outcome';
    }

    if (in_array($status, [StatusCodes::HTTP_FORBIDDEN, StatusCodes::HTTP_NOT_FOUND, StatusCodes::HTTP_GONE], true)) {
        $outcome = outcomeVocabularyProps($response)['outcome'] ?? null;

        return is_string($outcome) ? $outcome : "a {$status} carrying no outcome prop";
    }

    return 'status:'.$status;
}

/**
 * Everything about one Game a refused request must leave alone: its row, its
 * Move_List, and whether a Rematch points at it.
 *
 * Read from the tables rather than through a model the subject returned, so a stale
 * or hand-assigned in-memory instance cannot make a comparison pass. The Rematch
 * count is here so one comparison serves the Rematch rejection too.
 *
 * @return array{row: array<string, mixed>, moves: list<array{sequence_index: int, cell_index: int}>, rematches: int}
 */
function outcomeVocabularyStateOf(string $gameId): array
{
    $row = DB::table('games')->where('id', $gameId)->first();

    $moves = DB::table('moves')
        ->where('game_id', $gameId)
        ->orderBy('sequence_index')
        ->orderBy('id')
        ->get()
        ->all();

    return [
        'row' => $row === null ? [] : (array) $row,
        'moves' => array_values(array_map(
            static fn (object $move): array => [
                'sequence_index' => (int) $move->sequence_index,
                'cell_index' => (int) $move->cell_index,
            ],
            $moves,
        )),
        'rematches' => DB::table('games')->where('rematch_of_game_id', $gameId)->count(),
    ];
}

/**
 * Runs the scenario for `$condition` against a rate limiter with no history, and
 * returns the outcome value the application surfaced.
 *
 * The flush is not hygiene. `AppServiceProvider::rateLimitSubject()` reads the
 * session cookie the request presented and the test client sends none, so every
 * `POST /join` in this file counts into the one `ip:127.0.0.1` bucket of the `join`
 * limiter — and the rate-limited scenario spends all twenty of it. Without a flush
 * between scenarios, the join rejections would be 429s in whichever order the
 * collector below happened to reach them.
 *
 * The `array` store is pinned and checked rather than assumed: `phpunit.xml` sets
 * `CACHE_STORE=array`, but `SqliteConnectionSettingsTest` clears environment
 * variables mid-run and the `.env` values behind them can take over for every test
 * that follows.
 */
function outcomeVocabularyRun(string $condition): string
{
    config(['cache.default' => 'array', 'cache.limiter' => null]);

    $store = Cache::driver()->getStore();

    expect($store)->toBeInstanceOf(
        ArrayStore::class,
        'the rate limiter is not counting into an in-process array store, so its window is not deterministic and neither is the scenario below',
    );

    $store->flush();

    $scenarios = outcomeVocabularyScenarios();

    expect(array_key_exists($condition, $scenarios))->toBeTrue("there is no scenario for the {$condition} rejection");

    return $scenarios[$condition]();
}

/**
 * One scenario per rejection condition, keyed by the name the design's outcome table
 * gives it. Each builds its own fixture, drives the application, asserts that the
 * rejection happened and that nothing it must not touch moved, and returns the value
 * the application surfaced.
 *
 * Closures in a map rather than eleven `it()` blocks because Property 16 is a claim
 * about the eleven values together: a collector spread over eleven tests would depend
 * on the order the suite ran them in, which `--order-by=random` decides.
 *
 * @return array<string, Closure(): string>
 */
function outcomeVocabularyScenarios(): array
{
    return [
        /*
         * Req 3.3: a Move that would otherwise be accepted — X to move, cell 4 free
         * — from a session holding no Player_Token, so the refusal is about the
         * absent credential and nothing else. Req 7.11 is the same value from the
         * Rematch route, and its Game is terminal so a Player would have got a
         * Rematch rather than a refusal.
         */
        'not_authorised' => function (): string {
            $playable = outcomeVocabularyGame(GameState::Active, [0, 3])['game'];
            $finished = outcomeVocabularyGame(GameState::Won, [0, 3, 1, 4, 2])['game'];

            outcomeVocabularySession();

            $before = outcomeVocabularyStateOf($playable->id);

            $move = post('/games/'.$playable->id.'/moves', ['cell_index' => 4]);

            $move->assertForbidden();

            $observed = outcomeVocabularySurfaced($move);

            expect($before['moves'])->toHaveCount(2, 'the fixture Move_List is not the two Moves this scenario reasons about, so the comparison below spans the wrong state')
                // A `VisibilityOutcome` is fieldless, so the refusal has no Game to
                // hand on (Req 3.10).
                ->and(array_key_exists('game', outcomeVocabularyProps($move)))->toBeFalse('the refusal carries a game prop (Req 3.10)')
                ->and(outcomeVocabularyStateOf($playable->id))->toBe($before, 'the refused Move changed the Game (Req 3.3, Property 9)');

            $rematch = post('/games/'.$finished->id.'/rematch');

            $rematch->assertForbidden();

            expect(outcomeVocabularySurfaced($rematch))->toBe($observed, 'a tokenless Rematch request produced a different value than a tokenless Move request (Req 7.11)')
                ->and(outcomeVocabularyStateOf($finished->id)['rematches'])->toBe(0, 'a tokenless request created a Rematch (Req 7.11)');

            return $observed;
        },

        /*
         * Req 3.5: one Move played, so `O` is the Mark_To_Move by parity (Req 4.1)
         * and the X Player is refused for whose turn it is rather than for anything
         * about cell 4, which is free.
         */
        'not_your_turn' => function (): string {
            $fixture = outcomeVocabularyGame(GameState::Active, [0]);
            $game = $fixture['game'];

            outcomeVocabularySession([$game->id => $fixture['tokens']['x']]);

            $before = outcomeVocabularyStateOf($game->id);

            $refused = post('/games/'.$game->id.'/moves', ['cell_index' => 4]);

            $refused->assertStatus(StatusCodes::HTTP_SEE_OTHER)->assertRedirect(url('/games/'.$game->id));

            expect($before['moves'])->toBe(
                [['sequence_index' => 0, 'cell_index' => 0]],
                'the fixture Move_List is not the single Move that makes O the Mark_To_Move, so the refusal is not the turn guard\'s',
            )
                ->and(outcomeVocabularyStateOf($game->id))->toBe($before, 'the refused Move changed the Game (Req 3.5, Property 9)');

            return outcomeVocabularySurfaced($refused);
        },

        /*
         * Req 4.3 and 4.4: `O` is the Mark_To_Move, so the O Player passes the turn
         * guard and is refused by the Cell — first an occupied one, then a value that
         * is not an integer at all, which arrives uncast because no Form Request
         * validates it and so is one outcome rather than a validation payload.
         */
        'invalid_move' => function (): string {
            $fixture = outcomeVocabularyGame(GameState::Active, [0]);
            $game = $fixture['game'];

            outcomeVocabularySession([$game->id => $fixture['tokens']['o']]);

            $before = outcomeVocabularyStateOf($game->id);

            $occupied = post('/games/'.$game->id.'/moves', ['cell_index' => 0]);

            $occupied->assertStatus(StatusCodes::HTTP_SEE_OTHER)->assertRedirect(url('/games/'.$game->id));

            $observed = outcomeVocabularySurfaced($occupied);

            expect($before['moves'])->toBe(
                [['sequence_index' => 0, 'cell_index' => 0]],
                'cell 0 is not occupied in the fixture Move_List, so the request above named a free Cell',
            )
                ->and(outcomeVocabularyStateOf($game->id))->toBe($before, 'the refused Move changed the Game (Req 4.3, Property 9)');

            $notAnInteger = post('/games/'.$game->id.'/moves', ['cell_index' => 'banana']);

            $notAnInteger->assertStatus(StatusCodes::HTTP_SEE_OTHER)->assertRedirect(url('/games/'.$game->id));

            // The state comparison is what makes the equality meaningful: an accepted
            // second Move would leave the first request's flashed value in the store
            // and read as agreement.
            expect(outcomeVocabularySurfaced($notAnInteger))->toBe($observed, 'a non-integer Cell_Index produced a different value than an occupied Cell (Req 4.4)')
                ->and(outcomeVocabularyStateOf($game->id))->toBe($before, 'the refused Move changed the Game (Req 4.4, Property 9)');

            return $observed;
        },

        /*
         * Req 4.5: `X` is the Mark_To_Move on an empty Move_List (Req 4.1), so the
         * Creator's request would pass the turn guard and it is the Game_State that
         * refuses it.
         */
        'game_not_started' => function (): string {
            $fixture = outcomeVocabularyGame(GameState::WaitingForOpponent);
            $game = $fixture['game'];

            outcomeVocabularySession([$game->id => $fixture['tokens']['x']]);

            $before = outcomeVocabularyStateOf($game->id);

            $refused = post('/games/'.$game->id.'/moves', ['cell_index' => 0]);

            $refused->assertStatus(StatusCodes::HTTP_SEE_OTHER)->assertRedirect(url('/games/'.$game->id));

            expect($before['row']['state'] ?? null)->toBe(GameState::WaitingForOpponent->value, 'the fixture Game is not waiting for an opponent, so the refusal is not this guard\'s')
                ->and($before['moves'])->toBe([], 'the fixture Game already has Moves, so it is not a Game nobody has joined')
                ->and(outcomeVocabularyStateOf($game->id))->toBe($before, 'the refused Move changed the Game (Req 4.5, Property 9)');

            return outcomeVocabularySurfaced($refused);
        },

        /*
         * Req 4.6: X won with the Move at Sequence_Index 4, so `O` is the
         * Mark_To_Move — Req 4.1 is defined in terminal states too — and cell 5 is
         * free. Only the Game_State refuses this request.
         */
        'game_ended' => function (): string {
            $fixture = outcomeVocabularyGame(GameState::Won, [0, 3, 1, 4, 2]);
            $game = $fixture['game'];

            outcomeVocabularySession([$game->id => $fixture['tokens']['o']]);

            $before = outcomeVocabularyStateOf($game->id);

            $refused = post('/games/'.$game->id.'/moves', ['cell_index' => 5]);

            $refused->assertStatus(StatusCodes::HTTP_SEE_OTHER)->assertRedirect(url('/games/'.$game->id));

            expect($before['row']['state'] ?? null)->toBe(GameState::Won->value, 'the fixture Game is not in a Terminal_State, so the refusal is not this guard\'s')
                ->and($before['moves'])->toHaveCount(5, 'the fixture Move_List is not the five Moves that make O the Mark_To_Move')
                ->and(outcomeVocabularyStateOf($game->id))->toBe($before, 'the refused Move changed the Game (Req 4.6, Property 9)');

            return outcomeVocabularySurfaced($refused);
        },

        /*
         * Req 5.4: two calls over one observed `GameSnapshot`. `SubmitMove` is a pure
         * function of its arguments and issues no `SELECT`, so the second call sees
         * what a concurrent second request would — the Move_List without the first
         * Move — and derives the same Sequence_Index. The value comes back from the
         * service rather than from the transport, because a second HTTP request would
         * build its own snapshot and be refused as `not_your_turn` instead.
         */
        'conflict' => function (): string {
            $submit = new SubmitMove;
            $game = outcomeVocabularyGame(GameState::Active, [0, 3])['game'];

            $snapshot = GameSnapshot::of($game);

            $before = outcomeVocabularyStateOf($game->id);

            expect($snapshot->analysis->markToMove)->toBe(Mark::X, 'X is not the Mark_To_Move, so both calls would be refused as not_your_turn and neither would reach the insert')
                ->and($snapshot->analysis->board->isOccupied(4))->toBeFalse('cell 4 is occupied, so the first call would be refused as invalid_move')
                ->and($snapshot->analysis->board->isOccupied(6))->toBeFalse('cell 6 is occupied, so the second call would be refused by the Cell rather than by the Sequence_Index it shares with the first');

            $first = $submit->handle($snapshot, Mark::X, 4);

            $afterFirst = outcomeVocabularyStateOf($game->id);

            $second = $submit->handle($snapshot, Mark::X, 6);

            expect($first)->toBeInstanceOf(MoveAccepted::class, 'the first of two Moves from one snapshot was refused, so the second is not a conflict')
                ->and($afterFirst['moves'])->toHaveCount(count($before['moves']) + 1, 'the first Move was not recorded, so the second call collides with nothing')
                ->and($second)->toBeInstanceOf(MoveOutcome::class, 'the second Move from the same snapshot was accepted (Req 5.3, 5.4)')
                ->and(outcomeVocabularyStateOf($game->id))->toBe($afterFirst, 'the refused Move changed the Game, so its transaction did not roll back (Req 5.4, Property 9)');

            return $second instanceof MoveOutcome
                ? $second->value
                : 'an accepted Move rather than a rejection';
        },

        /*
         * Req 2.3: both slots occupied and this session holding no Player_Token for
         * the Game, so the Join_Code is refused rather than short-circuited back to a
         * Player of it (Req 2.4).
         */
        'game_full' => function (): string {
            $game = outcomeVocabularyGame(GameState::Active)['game'];
            $code = JoinCode::parse((string) $game->join_code)?->display();

            outcomeVocabularySession();

            $before = outcomeVocabularyStateOf($game->id);

            $refused = post('/join', ['join_code' => $code]);

            $refused->assertStatus(StatusCodes::HTTP_SEE_OTHER)->assertRedirect(url('/join'));

            expect($code)->not->toBeNull('the fixture Join_Code is not well formed, so the request above named no Game')
                ->and($before['row']['o_token_hash'] ?? null)->toBeString('the fixture Game has a free O slot, so the join would have been accepted rather than refused')
                ->and(outcomeVocabularyStateOf($game->id))->toBe($before, 'the refused join changed the Game (Req 2.3, Property 9)')
                ->and((new PlayerTokens)->heldFor($game->id))->toBeNull('the refused join left a Player_Token in the session for a Game it did not join');

            return outcomeVocabularySurfaced($refused);
        },

        /*
         * Req 13.8 for a Game_Id with neither a row nor an Expiry_Record, and Req 2.2
         * for a Join_Code matching nothing. `JoinOutcome::NotRecognised` shares
         * `VisibilityOutcome::NotRecognised`'s backing value deliberately — the design
         * lists `not_recognised` once — so the two are an equality here rather than
         * two of the eleven.
         */
        'not_recognised' => function (): string {
            $unknownId = Str::uuid7()->toString();

            outcomeVocabularySession();

            $games = DB::table('games')->count();

            expect(DB::table('games')->where('id', $unknownId)->exists())->toBeFalse('the id this scenario calls unknown has a Game row')
                ->and(DB::table('expiry_records')->where('game_id', $unknownId)->exists())->toBeFalse('the id has an Expiry_Record, which is the game_expired row of the visibility table rather than this one')
                ->and(DB::table('games')->where('join_code', 'ZZZZZZZZZZ')->exists())->toBeFalse('a fixture holds the Join_Code this scenario submits as unmatched');

            $byId = get('/games/'.$unknownId);

            $byId->assertNotFound();

            $observed = outcomeVocabularySurfaced($byId);

            $byCode = post('/join', ['join_code' => 'ZZZZZ-ZZZZZ']);

            $byCode->assertStatus(StatusCodes::HTTP_SEE_OTHER)->assertRedirect(url('/join'));

            expect(outcomeVocabularySurfaced($byCode))->toBe($observed, 'an unmatched Join_Code produced a different value than an unknown Game_Id (Req 2.2, 13.8)')
                ->and(DB::table('games')->count())->toBe($games, 'a rejected request created a Game');

            return $observed;
        },

        /*
         * Req 13.6 and 13.7: no `games` row, an Expiry_Record, and a session holding a
         * Player_Token for the id. The tombstone is written directly because
         * `game_expired` is the one outcome that cannot be produced while the Game
         * exists, and the row a sweep would have deleted is not what this scenario
         * observes.
         */
        'game_expired' => function (): string {
            $sweptId = Str::uuid7()->toString();

            $record = new ExpiryRecord;
            $record->game_id = $sweptId;
            $record->deleted_at = now();
            $record->save();

            $tokens = new PlayerTokens;

            outcomeVocabularySession([$sweptId => $tokens->mint()]);

            expect(DB::table('games')->where('id', $sweptId)->exists())->toBeFalse('a Game row exists for the swept id, so the request below would resolve rather than be refused')
                ->and($tokens->heldFor($sweptId))->not->toBeNull('the session holds no Player_Token for the swept id, which is the not_recognised row of the visibility table rather than this one (Req 13.8)');

            $state = get('/games/'.$sweptId);

            $state->assertStatus(StatusCodes::HTTP_GONE);

            $observed = outcomeVocabularySurfaced($state);

            $move = post('/games/'.$sweptId.'/moves', ['cell_index' => 0]);

            $move->assertStatus(StatusCodes::HTTP_GONE);

            expect(outcomeVocabularySurfaced($move))->toBe($observed, 'a move request for an expired Game_Id produced a different value than a state request (Req 13.7)')
                ->and(DB::table('games')->where('id', $sweptId)->exists())->toBeFalse('the refused request created a Game (Req 13.7)')
                ->and(DB::table('moves')->where('game_id', $sweptId)->exists())->toBeFalse('the refused request created a Move (Req 13.7)');

            return $observed;
        },

        /*
         * Req 7.10: a Game still in play, requested by a Player of it, so the Rematch
         * is refused for the Game_State rather than for the credential (Req 7.11).
         */
        'invalid_state' => function (): string {
            $fixture = outcomeVocabularyGame(GameState::Active, [0, 3]);
            $game = $fixture['game'];

            outcomeVocabularySession([$game->id => $fixture['tokens']['x']]);

            $before = outcomeVocabularyStateOf($game->id);

            $refused = post('/games/'.$game->id.'/rematch');

            $refused->assertStatus(StatusCodes::HTTP_SEE_OTHER)->assertRedirect(url('/games/'.$game->id));

            expect($before['row']['state'] ?? null)->toBe(GameState::Active->value, 'the fixture Game is in a Terminal_State, so a Rematch would have been created rather than refused')
                ->and($before['rematches'])->toBe(0, 'a Rematch already points at the fixture Game, so the request would have been answered with it (Req 7.9)')
                ->and(outcomeVocabularyStateOf($game->id))->toBe($before, 'the refused Rematch request changed the Game or created a Rematch (Req 7.10, Property 9)');

            return outcomeVocabularySurfaced($refused);
        },

        /*
         * Req 10.6: the window is filled with a Join_Code matching nothing, so those
         * twenty requests touch no Game, and the refused twenty-first carries the code
         * of a Game waiting for an opponent — had it reached the controller, that row
         * would now be `active` with an occupied O slot and a Version_Counter of 1.
         * The threshold and both boundaries are `RateLimitTest`'s; what is taken here
         * is the value.
         */
        'rate_limited' => function (): string {
            $threshold = 20;

            $game = outcomeVocabularyGame(GameState::WaitingForOpponent)['game'];
            $code = JoinCode::parse((string) $game->join_code)?->display();

            outcomeVocabularySession();

            for ($request = 1; $request <= $threshold; $request++) {
                expect(post('/join', ['join_code' => 'ZZZZZ-ZZZZZ'])->getStatusCode())->toBe(
                    StatusCodes::HTTP_SEE_OTHER,
                    "join request {$request} of {$threshold} was refused before the window was full, so the refusal below would not be the limiter's (Req 10.6)",
                );
            }

            // Each of those twenty flashed its own outcome, and flash data survives in
            // the store across requests within one test, so the key is cleared here:
            // what the refusal surfaces has to be the refusal's own.
            $before = outcomeVocabularyStateOf($game->id);

            $refused = post('/join', ['join_code' => $code]);

            expect($refused->getStatusCode())->toBe(StatusCodes::HTTP_TOO_MANY_REQUESTS, 'the twenty-first join request from one Rate_Limit_Subject inside one 60-second window was not rate limited (Req 10.6)')
                ->and($before['row']['o_token_hash'] ?? null)->toBeNull('the fixture Game already has two Players, so it would have refused this request whatever the limiter did')
                ->and(outcomeVocabularyStateOf($game->id))->toBe($before, 'the rate-limited request reached the controller and joined the Game it named (Req 10.6, Property 9)')
                ->and((new PlayerTokens)->heldFor($game->id))->toBeNull('the rate-limited request left a Player_Token in the session for a Game it never joined')
                // The finding this scenario records: nothing in the application emits
                // an outcome value for a rate-limited request. `Store::save()` ages
                // flash data out on the request after the one that set it, so a value
                // here would have to be this refusal's own.
                ->and(Session::get('outcome'))->toBeNull('the rate-limited rejection now surfaces an outcome value, so the status is no longer what this scenario should observe');

            return outcomeVocabularySurfaced($refused);
        },
    ];
}

/*
 * Each condition on its own, against the value the design's outcome table assigns it.
 * A dataset rather than a loop, so one disagreement names the condition that produced
 * it and the other ten still run.
 */
it('surfaces the value the outcome table assigns to the rejection', function (string $condition) {
    expect(outcomeVocabularyRun($condition))->toBe(
        outcomeVocabularyValueFor($condition),
        "the {$condition} rejection surfaced a different value",
    );
})->with([
    'not_authorised',
    'not_your_turn',
    'invalid_move',
    'game_not_started',
    'game_ended',
    'conflict',
    'game_full',
    'not_recognised',
    'game_expired',
    'invalid_state',
    'rate_limited',
]);

/*
 * Property 16 itself. Distinctness is asserted over what the eleven scenarios
 * produced, not over the table above, so two outcomes collapsing onto one value fails
 * here even if the expectations were edited to agree with them.
 */
it('gives the eleven rejection conditions eleven pairwise distinct outcome values', function () {
    $observed = [];

    foreach (array_keys(outcomeVocabularyScenarios()) as $condition) {
        $observed[$condition] = outcomeVocabularyRun($condition);
    }

    $duplicates = [];

    foreach ($observed as $value) {
        $sharing = array_map(strval(...), array_keys($observed, $value, true));

        if (count($sharing) > 1) {
            $duplicates[$value] = $value.' is produced by '.implode(' and ', $sharing);
        }
    }

    expect($observed)->toHaveCount(11, 'there are not eleven rejection conditions here, so distinctness is being asserted over the wrong set (Req 14.3)')
        ->and(count(array_unique($observed)))->toBe(
            11,
            'the eleven rejection conditions do not produce eleven distinct outcome values (Property 16): '.implode('; ', $duplicates),
        );
});
