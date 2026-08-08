<?php

declare(strict_types=1);

use App\Domain\TicTacToe\Mark;
use App\Games\GameState;
use App\Games\JoinCode;
use App\Games\MintedToken;
use App\Games\PlayerTokens;
use App\Models\Game;
use App\Models\Move;
use Illuminate\Cache\ArrayStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response as StatusCodes;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

// Feature: remote-tic-tac-toe, Property 20: Conforming polling is never rate limited
//
// Validates: Requirements 10.6, 10.7, 10.8, 14.4
//
/*
 * Rate-limit behaviour: the thresholds, the two boundaries, the window, and the state
 * limiter's headroom over the polling rate Req 8.1 demands.
 *
 * Excluded, and where that ground lives instead: the `TrustProxies` configuration and
 * the derivation of the Rate_Limit_Subject from it are
 * `MiddlewareConfigurationTest`'s — the forwarded header below is used only as a means
 * of being a different subject. Property 9 over the other seven rejection outcomes
 * belongs to the files that produce them; this file covers its `rate_limited` row.
 *
 * Where the boundary comes from. `ThrottleRequests::handleRequest()` tests
 * `tooManyAttempts()` for every limit before it hits any counter — refusal loop at
 * `Illuminate/Routing/Middleware/ThrottleRequests.php:157`, `hit()` loop at 164 — and
 * `RateLimiter::tooManyAttempts()` compares `attempts >= $maxAttempts`
 * (`Illuminate/Cache/RateLimiter.php:130`). So on a fresh bucket the twentieth join
 * request sees nineteen attempts and proceeds and the twenty-first sees twenty and is
 * refused, which is Req 10.6's "exceeds 20" and both halves of Req 14.4.
 * `Limit::perMinute(20)` is a 60-second decay.
 *
 * The `array` store is what makes the window deterministic, and it is checked rather
 * than assumed: `phpunit.xml` sets `CACHE_STORE=array`, but
 * `SqliteConnectionSettingsTest` clears environment variables mid-run and the `.env`
 * values behind them can take over for every test that follows. `ArrayStore` is also
 * in-process, fresh per test, and expires against `Carbon::now()`
 * (`Illuminate/Cache/ArrayStore.php:96`), which is the only reason the travelled clock
 * at the end of the join test can move the window.
 *
 * Why every request below shares one bucket. `AppServiceProvider::rateLimitSubject()`
 * reads the session cookie the request presented, not `session()->getId()`, and the
 * test client carries no cookie from one response into the next request, so every
 * request falls to the `ip:` branch and lands in `ip:127.0.0.1` — the production shape
 * for a cookieless caller. Had the subject been `session()->getId()`, each request
 * would have been given a fresh id and a bucket of its own, the loops would all have
 * passed, and no boundary would ever have triggered. The `X-RateLimit-Remaining`
 * assertions in each loop and the second-subject requests are what rule that out.
 */

uses(RefreshDatabase::class);

/**
 * The polling rate Req 8.1 demands, per 60-second window: `useGamePolling` implements
 * its "no more than 2 seconds" as 2000 ms, so a window holds 60000 / 2000 = 30
 * conforming state requests. The division is written out so that changing the interval
 * in `useGamePolling` and not here shows up as a disagreement between two numbers.
 *
 * Req 8.5's terminal-state interval of 5000 ms is 12 per window and strictly slower,
 * so a limiter that clears 30 clears it too.
 */
function rateLimitConformingPollsPerWindow(): int
{
    $windowMilliseconds = 60 * 1000;
    $pollingIntervalMilliseconds = 2000;

    return intdiv($windowMilliseconds, $pollingIntervalMilliseconds);
}

/**
 * A saved Game waiting for an opponent, with the X slot occupied and nothing in any
 * session — what `CreateGame` leaves behind as seen from a second browser.
 *
 * The token is minted and assigned directly rather than through
 * `PlayerTokens::issue()`, which writes whichever session is current and would hand
 * the caller a credential these fixtures mean it not to have.
 *
 * @return array{Game, MintedToken}
 */
function rateLimitWaitingGame(string $storedCode): array
{
    $token = (new PlayerTokens)->mint();

    $game = new Game;
    $game->id = Str::uuid7()->toString();
    $game->join_code = $storedCode;
    $game->state = GameState::WaitingForOpponent;
    $game->version_counter = 0;
    $game->x_token_hash = $token->hash;
    $game->last_activity_at = now()->subMinutes(5);
    $game->save();

    return [$game, $token];
}

/**
 * A saved `active` Game with both Player_Tokens bound to it, `$cells` recorded
 * contiguously from zero, and nothing in any session.
 *
 * `version_counter` is `1 + count($cells)`: one for the join (Req 2.6) and one per
 * accepted Move (Req 4.7), so "unchanged" below compares against a plausible state
 * rather than a round number. `last_activity_at` is backdated so an accepted Move
 * visibly moves it and a refused one visibly does not.
 *
 * @param  list<int>  $cells
 * @return array{game: Game, tokens: array{x: MintedToken, o: MintedToken}}
 */
function rateLimitActiveGame(array $cells = []): array
{
    $tokens = new PlayerTokens;
    $x = $tokens->mint();
    $o = $tokens->mint();

    $game = new Game;
    $game->id = Str::uuid7()->toString();
    $game->join_code = JoinCode::generate()->stored;
    $game->state = GameState::Active;
    $game->version_counter = 1 + count($cells);
    $game->x_token_hash = $x->hash;
    $game->o_token_hash = $o->hash;
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
 * Every `games` row and every `moves` row, all columns, read straight from the tables
 * in a fixed order.
 *
 * Scoped to the whole world rather than to the Game the refused request named, so a
 * 429 that ran the controller shows up here whichever row it touched (Property 9).
 * `rateLimitActiveGame()` seeds real Move rows so the comparison is not vacuously
 * over an empty table.
 *
 * @return array{games: list<array<string, mixed>>, moves: list<array<string, mixed>>}
 */
function rateLimitWorldSnapshot(): array
{
    $games = DB::table('games')
        ->orderBy('id')
        ->get()
        ->map(static fn (object $row): array => (array) $row)
        ->all();

    $moves = DB::table('moves')
        ->orderBy('game_id')
        ->orderBy('sequence_index')
        ->get()
        ->map(static fn (object $row): array => (array) $row)
        ->all();

    return ['games' => array_values($games), 'moves' => array_values($moves)];
}

/**
 * The `X-RateLimit-Remaining` value `$response` reports, as an integer, or null where
 * the header is absent.
 *
 * Null is returned rather than a default because absence is a real answer: a limiter
 * returning `Limit::none()` short-circuits before `addHeaders()` is reached
 * (`ThrottleRequests.php:125`), so a missing header is the shape a silently-unlimited
 * route has and must not read as "plenty remaining".
 *
 * @param  TestResponse<Response>  $response
 */
function rateLimitRemaining(TestResponse $response): ?int
{
    $value = $response->headers->get('X-RateLimit-Remaining');

    return $value === null ? null : (int) $value;
}

/**
 * Asserts `$response` was not rate limited, and says which request it was.
 *
 * `not 429` rather than a specific success status, because a conforming request's own
 * outcome is another file's subject. The second expectation is what stops a 500 from
 * reading as a passing "not rate limited".
 *
 * @param  TestResponse<Response>  $response
 */
function rateLimitAssertPassed(TestResponse $response, string $described): void
{
    expect($response->getStatusCode())->not->toBe(
        StatusCodes::HTTP_TOO_MANY_REQUESTS,
        "{$described} was rate limited",
    );

    expect($response->getStatusCode())->toBeLessThan(
        500,
        "{$described} was not rate limited but failed with status {$response->getStatusCode()}, so it says nothing about the limiter",
    );
}

/*
 * The `array` store, pinned and checked — the precondition every assertion here rests
 * on. See the header note for why it cannot be assumed from `phpunit.xml`.
 */
beforeEach(function () {
    config(['cache.default' => 'array', 'cache.limiter' => null]);

    expect(Cache::driver()->getStore())->toBeInstanceOf(
        ArrayStore::class,
        'the rate limiter is not counting into an in-process array store, so the 60-second window is not deterministic and neither is anything below',
    );
});

/*
 * The join boundary (Req 10.6, 14.4, and Property 9's `rate_limited` row). Both
 * halves are asserted, so a limiter set to 19 fails on request twenty and one set to
 * 21 fails on request twenty-one.
 *
 * The twenty-first request names a different Game on purpose. Requests one to twenty
 * carry Game A's Join_Code — the first claims its O slot, the rest short-circuit on
 * the token it stored, which is what hammering the join button produces. Request
 * twenty-one carries Game B's code, a Game still waiting for an opponent, so it is a
 * join that would have succeeded: had the controller run, B would be `active` with an
 * occupied O slot and a Version_Counter of 1. The same request from another subject
 * succeeding afterwards is what turns "would have succeeded" into an observation.
 */
it('does not rate limit the twentieth join request from one subject and rejects the twenty-first', function () {
    $threshold = 20;

    [$a] = rateLimitWaitingGame('4K7P29QZR3');
    [$b] = rateLimitWaitingGame('5M8Q3AR0S4');
    // A third Game, mid-play and untouched below, so the no-state-change comparison
    // spans a non-empty Move_List rather than rows with no Moves to lose.
    rateLimitActiveGame([0, 4, 1]);

    $remaining = [];

    for ($request = 1; $request <= $threshold; $request++) {
        $response = post('/join', ['join_code' => $a->join_code]);

        rateLimitAssertPassed($response, "join request {$request} of {$threshold}");

        $remaining[$request] = rateLimitRemaining($response);
    }

    // Non-vacuity: rules out twenty requests in twenty buckets, which would all report
    // 19 remaining. A fall of exactly one per request, 19 to 0, is only possible if all
    // twenty counted against one key.
    expect($remaining)->toBe(
        array_combine(range(1, $threshold), range($threshold - 1, 0)),
        'the twenty join requests did not count against one shared bucket: X-RateLimit-Remaining did not fall by exactly one per request from 19 to 0, so the boundary below would never have been reached (Req 10.6)',
    );

    $snapshot = rateLimitWorldSnapshot();

    $refused = post('/join', ['join_code' => $b->join_code]);

    expect($refused->getStatusCode())->toBe(
        StatusCodes::HTTP_TOO_MANY_REQUESTS,
        'the twenty-first join request from one Rate_Limit_Subject inside one 60-second window was not rejected with the rate-limited outcome (Req 10.6, 14.4)',
    );

    // The threshold and the window, read off the refusal. `X-RateLimit-Limit` is the
    // `maxAttempts` the middleware counted against, and `Retry-After` is
    // `availableIn()` on the decay, so a value inside 1..60 is what says the window is
    // 60 seconds rather than some other length (Req 10.6).
    $retryAfter = (int) $refused->headers->get('Retry-After');

    expect($refused->headers->get('X-RateLimit-Limit'))->toBe(
        (string) $threshold,
        'the join limiter is not set to twenty per window (Req 10.6)',
    )
        ->and(rateLimitRemaining($refused))->toBe(0, 'the refusal does not report an exhausted bucket')
        ->and($retryAfter)->toBeGreaterThan(0, 'the refusal carries no Retry-After, so it states no window')
        ->and($retryAfter)->toBeLessThanOrEqual(60, 'the join limiter decays over something longer than the 60-second window Requirement 10.6 states');

    // Every row of both tables, not the status alone: this is what separates a
    // middleware short-circuit from a controller that ran and then reported a refusal
    // (Property 9).
    expect(rateLimitWorldSnapshot())->toBe(
        $snapshot,
        'the rate-limited join request changed persisted Game state (Property 9)',
    );

    $bRow = DB::table('games')->where('id', $b->id)->first();

    expect($bRow)->not->toBeNull()
        ->and((array) $bRow)->toMatchArray([
            'state' => GameState::WaitingForOpponent->value,
            'o_token_hash' => null,
            'version_counter' => 0,
        ], 'the Game the rate-limited request named was joined anyway (Req 10.6, Property 9)')
        ->and((new PlayerTokens)->heldFor($b->id))->toBeNull(
            'the rate-limited request left a Player_Token in the session for a Game it never joined',
        );

    // Non-vacuity: rules out the refusal being anything but the limiter's doing. The
    // identical request from a different Rate_Limit_Subject — a forwarded client
    // address, which `MiddlewareConfigurationTest` establishes is honoured — joins
    // Game B.
    $elsewhere = post('/join', ['join_code' => $b->join_code], ['X-Forwarded-For' => '203.0.113.7']);

    rateLimitAssertPassed($elsewhere, 'a join request from a second Rate_Limit_Subject');

    $elsewhere->assertStatus(StatusCodes::HTTP_SEE_OTHER)
        ->assertRedirect(route('games.show', ['game' => $b->id]));

    expect(rateLimitRemaining($elsewhere))->toBe(
        $threshold - 1,
        'the second subject did not start on a bucket of its own, so Requirement 10.6 is a single global limit rather than a per-subject one',
    );

    // Req 10.6 says the rejection persists for the remainder of the window; one
    // refusal alone could be a single unlucky request.
    expect(post('/join', ['join_code' => $a->join_code])->getStatusCode())->toBe(
        StatusCodes::HTTP_TOO_MANY_REQUESTS,
        'the exhausted subject was served again inside the same window (Req 10.6)',
    );

    // The window is 60 seconds rather than permanent. `ArrayStore` expires against
    // `Carbon::now()`, so travelling past the decay is what distinguishes a window from
    // a lockout. `TestCase::tearDown()` clears the test clock, and the `finally` clears
    // it too so a failure below cannot leak it into the next test.
    Carbon::setTestNow(now()->addSeconds(61));

    try {
        rateLimitAssertPassed(
            post('/join', ['join_code' => $a->join_code]),
            'a join request from the exhausted subject one second after the window closed',
        );
    } finally {
        Carbon::setTestNow();
    }
});

/*
 * Property 20's state half: conforming polling is never rate limited (Req 10.8).
 *
 * A full window at the full rate rather than a sample — thirty requests, since a
 * handful would assert nothing about a threshold of 120. Each is an authorised
 * `GET /games/{game}` from a session holding the X Player_Token, the route
 * `useGamePolling` reloads, and each is asserted `200` so a `403` from a broken fixture
 * cannot pass as "not rate limited". `GET` is also why one session can issue thirty
 * without the Game changing under it.
 *
 * Req 10.8 permits either no limit or a threshold exceeding the Req 8 rate, and the
 * design chose the latter at four times the rate. So the threshold is read off the
 * response as well as the outcome, which also excludes a `Limit::none()` route that
 * would satisfy the criterion while silently retiring this test.
 */
it('never rate limits state requests issued for a full window at the rate Requirement 8 demands', function () {
    $polls = rateLimitConformingPollsPerWindow();
    $threshold = 120;

    expect($polls)->toBe(30, 'the derivation of the conforming polling rate no longer yields 30 requests per 60-second window (Req 8.1)');

    $fixture = rateLimitActiveGame([4]);
    $game = $fixture['game'];

    (new PlayerTokens)->remember($game->id, $fixture['tokens']['x']);

    $remaining = [];

    for ($poll = 1; $poll <= $polls; $poll++) {
        $response = get('/games/'.$game->id);

        rateLimitAssertPassed($response, "state request {$poll} of {$polls} in one 60-second window");

        $response->assertOk();

        $remaining[$poll] = rateLimitRemaining($response);
    }

    // Non-vacuity: rules out thirty requests in thirty buckets, of which "none was rate
    // limited" is trivially true. The header falling by one per request, 119 to 90, is
    // what says the whole window counted against one subject and still cleared the
    // threshold with three quarters unspent.
    expect($remaining)->toBe(
        array_combine(range(1, $polls), range($threshold - 1, $threshold - $polls)),
        'a full window of conforming polling did not count against one shared bucket, so Property 20 would hold vacuously (Req 10.8)',
    )
        ->and($remaining[$polls])->toBe(
            $threshold - $polls,
            'a full window of conforming polling left the wrong allowance, so the state threshold is not the 120 the design records (Req 10.8)',
        );

    $last = get('/games/'.$game->id);

    rateLimitAssertPassed($last, 'a state request immediately after a full window of conforming polling');

    expect($last->headers->get('X-RateLimit-Limit'))->toBe(
        (string) $threshold,
        'the state limiter reports no threshold of its own; a route with no limit would satisfy Requirement 10.8 but would leave this test asserting nothing',
    )
        ->and($threshold)->toBeGreaterThan(
            $polls,
            'the state threshold does not exceed the request rate Requirement 8 requires (Req 10.8)',
        );

    // Req 10.8 requires the state limit to be separate from the move limit. Thirty state
    // requests have been spent, so a move request on the same Game in the same session
    // must find its own sixty untouched; a shared bucket shows up as a limit of 120 here
    // or as an allowance short of 59.
    $move = post('/games/'.$game->id.'/moves', ['cell_index' => 0]);

    rateLimitAssertPassed($move, 'a move request after a full window of conforming polling');

    expect($move->headers->get('X-RateLimit-Limit'))->toBe(
        '60',
        'the move route reports the state limiter\'s threshold, so the two limits are not separate (Req 10.8)',
    )
        ->and(rateLimitRemaining($move))->toBe(
            59,
            'polling spent part of the move allowance, so the state limit and the move limit are one bucket (Req 10.8)',
        );
});

/*
 * Property 20's move half: the per-Player_Token boundary (Req 10.7). Not an analogy to
 * the join boundary — `join` counts per Rate_Limit_Subject while `move` is the one
 * limiter keyed on the hash of the Player_Token the request presents, so a `move`
 * limiter that quietly fell back to the subject would pass the join test.
 *
 * Only the first of the sixty is accepted. The fixture is mid-play with `X` to move, so
 * request one is a real Move and the rest are `not_your_turn`; Req 10.7 counts requests
 * presenting a Player_Token irrespective of outcome, and a Game holds nine Moves at
 * most. Mixing the shapes is what shows the counter is on the request rather than on
 * the write — an implementation counting only accepted Moves would never refuse this
 * loop.
 */
it('does not rate limit the sixtieth move request presenting one player token and rejects the sixty-first', function () {
    $threshold = 60;

    $fixture = rateLimitActiveGame([0, 4]);
    $game = $fixture['game'];
    $tokens = new PlayerTokens;

    $tokens->remember($game->id, $fixture['tokens']['x']);

    $remaining = [];

    for ($request = 1; $request <= $threshold; $request++) {
        $response = post('/games/'.$game->id.'/moves', ['cell_index' => 1]);

        rateLimitAssertPassed($response, "move request {$request} of {$threshold} presenting one Player_Token");

        $remaining[$request] = rateLimitRemaining($response);
    }

    expect($remaining)->toBe(
        array_combine(range(1, $threshold), range($threshold - 1, 0)),
        'the sixty move requests did not count against one shared bucket keyed on the presented Player_Token, so the boundary below would never have been reached (Req 10.7)',
    );

    $snapshot = rateLimitWorldSnapshot();

    expect($snapshot['moves'])->toHaveCount(
        3,
        'the accepted Move was not recorded, so the no-state-change comparison below spans the wrong Move_List',
    );

    $refused = post('/games/'.$game->id.'/moves', ['cell_index' => 2]);

    expect($refused->getStatusCode())->toBe(
        StatusCodes::HTTP_TOO_MANY_REQUESTS,
        'the sixty-first move request presenting one Player_Token inside one 60-second window was not rejected with the rate-limited outcome (Req 10.7)',
    )
        ->and($refused->headers->get('X-RateLimit-Limit'))->toBe(
            (string) $threshold,
            'the move limiter is not set to sixty per window (Req 10.7)',
        )
        ->and((int) $refused->headers->get('Retry-After'))->toBeGreaterThan(0)
        ->and((int) $refused->headers->get('Retry-After'))->toBeLessThanOrEqual(60, 'the move limiter decays over something longer than the 60-second window Requirement 10.7 states');

    // Over a Move_List that genuinely holds three Moves and a Cell — 2 — that was free
    // and would have been taken had the controller run (Property 9).
    expect(rateLimitWorldSnapshot())->toBe(
        $snapshot,
        'the rate-limited move request changed persisted Game state (Property 9)',
    );

    // The bucket is the presented Player_Token's, which is the whole of Req 10.7. Same
    // route, same Game, same Rate_Limit_Subject, same client address — everything a
    // coarser key could be built from is unchanged — and the O Player is refused
    // nothing.
    Session::flush();
    // `Store::isValidId()` accepts 40 alphanumeric characters and silently replaces
    // anything else with a generated id, which would make a failed switch look like a
    // successful one.
    Session::setId(Str::random(40));
    Session::start();

    $tokens->remember($game->id, $fixture['tokens']['o']);

    $opponent = post('/games/'.$game->id.'/moves', ['cell_index' => 2]);

    rateLimitAssertPassed($opponent, 'a move request presenting the opponent\'s Player_Token');

    expect(rateLimitRemaining($opponent))->toBe(
        $threshold - 1,
        'the second Player_Token did not start on a bucket of its own, so the move limit is not counted per presented Player_Token (Req 10.7)',
    );

    // Non-vacuity: rules out the second-bucket request being something the route merely
    // answered rather than a Move it accepted.
    expect($opponent->getStatusCode())->toBe(StatusCodes::HTTP_SEE_OTHER)
        ->and(DB::table('moves')->where('game_id', $game->id)->count())->toBe(4)
        ->and($tokens->resolve(Game::query()->findOrFail($game->id), (string) $tokens->heldFor($game->id)))->toBe(Mark::O);
});
