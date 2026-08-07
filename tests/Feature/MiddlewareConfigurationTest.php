<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\IpUtils;

use function Pest\Laravel\get;

// Feature: remote-tic-tac-toe
//
// Validates: Requirements 10.6, 10.8
//
/*
 * Task 9.5 — the `TrustProxies` configuration of task 9.2, and nothing about
 * rate-limit BEHAVIOUR. The 20/21 join boundary and Property 20 are task 9.6's
 * `RateLimitTest`; this file never counts a request. It asserts what the
 * application believes the client's address to be, and that the IP branch of the
 * Rate_Limit_Subject is derived from that belief.
 *
 * WHY THIS FILE EXISTS AT ALL. The feature suite talks to the application
 * directly, so no behavioural test of the limiters would notice `TrustProxies`
 * being removed from the global stack or its trusted range being narrowed — every
 * test client is its own peer, so every subject resolves to a working key either
 * way. In production the peer is Caddy, and the same misconfiguration collapses
 * `join`, `create-game` and `state` into three single global buckets keyed on
 * Caddy's container address: Requirement 10.6's twenty joins per subject becomes
 * twenty joins for the whole instance. That is the failure this file is the only
 * mechanical guard against, which is why it is not optional.
 *
 * `THIS TEST IS COUPLED TO THE `*` TRUSTED RANGE, ON PURPOSE, AND HERE IS WHAT
 * BREAKS IT.` A feature test has no real peer, so the framework supplies a
 * loopback `REMOTE_ADDR`, and `X-Forwarded-For` is honoured only where the trusted
 * range covers that peer. `bootstrap/app.php` passes `trustProxies(at: '*')`,
 * which `TrustProxies` special-cases at line 83 of
 * `Illuminate/Http/Middleware/TrustProxies.php` and routes to
 * `setTrustedProxyIpAddressesToTheCallingIp()` (line 120), whose whole body sets
 * `['0.0.0.0/0', '::/0']` (line 122) — loopback included, which is the only reason
 * a feature test can observe any of this. Narrowing the range to a declared subnet
 * — the alternative task 9.2 weighed and rejected — makes loopback untrusted and
 * fails the assertions below rather than surfacing in production. The test and the
 * range are one decision: whoever narrows the range should read this file, satisfy
 * themselves that the new range covers the real proxy, and change the peer these
 * assertions run as. `peer_is_trusted` is asserted separately from the address so
 * that the failure says which of the two halves went.
 *
 * NO FORGERY ASSERTION HERE, AND IT IS NOT AN OMISSION. Task 1.2 settled the
 * `PreventRequestForgery` question against the vendor source and task 9.1 recorded
 * that the defaults are deliberate; a companion assertion was then considered for
 * this file and dropped, because there is no flag to read and nothing about the
 * path is observable from a feature test. `handle()` short-circuits on the first
 * true condition of five, and `runningUnitTests()` is the SECOND of them —
 * `isReading()` at line 98, `runningUnitTests()` at line 99, then
 * `inExceptArray()`, `hasValidOrigin()` and `tokensMatch()` at lines 100 to 102,
 * with the `TokenMismatchException` at line 111. `runningUnitTests()` is
 * `runningInConsole() && runningUnitTests()` (line 132), both true for every test
 * in this suite, so the origin and token checks are never reached from here and no
 * assertion could distinguish a configured application from a misconfigured one.
 * Requirement 14.3's exclusion of the forgery rejection from the mandated coverage
 * therefore stands on its own.
 */

/**
 * Declares the probe route and returns nothing.
 *
 * `->middleware('web')` matters twice. `StartSession` runs, so
 * `$request->hasSession()` is true and the Rate_Limit_Subject callback takes the
 * same branch it takes on `POST /join` rather than a degenerate one. And a GET
 * through the real group is the request shape the framework's own middleware
 * priority applies to, so the observation is made where the application makes it.
 *
 * `TrustProxies` needs no mention: it is GLOBAL middleware, so it has already run
 * by the time any handler is reached, on this route exactly as on a real one. That
 * is what makes a probe route a faithful place to read the client address from —
 * this file could not have waited for a route of its own.
 */
function forwardedProbeRoute(): void
{
    Route::middleware('web')->get('/_probe/client-address', function (Request $request) {
        $trusted = Request::getTrustedProxies();
        $peer = (string) $request->server->get('REMOTE_ADDR');

        return response()->json([
            'ip' => $request->ip(),
            'peer' => $peer,
            'trusted_proxies' => $trusted,
            'peer_is_trusted' => $trusted !== [] && IpUtils::checkIp($peer, $trusted),
            'join_subject' => forwardedLimiterKey('join', $request),
            'state_subject' => forwardedLimiterKey('state', $request),
        ]);
    });
}

/**
 * The key a named limiter would count this request against, read from the limiter
 * the application registered rather than from a copy of its logic.
 *
 * `RateLimiter::limiter()` returns the very closure `AppServiceProvider::boot()`
 * passed to `RateLimiter::for()`, so calling it here evaluates the real
 * Rate_Limit_Subject against the real request. NOTHING IS COUNTED: `Limit` is a
 * value object and this reads its `key`, which is the derivation task 9.2's
 * configuration feeds. The thresholds those keys are counted against belong to
 * task 9.6.
 */
function forwardedLimiterKey(string $limiter, Request $request): ?string
{
    $callback = app(RateLimiter::class)->limiter($limiter);

    if ($callback === null) {
        return null;
    }

    $limit = $callback($request);

    return $limit instanceof Limit ? (string) $limit->key : null;
}

/**
 * One request to the probe, with or without a forwarded client address.
 *
 * @return array<string, mixed>
 */
function forwardedObservation(?string $clientAddress = null): array
{
    $response = get(
        '/_probe/client-address',
        $clientAddress === null ? [] : ['X-Forwarded-For' => $clientAddress],
    );

    $response->assertOk();

    $body = $response->json();

    expect($body)->toBeArray('the probe route did not answer with the observation this file reads');

    /** @var array<string, mixed> $body */
    return $body;
}

/*
 * THE FORWARDED CLIENT ADDRESS IS THE ONE THE APPLICATION SEES.
 *
 * Read through `$request->ip()` inside a handler, because that is the application's
 * view of the client — the same call `AppServiceProvider::rateLimitSubject()` makes
 * and the same one any client-address log field would resolve to — rather than the
 * header, which is present either way and would assert nothing.
 *
 * THE UNFORWARDED BASELINE IS ASSERTED FIRST, and it is what keeps the second half
 * honest: if the peer were already `203.0.113.7`, the forwarded assertion would
 * pass with `TrustProxies` deleted outright. The peer is asserted to be UNCHANGED
 * by the forwarded request too, so the address the application reports changed
 * because the header was honoured and not because the request came from somewhere
 * else.
 */
it('honours a forwarded client address, so the application sees the client rather than the proxy', function () {
    forwardedProbeRoute();

    $direct = forwardedObservation();

    expect($direct['peer'])->toBe('127.0.0.1', 'the test client is not the loopback peer this file reasons about')
        ->and($direct['ip'])->toBe($direct['peer'], 'the application does not see an unforwarded request as coming from its peer');

    $forwarded = forwardedObservation('203.0.113.7');

    expect($forwarded['ip'])->toBe(
        '203.0.113.7',
        'X-Forwarded-For was not honoured: TrustProxies is absent from the global stack, or its trusted range no longer covers the peer, and every IP-keyed limiter is now one global bucket keyed on the proxy (Req 10.6, 10.8)',
    )
        ->and($forwarded['peer'])->toBe('127.0.0.1', 'the peer changed between the two requests, so the address above proves nothing about the header')
        ->and($forwarded['ip'])->not->toBe($forwarded['peer']);

    // THE COUPLING TO TASK 9.2's RANGE, STATED AS AN ASSERTION RATHER THAN A
    // COMMENT. `*` resolves to `['0.0.0.0/0', '::/0']`, which covers loopback; a
    // declared subnet would not, and this is the assertion that says so in the
    // failure output instead of leaving the reader to infer it from the address
    // above.
    expect($forwarded['peer_is_trusted'])->toBeTrue(
        'the trusted proxy range no longer covers the loopback peer a feature test runs as — narrowing it from `*` is what this test is coupled to, and the forwarded header is ignored in this environment as a result',
    )
        ->and($forwarded['trusted_proxies'])->toBeArray()
        ->and($forwarded['trusted_proxies'])->not->toBe([], 'no trusted proxies are configured at all');
});

/*
 * AND THE RATE_LIMIT_SUBJECT FOLLOWS IT — which is the reason the configuration
 * exists (Req 10.6, 10.8).
 *
 * `$request->ip()` alone would be a test of Symfony's header parsing. The decision
 * task 9.2 records is about the limiters: the IP branch of the Rate_Limit_Subject
 * is reachable because no state-changing request is guaranteed to carry an
 * established Player_Session, and without the forwarded header that branch
 * resolves to the proxy for every caller alike. So the subject is read from the
 * limiter closures the application actually registered, for the two limiters whose
 * criteria this task cites — `join` (10.6) and `state` (10.8). `move` is left
 * alone: it keys on the presented token's hash and is unaffected either way.
 *
 * THE LAST ASSERTION IS THE WHOLE POINT. Two callers at two addresses must land in
 * two buckets. Under a misconfiguration both would be `ip:127.0.0.1` — one bucket,
 * shared by everybody, which is exactly the collapse the design describes and the
 * one thing that would still look correct in every other test in this suite.
 */
it('derives the ip branch of the Rate_Limit_Subject from the forwarded client address', function () {
    forwardedProbeRoute();

    $direct = forwardedObservation();

    // PRECONDITION: THE IP BRANCH IS THE BRANCH BEING OBSERVED. The probe presents
    // no session cookie, so `rateLimitSubject()` falls past the session branch —
    // if it did not, the keys below would be a hash of a session id and would not
    // move with the client address no matter how `TrustProxies` were configured.
    expect($direct['join_subject'])->toBeString()->toStartWith('ip:')
        ->and($direct['state_subject'])->toBeString()->toStartWith('ip:')
        ->and($direct['join_subject'])->toBe('ip:127.0.0.1', 'the unforwarded subject is not the peer address, so the comparison below is not the one described')
        ->and($direct['state_subject'])->toBe('ip:127.0.0.1');

    $first = forwardedObservation('203.0.113.7');
    $second = forwardedObservation('198.51.100.4');

    expect($first['join_subject'])->toBe('ip:203.0.113.7', 'the join limiter does not count against the forwarded client address (Req 10.6)')
        ->and($first['state_subject'])->toBe('ip:203.0.113.7', 'the state limiter does not count against the forwarded client address (Req 10.8)')
        ->and($second['join_subject'])->toBe('ip:198.51.100.4')
        ->and($second['state_subject'])->toBe('ip:198.51.100.4')
        ->and($first['join_subject'])->not->toBe(
            $second['join_subject'],
            'two callers at two addresses share one join bucket, so Requirement 10.6 is a single global limit rather than a per-subject one',
        )
        ->and($first['state_subject'])->not->toBe($second['state_subject']);
});
