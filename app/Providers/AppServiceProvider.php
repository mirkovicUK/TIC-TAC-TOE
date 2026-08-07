<?php

declare(strict_types=1);

namespace App\Providers;

use App\Games\PlayerTokens;
use App\Http\Middleware\ResolveActingPlayer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\ServiceProvider;

/**
 * Task 9.4 — the four named rate limiters, and nothing else.
 *
 * `register()` stays empty on purpose: the whole of this provider is the
 * `RateLimiter::for()` definitions below, and those must be registered in
 * `boot()` rather than `register()` because they resolve the `Illuminate\Cache\
 * RateLimiter` singleton, which is a service and not a configuration value.
 *
 * WHY THE DEFINITIONS ARE HERE AND THE ATTACHMENTS ARE IN `routes/web.php`.
 * `ThrottleRequests` resolves a named limiter at REQUEST time — `handle()` calls
 * `$this->limiter->limiter($name)` (line 90 of
 * `Illuminate/Routing/Middleware/ThrottleRequests.php`) and, finding nothing,
 * falls through to `resolveMaxAttempts()` which throws
 * `MissingRateLimiterException: Rate limiter [join] is not defined.` (line 209 of
 * the same file; the exception extends plain `Exception`). So a `throttle:join`
 * on a route with no `join` limiter defined is a 500 on the first request through
 * it, not a boot-time error. The two halves of this task therefore land together.
 */
final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * The four named limiters of the design's limiter table.
     *
     * | Limiter       | Threshold           | Route                       | Criterion |
     * | ------------- | ------------------- | --------------------------- | --------- |
     * | `join`        | 20 / 60s per subject | `POST /join`               | 10.6      |
     * | `move`        | 60 / 60s per token   | `POST /games/{game}/moves` | 10.7      |
     * | `state`       | 120 / 60s per subject | `GET /games/{game}`       | 10.8      |
     * | `create-game` | 20 / 60s per subject | `POST /games`             | *none*    |
     *
     * `Limit::perMinute($n)` is `new Limit('', $n, 60 * 1)` — a count of `$n`
     * against a 60-second decay (line 75 of
     * `Illuminate/Cache/RateLimiting/Limit.php`), which is the "any 60-second
     * window" the criteria are written in terms of. `ThrottleRequests::
     * handleRequest()` checks `tooManyAttempts($key, $maxAttempts)` BEFORE it
     * hits the counter (lines 157 and 164), and `tooManyAttempts()` compares
     * `attempts >= $maxAttempts` (line 130 of `Illuminate/Cache/RateLimiter.php`),
     * so the twentieth join request sees nineteen
     * attempts and passes while the twenty-first sees twenty and is refused.
     * That is exactly the boundary Requirement 10.6 states ("exceeds 20") and the
     * one Property 20 pins in task 9.6.
     *
     * `THE FOUR NAMES ARE FOUR SEPARATE BUCKETS EVEN WHERE THE KEY IS THE SAME.`
     * `join` and `create-game` are both 20 per subject and would collide if the
     * key were all that distinguished them; it is not. The middleware composes the
     * cache key as `md5($limiterName.$limit->key)` (line 134), so the limiter name
     * is part of every key and a caller who has spent their twenty joins still has
     * twenty creates.
     *
     * `RETURNING A `Limit` RATHER THAN `Limit::none()`.` `none()` returns an
     * `Unlimited`, which `handleRequestUsingNamedLimiter()` short-circuits on
     * (line 125) — the request proceeds with no counter touched and no
     * `X-RateLimit-*` headers. Nothing here returns it: Requirement 10.8 permits
     * "no rate limit" for state requests, but a limit four times the required
     * polling rate is strictly better than none and costs nothing, so `state` gets
     * a real `Limit` too. Exceeding any of these throws
     * `ThrottleRequestsException` (line 158 via `buildException()`), which is a 429
     * carrying `Retry-After` and `X-RateLimit-*` and running no controller — so the
     * rate-limited outcome changes no Game state, which is the half of Property 19
     * that concerns these limiters.
     *
     * WHERE THE COUNTS LAND, AND THE ONE DEPLOYMENT CONSEQUENCE. `RateLimiter`
     * writes through the default cache store, which is `database` (`config/cache.php`)
     * with no `DB_CACHE_CONNECTION`, so the counters are rows in the `cache` table of
     * the same SQLite file that holds the Games and the sessions. Two consequences
     * follow and both are acceptable here. The counters are durable, so a container
     * restart does not hand an abuser a fresh window — a free improvement over an
     * in-memory store. And they are scoped to that one file: the deployment runs one
     * php-fpm container behind Caddy, so every worker shares the counts, but a second
     * application container or a second host would each keep their own and every
     * threshold would multiply by the number of them. If this is ever scaled out, the
     * limiter store has to move to something shared before the limits mean anything.
     * The rows carry the middleware's `md5` key rather than anything legible, so
     * nothing about a Player or a session is readable from that table.
     *
     * `create-game` IS DELIBERATE AND IS REQUIRED BY NO CRITERION. It guards a
     * public endpoint that creates a database row for an unauthenticated caller
     * with no prior Game and no established session — the cheapest thing on the
     * surface to abuse. It is recorded here, in the design's limiter table and in
     * `routes/web.php` so that a reviewer reading any of the three sees it is an
     * addition rather than a criterion someone invented.
     */
    public function boot(): void
    {
        RateLimiter::for(
            'join',
            fn (Request $request): Limit => Limit::perMinute(20)->by($this->rateLimitSubject($request)),
        );

        RateLimiter::for(
            'create-game',
            fn (Request $request): Limit => Limit::perMinute(20)->by($this->rateLimitSubject($request)),
        );

        RateLimiter::for(
            'state',
            fn (Request $request): Limit => Limit::perMinute(120)->by($this->rateLimitSubject($request)),
        );

        RateLimiter::for(
            'move',
            fn (Request $request): Limit => Limit::perMinute(60)->by(
                $this->presentedTokenKey($request) ?? $this->rateLimitSubject($request),
            ),
        );
    }

    /**
     * The Rate_Limit_Subject of `$request`: the Player_Session where the request
     * carries one, and the requesting IP address otherwise.
     *
     * NEITHER BRANCH PUTS AN IDENTIFIER IN THE KEY. The session branch keys on
     * `hash('sha256', $id)` and not on `$id`, so the value that would let a holder
     * of the cache resume somebody's session never reaches a cache key. The
     * middleware then hashes again — `md5($limiterName.$limit->key)` at line 134 of
     * `ThrottleRequests` — but that second hash is the framework's business and can
     * be switched off process-wide by `ThrottleRequests::shouldHashKeys(false)`,
     * which would leave the key readable. The hash here cannot be switched off, and
     * that is the point of doing it here rather than relying on the framework's.
     *
     * `THE SOURCE IS THE COOKIE THE REQUEST PRESENTED, NOT `session()->getId()`,
     * AND THE DIFFERENCE IS THE WHOLE CONTROL.` `StartSession::getSession()` does
     * `$session->setId($request->cookies->get($session->getName()))` (line 159),
     * and `Store::setId()` REPLACES anything that is not a valid id with a freshly
     * generated one (line 701). So by the time this callback runs — `StartSession`
     * sits above `ThrottleRequests` in the framework's middleware priority list, at
     * positions 4 and 7 — every request has a session id, including a request that
     * presented no cookie at all. Keying on that id would give a cookieless caller
     * a brand-new bucket on every single request and silently retire Requirements
     * 10.6 and 10.8 for exactly the caller they exist to constrain, while leaving
     * the limiters working perfectly for ordinary browsers. Reading the cookie
     * instead makes the fallback real: no cookie means the IP branch.
     *
     * The cookie value is trustworthy by the time it is read. `EncryptCookies` runs
     * before `StartSession` (priority 2) and replaces any value it cannot decrypt
     * with `null` (line 95 of `Illuminate/Cookie/Middleware/EncryptCookies.php`),
     * so a non-null value here was issued by this application under its own
     * `APP_KEY`. `Session::isValidId()` is then belt and braces on the shape.
     *
     * ONE HONEST LIMITATION, recorded in the design and not compensated for here:
     * a caller who discards the session cookie gets a fresh subject, so the
     * session branch is bypassable at the cost of one extra request. That conforms
     * to Requirement 10.6, which defines Rate_Limit_Subject session-first, and it
     * is a weak abuse control at this scope. Note what the paragraph above buys
     * even so — the bypass costs a request precisely because the cookieless case
     * falls to the IP branch rather than to a free bucket.
     *
     * The `session:` and `ip:` prefixes keep the two branches in separate key
     * spaces, so a 64-character hash can never be read as an address or the
     * reverse.
     */
    private function rateLimitSubject(Request $request): string
    {
        $presented = $request->hasSession()
            ? $request->cookies->get((string) $request->session()->getName())
            : null;

        if (is_string($presented) && Session::isValidId($presented)) {
            return 'session:'.hash('sha256', $presented);
        }

        return 'ip:'.(string) $request->ip();
    }

    /**
     * The key the `move` limiter counts against: a hash of the Player_Token this
     * request presents for the Game named in its URL, or null where it presents
     * none.
     *
     * Requirement 10.7 counts move requests per PRESENTED Player_Token rather than
     * per subject, and this is where that distinction is made. "Presented" means
     * what `GameResolver` will shortly mean by it: the raw token the Player_Session
     * holds under this Game_Id, which is the only credential a move request can
     * offer — there is no header, no body field and no bearer scheme (ADR-005).
     * `PlayerTokens::heldFor()` is therefore the one right source, and it takes the
     * Game_Id as a string, which is all this callback has.
     *
     * `THE ROUTE PARAMETER IS READ WITH `originalParameter()`, NOT `parameter()`,
     * for the same reason `ResolveActingPlayer` does: it is the raw string the URL
     * carried. No route-model binding is registered for `{game}` and no controller
     * type-hints `App\Models\Game`, so nothing would substitute a model here today
     * — but `SubstituteBindings` sits BELOW `ThrottleRequests` in the priority list
     * (positions 10 and 7) and the distinction costs nothing to get right.
     *
     * `THE HASH IS NOT A CONVENIENCE.` A raw Player_Token in a cache key would be a
     * usable credential sitting in the `cache` table, readable by anything that can
     * read that table and reachable by any diagnostic that dumps a key — the exact
     * exposure `PlayerTokens` exists to prevent by storing only a digest on the
     * `games` row. `hash('sha256', ...)` matches what that class stores, so the key
     * is derived from the same one-way function the authorisation path uses and no
     * second scheme is introduced. The value is a digest of a 256-bit random
     * secret, so it is not invertible by any means including a rainbow table.
     *
     * `FALLING BACK TO THE SUBJECT RATHER THAN TO `Limit::none()`.` A move request
     * presenting no token is refused `not_authorised` by `ResolveActingPlayer` —
     * but that is a controller-adjacent refusal that runs a `GameResolver` query
     * first, and `Limit::none()` here would let an unauthenticated caller issue
     * those queries without limit. Falling back to the Rate_Limit_Subject keeps the
     * limiter total over the route at 60 per minute, which is more than any real
     * player needs and less than a flood. Requirement 10.7 speaks only of requests
     * that DO present a token, so the fallback adds a limit where the criterion
     * asks for none and weakens nothing: the `token:` prefix keeps a token bucket
     * and a subject bucket apart, so a caller cannot spend one to exhaust the other.
     */
    private function presentedTokenKey(Request $request): ?string
    {
        $route = $request->route();

        $gameId = $route instanceof Route
            ? $route->originalParameter(ResolveActingPlayer::ROUTE_PARAMETER)
            : null;

        if (! is_string($gameId) || $gameId === '') {
            return null;
        }

        $presented = $this->app->make(PlayerTokens::class)->heldFor($gameId);

        return $presented === null
            ? null
            : 'token:'.hash('sha256', $presented);
    }
}
