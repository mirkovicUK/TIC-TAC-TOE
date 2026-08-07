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
 * The `TrustProxies` configuration, and nothing about rate-limit behaviour: this file
 * never counts a request. It asserts what the application believes the client's address
 * to be, and that the IP branch of the Rate_Limit_Subject follows that belief. The
 * 20/21 join boundary and Property 20 are `RateLimitTest`'s.
 *
 * No behavioural test of the limiters would notice `TrustProxies` leaving the global
 * stack or its range being narrowed, because every test client is its own peer and
 * every subject resolves to a working key either way. In production the peer is Caddy,
 * where the same misconfiguration collapses `join`, `create-game` and `state` into
 * three global buckets keyed on Caddy's container address, turning Req 10.6's twenty
 * joins per subject into twenty for the whole instance.
 *
 * Coupled to the `*` trusted range on purpose. A feature test has no real peer, so the
 * framework supplies a loopback `REMOTE_ADDR`, and `X-Forwarded-For` is honoured only
 * where the trusted range covers that peer. `bootstrap/app.php` passes
 * `trustProxies(at: '*')`, which `Illuminate/Http/Middleware/TrustProxies.php`
 * special-cases at line 83 and routes to `setTrustedProxyIpAddressesToTheCallingIp()`
 * (line 120), whose body sets `['0.0.0.0/0', '::/0']` (line 122) — loopback included,
 * which is the only reason a feature test can observe any of this. Narrowing the range
 * to a declared subnet makes loopback untrusted and fails the assertions below, so
 * whoever narrows it must check the new range covers the real proxy and change the peer
 * these assertions run as. `peer_is_trusted` is asserted separately from the address so
 * the failure says which half went.
 *
 * No `PreventRequestForgery` assertion, and not an omission. `handle()` short-circuits
 * on the first true of five conditions and `runningUnitTests()` is the second (line 99,
 * ahead of `inExceptArray()`, `hasValidOrigin()` and `tokensMatch()` at lines 100 to
 * 102); it is `runningInConsole() && runningUnitTests()` (line 132), both true
 * throughout this suite, so no assertion here could distinguish a configured
 * application from a misconfigured one. Req 14.3 excludes it from mandated coverage.
 */

/**
 * Declares the probe route.
 *
 * `->middleware('web')` runs `StartSession`, so `$request->hasSession()` is true and the
 * Rate_Limit_Subject callback takes the same branch it takes on `POST /join` rather than
 * a degenerate one.
 *
 * `TrustProxies` is global middleware, so it has already run by the time any handler is
 * reached, on this route exactly as on a real one — which is what makes a probe route a
 * faithful place to read the client address from.
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
 * The key a named limiter would count this request against, read from the limiter the
 * application registered rather than from a copy of its logic.
 *
 * `RateLimiter::limiter()` returns the closure `AppServiceProvider::boot()` passed to
 * `RateLimiter::for()`, so calling it evaluates the real Rate_Limit_Subject against the
 * real request. Nothing is counted: `Limit` is a value object and this reads its `key`.
 * The thresholds those keys are counted against belong to `RateLimitTest`.
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
 * Read through `$request->ip()` inside a handler, the same call
 * `AppServiceProvider::rateLimitSubject()` makes, rather than the header, which is
 * present either way and would assert nothing.
 *
 * Non-vacuity: the unforwarded baseline and the unchanged peer rule out a peer that was
 * already `203.0.113.7`, which would let the forwarded assertion pass with
 * `TrustProxies` deleted outright.
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

    // The coupling to the trusted range, as an assertion rather than a comment: `*`
    // resolves to `['0.0.0.0/0', '::/0']`, which covers loopback, and a declared subnet
    // would not.
    expect($forwarded['peer_is_trusted'])->toBeTrue(
        'the trusted proxy range no longer covers the loopback peer a feature test runs as — narrowing it from `*` is what this test is coupled to, and the forwarded header is ignored in this environment as a result',
    )
        ->and($forwarded['trusted_proxies'])->toBeArray()
        ->and($forwarded['trusted_proxies'])->not->toBe([], 'no trusted proxies are configured at all');
});

/*
 * The Rate_Limit_Subject follows the client address, which is why the configuration
 * exists (Req 10.6, 10.8). `$request->ip()` alone would be a test of Symfony's header
 * parsing, so the subject is read from the limiter closures the application registered,
 * for the two limiters whose criteria this covers — `join` (10.6) and `state` (10.8).
 * `move` keys on the presented token's hash and is unaffected either way.
 *
 * The last assertion is the point: two callers at two addresses land in two buckets,
 * where a misconfiguration makes both `ip:127.0.0.1` — one bucket shared by everybody.
 */
it('derives the ip branch of the Rate_Limit_Subject from the forwarded client address', function () {
    forwardedProbeRoute();

    $direct = forwardedObservation();

    // Non-vacuity: the probe presents no session cookie, so `rateLimitSubject()` falls
    // past the session branch. This rules out keys that are a hash of a session id and
    // would not move with the client address however `TrustProxies` were configured.
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
