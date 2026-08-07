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
 * Task 9.6 — rate-limit BEHAVIOUR: the thresholds, the two boundaries, the window,
 * and the state limiter's headroom over the polling rate Requirement 8.1 demands.
 *
 * WHAT IS DELIBERATELY NOT HERE. Task 9.5's `MiddlewareConfigurationTest` asserts
 * the `TrustProxies` configuration and the DERIVATION of the Rate_Limit_Subject
 * from it, and never counts a request; this file counts requests and never
 * re-asserts a derivation. The one place the two touch is the forwarded header
 * below, which is used as a means of being a *different subject* rather than as a
 * claim about `TrustProxies` — that claim is 9.5's, and this file simply consumes
 * it. Property 19's no-state-change claim over the other eight rejection outcomes
 * belongs to the files that produce them; what is asserted here is the
 * `rate_limited` row of it, which no other file can reach.
 *
 * WHERE THE BOUNDARY COMES FROM, READ OUT OF THE FRAMEWORK RATHER THAN GUESSED.
 * `ThrottleRequests::handleRequest()` tests `tooManyAttempts()` for every limit
 * BEFORE it hits any counter — the refusal loop is at line 157 of
 * `Illuminate/Routing/Middleware/ThrottleRequests.php` and the `hit()` loop at line
 * 164 — and `RateLimiter::tooManyAttempts()` compares `attempts >= $maxAttempts`
 * (line 130 of `Illuminate/Cache/RateLimiter.php`). So on a fresh bucket the
 * twentieth join request sees nineteen recorded attempts and proceeds, and the
 * twenty-first sees twenty and is refused. That is Requirement 10.6's "exceeds 20"
 * exactly: twenty are permitted, the twenty-first is not, and Requirement 14.4
 * names both halves. `Limit::perMinute(20)` is a 60-second decay, which is the
 * criterion's "any 60-second window".
 *
 * THE `array` CACHE STORE IS WHAT MAKES THE WINDOW DETERMINISTIC, AND IT IS
 * ASSERTED RATHER THAN ASSUMED. `phpunit.xml` sets `CACHE_STORE=array`, but
 * `SqliteConnectionSettingsTest` clears environment variables mid-run and the
 * `.env` values behind them can take over for every test that follows, so an
 * order-dependent assumption about the store would be an order-dependent test.
 * `beforeEach` therefore pins `cache.default` and checks the store the
 * `RateLimiter` singleton will resolve is genuinely an `ArrayStore`: in-process,
 * fresh per test, and expiring against `Carbon::now()` (line 96 of
 * `Illuminate/Cache/ArrayStore.php`) — which is the one reason the travelled clock
 * at the end of the join test can move the window at all.
 *
 * WHY EVERY REQUEST BELOW SHARES ONE BUCKET, WHICH IS THE HAZARD THIS FILE HAD TO
 * RULE OUT FIRST. `AppServiceProvider::rateLimitSubject()` reads the session cookie
 * the request PRESENTED, not `session()->getId()`, and the framework's test client
 * carries no cookie from one response into the next request. So every request here
 * falls to the `ip:` branch and lands in `ip:127.0.0.1` — one bucket, which is
 * exactly the production shape for a cookieless caller. Had the subject been
 * `session()->getId()` instead, each request would have been given a freshly
 * generated id and therefore a bucket of its own, a loop of twenty would have
 * passed, and the twenty-first would have passed too: the boundary would never have
 * triggered and this file would have looked like it worked. That is not left to
 * reasoning. It is pinned three ways — the `X-RateLimit-Remaining` header is
 * asserted to fall by exactly one per request across each loop, so twenty
 * responses that shared nothing could not produce it; a request from a DIFFERENT
 * subject is asserted to still succeed once the shared bucket is exhausted; and
 * the `move` loop's proof is the stronger form again, since a second Player_Token
 * on the same route and the same Game is refused nothing.
 */

uses(RefreshDatabase::class);

/**
 * The polling rate Requirement 8.1 demands, per 60-second window, derived here
 * rather than written down as a number.
 *
 * Requirement 8.1: while a Game is not in a Terminal_State, the Web_Client requests
 * its state "at intervals of no more than 2 seconds", which `useGamePolling`
 * implements as 2000 ms. One 60-second window therefore contains 60000 / 2000 = 30
 * conforming state requests, and that is the figure Requirement 10.8 requires the
 * `state` threshold to exceed. Requirement 8.5's terminal-state interval of 5000 ms
 * is 12 per window and is strictly slower, so a limiter that clears 30 clears it
 * too.
 *
 * The division is written out so that changing the polling interval in
 * `useGamePolling` and not here is visible as a disagreement between two numbers
 * rather than hidden inside one.
 */
function rateLimitConformingPollsPerWindow(): int
{
    $windowMilliseconds = 60 * 1000;
    $pollingIntervalMilliseconds = 2000;

    return intdiv($windowMilliseconds, $pollingIntervalMilliseconds);
}

/**
 * A saved Game waiting for an opponent, with the X slot occupied and NOTHING in any
 * session — the state `CreateGame` leaves behind as seen from a second browser.
 *
 * The token is minted and assigned directly rather than issued through
 * `PlayerTokens::issue()`, because `issue()` writes whichever session is current and
 * these fixtures must not hand the caller a credential they did not ask for.
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
 * A saved `active` Game with both Player_Tokens bound to it and `$cells` recorded
 * contiguously from zero, and NOTHING in any session.
 *
 * `version_counter` is `1 + count($cells)` — one for the join (Req 2.6) and one per
 * accepted Move (Req 4.7) — so the row carries the value a real Game would carry
 * and "unchanged" below is a comparison against a plausible state rather than
 * against a round number. `last_activity_at` is backdated so that an accepted Move
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
 * Every `games` row and every `moves` row, all columns, read straight from the
 * tables in a fixed order.
 *
 * A rate-limited request must change NO Game state (Property 19's `rate_limited`
 * row), and the honest way to say that is over the whole world rather than over the
 * columns of the one Game the refused request named — a 429 that ran the controller
 * would show up here whichever row it touched. The Move_List is included because
 * the criterion is about Game state and the Move_List is the larger half of it;
 * `rateLimitActiveGame()` seeds real Move rows precisely so that this comparison is
 * not vacuously over an empty table.
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
 * The `X-RateLimit-Remaining` value `$response` reports, as an integer, or null
 * where the header is absent.
 *
 * Absence is a real answer and is asserted against below: a limiter returning
 * `Limit::none()` short-circuits before `addHeaders()` is reached (line 125 of
 * `ThrottleRequests`), so a missing header is precisely the shape a
 * silently-unlimited route would have, and it must not be read as "plenty
 * remaining".
 *
 * @param  TestResponse<Response>  $response
 */
function rateLimitRemaining(TestResponse $response): ?int
{
    $value = $response->headers->get('X-RateLimit-Remaining');

    return $value === null ? null : (int) $value;
}

/**
 * Asserts `$response` was NOT rate limited, and says which request it was.
 *
 * The assertion is `not 429` rather than a specific success status on purpose: a
 * conforming request's own outcome is another file's subject, and the claim here is
 * only that the limiter let it through. The status is reported in the failure
 * message so a 500 does not read as a passing "not rate limited".
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
 * `array` STORE, PINNED AND CHECKED. Order-independence, and the precondition every
 * assertion in this file rests on.
 */
beforeEach(function () {
    config(['cache.default' => 'array', 'cache.limiter' => null]);

    expect(Cache::driver()->getStore())->toBeInstanceOf(
        ArrayStore::class,
        'the rate limiter is not counting into an in-process array store, so the 60-second window is not deterministic and neither is anything below',
    );
});

/*
 * THE JOIN BOUNDARY (Req 10.6, 14.4, and Property 19's `rate_limited` row).
 *
 * Requirement 14.4 asks for exactly two facts and this asserts both, so an
 * off-by-one in EITHER direction fails: the twentieth join request from one
 * Rate_Limit_Subject inside one 60-second window receives no rate-limited outcome,
 * and the twenty-first is rejected with one. A limiter set to 19 fails on request
 * twenty; one set to 21 fails on request twenty-one.
 *
 * THE TWENTY-FIRST REQUEST NAMES A DIFFERENT GAME, AND THAT IS THE POINT OF THE
 * FIXTURE. Requests one to twenty carry Game A's Join_Code — the first claims its O
 * slot and the remaining nineteen short-circuit on the token the first one stored,
 * which is what a person hammering the join button actually produces. Request
 * twenty-one carries Game B's code, a Game that is still waiting for an opponent,
 * so it is a join that WOULD HAVE SUCCEEDED. The refusal is therefore visible as a
 * refusal rather than as a request that had nothing to do anyway, and the
 * no-state-change assertion has something to be about: had the controller run, B
 * would be `active` with an occupied O slot and a Version_Counter of 1. The
 * assertion that the same request from another subject then succeeds is what turns
 * "would have succeeded" from a claim into an observation.
 */
it('does not rate limit the twentieth join request from one subject and rejects the twenty-first', function () {
    $threshold = 20;

    [$a] = rateLimitWaitingGame('4K7P29QZR3');
    [$b] = rateLimitWaitingGame('5M8Q3AR0S4');
    // A third Game, mid-play and untouched by anything below, so that the
    // no-state-change comparison spans a non-empty Move_List rather than two rows
    // that have no Moves to lose.
    rateLimitActiveGame([0, 4, 1]);

    $remaining = [];

    for ($request = 1; $request <= $threshold; $request++) {
        $response = post('/join', ['join_code' => $a->join_code]);

        rateLimitAssertPassed($response, "join request {$request} of {$threshold}");

        $remaining[$request] = rateLimitRemaining($response);
    }

    // THE SHARED BUCKET, PROVED FROM THE APPLICATION'S OWN HEADERS. Twenty requests
    // that each landed in a bucket of their own would every one of them report 19
    // remaining. A fall of exactly one per request, from 19 to 0, is only possible
    // if all twenty counted against one key — so this is the assertion that stops
    // the loop above from being a loop of twenty independent first requests.
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

    // THE THRESHOLD AND THE WINDOW, READ OFF THE REFUSAL ITSELF. `X-RateLimit-Limit`
    // is the `maxAttempts` the middleware counted against, so it says the limit is
    // twenty rather than leaving the reader to infer it from the loop length; and
    // `Retry-After` is `availableIn()` on a 60-second decay, so a value inside 1..60
    // is what says the refusal lasts for the remainder of a 60-SECOND window rather
    // than of some other window (Req 10.6).
    $retryAfter = (int) $refused->headers->get('Retry-After');

    expect($refused->headers->get('X-RateLimit-Limit'))->toBe(
        (string) $threshold,
        'the join limiter is not set to twenty per window (Req 10.6)',
    )
        ->and(rateLimitRemaining($refused))->toBe(0, 'the refusal does not report an exhausted bucket')
        ->and($retryAfter)->toBeGreaterThan(0, 'the refusal carries no Retry-After, so it states no window')
        ->and($retryAfter)->toBeLessThanOrEqual(60, 'the join limiter decays over something longer than the 60-second window Requirement 10.6 states');

    // THE REFUSED REQUEST CHANGED NO GAME STATE — every row of both tables, not the
    // status alone. The 429 is thrown by the middleware before `JoinGameController`
    // is reached, so this is the assertion that the shortcut is genuine rather than
    // a controller that ran and then reported a refusal (Property 19).
    expect(rateLimitWorldSnapshot())->toBe(
        $snapshot,
        'the rate-limited join request changed persisted Game state (Property 19)',
    );

    $bRow = DB::table('games')->where('id', $b->id)->first();

    expect($bRow)->not->toBeNull()
        ->and((array) $bRow)->toMatchArray([
            'state' => GameState::WaitingForOpponent->value,
            'o_token_hash' => null,
            'version_counter' => 0,
        ], 'the Game the rate-limited request named was joined anyway (Req 10.6, Property 19)')
        ->and((new PlayerTokens)->heldFor($b->id))->toBeNull(
            'the rate-limited request left a Player_Token in the session for a Game it never joined',
        );

    // THE STRONGER HALF OF THE SHARED-BUCKET PROOF, AND THE PROOF THE REFUSAL WAS
    // THE LIMITER'S DOING. The identical request from a DIFFERENT Rate_Limit_Subject
    // — a forwarded client address, which task 9.5 establishes the application
    // honours — is refused nothing and joins Game B. So the twenty-first request
    // failed because its subject's bucket was full and for no other reason, and the
    // twenty before it must have shared that bucket for it to have been full.
    $elsewhere = post('/join', ['join_code' => $b->join_code], ['X-Forwarded-For' => '203.0.113.7']);

    rateLimitAssertPassed($elsewhere, 'a join request from a second Rate_Limit_Subject');

    $elsewhere->assertStatus(StatusCodes::HTTP_SEE_OTHER)
        ->assertRedirect(route('games.show', ['game' => $b->id]));

    expect(rateLimitRemaining($elsewhere))->toBe(
        $threshold - 1,
        'the second subject did not start on a bucket of its own, so Requirement 10.6 is a single global limit rather than a per-subject one',
    );

    // FOR THE REMAINDER OF THAT WINDOW (Req 10.6). One refusal could be a single
    // unlucky request; the criterion says the rejection persists.
    expect(post('/join', ['join_code' => $a->join_code])->getStatusCode())->toBe(
        StatusCodes::HTTP_TOO_MANY_REQUESTS,
        'the exhausted subject was served again inside the same window (Req 10.6)',
    );

    // AND THE WINDOW IS 60 SECONDS RATHER THAN PERMANENT. The `array` store expires
    // against `Carbon::now()`, so moving the clock past the decay is what
    // distinguishes a 60-second window from a subject that has been locked out for
    // good. `Illuminate\Foundation\Testing\TestCase::tearDown()` clears the test
    // clock, and it is cleared here as well so a failure above cannot leak it.
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
 * PROPERTY 20's STATE HALF: CONFORMING POLLING IS NEVER RATE LIMITED (Req 10.8).
 *
 * A FULL WINDOW AT THE FULL RATE, NOT A SAMPLE. Requirement 8.1 requires the
 * Web_Client to request Game state at intervals of no more than 2 seconds while the
 * Game is not terminal, so a conforming client issues 60000 / 2000 = 30 state
 * requests inside one 60-second window. All thirty are issued here and every one is
 * asserted not rate limited, which is what Requirement 10.8's closing clause asks
 * for: "state requests made at the rate required by Requirement 8 receive no
 * rate-limited outcome". A handful of requests would have asserted nothing about a
 * threshold of 120.
 *
 * THE REQUESTS ARE THE REAL POLLING REQUESTS. Each is an authorised
 * `GET /games/{game}` from a session holding the X Player_Token — the route
 * `useGamePolling` reloads — and each is asserted `200`, so a `403` from a broken
 * fixture cannot pass as "not rate limited". `GET` is also why one session can issue
 * thirty of them without the Game changing under it.
 *
 * AND THE HEADROOM IS ASSERTED, NOT JUST THE OUTCOME. Requirement 10.8 permits
 * either no limit or a limit "whose threshold exceeds the request rate required by
 * Requirement 8", and the design chose the latter at four times the rate. So the
 * threshold is read off the response and compared against the derived rate, and the
 * absence of a header — the shape a `Limit::none()` route would have — is excluded
 * as well, since an unlimited route would satisfy the criterion but would not
 * satisfy the design and would silently retire this test.
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

    // ONE BUCKET AGAIN, AND FOR THE SAME REASON IT MATTERS HERE. "Thirty requests
    // were not rate limited" is trivially true of thirty requests in thirty buckets,
    // which is precisely the failure mode that would make Property 20 vacuous. The
    // header falling by exactly one per request, from 119 to 90, is what says the
    // whole window's polling counted against one subject and still cleared the
    // threshold with three quarters of it unspent.
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

    // THE STATE LIMITER IS SEPARATE FROM THE MOVE LIMITER, WHICH REQUIREMENT 10.8
    // STATES OUTRIGHT ("a rate limit separate from the limit stated in criterion 7").
    // Thirty state requests have been spent; a move request on the same Game in the
    // same session must find its own sixty untouched, and its `X-RateLimit-Limit`
    // must be the move limiter's rather than the state limiter's. Sharing a bucket
    // would show up as a limit of 120 here or as an allowance short of 59.
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
 * PROPERTY 20's MOVE HALF: THE PER-PLAYER_TOKEN BOUNDARY (Req 10.7).
 *
 * Property 20 states it alongside the join boundary — "the sixtieth move request
 * presenting one Player_Token is not rate limited and the sixty-first is" — and
 * Requirement 10.7 is on this task's Validates line, so it is asserted here rather
 * than left to the join half by analogy. The two limiters are not analogous: `join`
 * counts per Rate_Limit_Subject and `move` is the ONE limiter keyed on something
 * else, the hash of the Player_Token the request presents. A test of the join
 * boundary alone would say nothing about whether that key works, and a `move`
 * limiter that quietly fell back to the subject for every request would pass every
 * other test in this suite.
 *
 * ONLY THE FIRST OF THE SIXTY IS ACCEPTED, AND THAT IS DELIBERATE. The fixture is
 * mid-play with `X` to move, so request one is a real accepted Move and requests two
 * to sixty are `not_your_turn` — Requirement 10.7 counts move requests PRESENTING a
 * Player_Token irrespective of their outcome, and a Game holds nine Moves at most,
 * so sixty accepted Moves are not a thing that can be asked for. Mixing the two
 * shapes is what shows the counter is on the request rather than on the write: an
 * implementation that only counted accepted Moves would need 60 acceptances to
 * refuse anything and would never refuse this loop at all.
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

    // THE REFUSED MOVE CHANGED NO GAME STATE, over a Move_List that genuinely has
    // three Moves in it and a Cell — 2 — that was free and would have been taken had
    // the controller run (Property 19).
    expect(rateLimitWorldSnapshot())->toBe(
        $snapshot,
        'the rate-limited move request changed persisted Game state (Property 19)',
    );

    // THE BUCKET IS THE PRESENTED PLAYER_TOKEN'S, WHICH IS THE WHOLE OF REQUIREMENT
    // 10.7 AND THE STRONGEST FORM OF THE SHARED-BUCKET PROOF IN THIS FILE. Same
    // route, same Game, same Rate_Limit_Subject, same client address — everything a
    // coarser key could be built from is unchanged — and the O Player, presenting the
    // other Player_Token, is refused nothing. So the sixty requests above shared a
    // bucket that belongs to X's token and to nothing else.
    Session::flush();
    // 40 alphanumeric characters is what `Store::isValidId()` accepts; anything else
    // is silently replaced by a generated id, and the switch would then be
    // indistinguishable from a switch that failed.
    Session::setId(Str::random(40));
    Session::start();

    $tokens->remember($game->id, $fixture['tokens']['o']);

    $opponent = post('/games/'.$game->id.'/moves', ['cell_index' => 2]);

    rateLimitAssertPassed($opponent, 'a move request presenting the opponent\'s Player_Token');

    expect(rateLimitRemaining($opponent))->toBe(
        $threshold - 1,
        'the second Player_Token did not start on a bucket of its own, so the move limit is not counted per presented Player_Token (Req 10.7)',
    );

    // And it was a real Move, so the request that proved the second bucket was a
    // move request rather than something the route happened to answer.
    expect($opponent->getStatusCode())->toBe(StatusCodes::HTTP_SEE_OTHER)
        ->and(DB::table('moves')->where('game_id', $game->id)->count())->toBe(4)
        ->and($tokens->resolve(Game::query()->findOrFail($game->id), (string) $tokens->heldFor($game->id)))->toBe(Mark::O);
});
