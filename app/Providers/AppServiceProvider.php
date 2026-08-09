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
 * The four named rate limiters (Req 10.6, 10.7, 10.8), and nothing else.
 *
 * The definitions belong in `boot()` rather than `register()`: they resolve the
 * `RateLimiter` singleton, which is a service and not a configuration value.
 *
 * The matching `throttle:` attachments live in `routes/web.php`. A named limiter
 * is resolved at request time, so a `throttle:join` on a route with no `join`
 * limiter defined is a 500 on the first request through it rather than a
 * boot-time error — the two halves have to land together.
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
     * The four named limiters, with the route each is attached to and the
     * criterion it serves.
     *
     * | Limiter       | Threshold             | Route                      | Criterion |
     * | ------------- | --------------------- | -------------------------- | --------- |
     * | `join`        | 20 / 60s per subject  | `POST /join`               | 10.6      |
     * | `move`        | 60 / 60s per token    | `POST /games/{game}/moves` | 10.7      |
     * | `state`       | 120 / 60s per subject | `GET /games/{game}`        | 10.8      |
     * | `create-game` | 20 / 60s per subject  | `POST /games`              | *none*    |
     *
     * `ThrottleRequests` tests the counter before incrementing it, so the
     * twentieth join request passes and the twenty-first is refused — the
     * boundary Requirement 10.6 states as "exceeds 20". The 429 runs no
     * controller, so a refused request changes no Game state. Requirement 10.8
     * permits no limit at all on state requests; it gets one anyway, at four
     * times the polling rate the design asks for.
     *
     * The limiter name is part of the cache key the middleware composes, so
     * `join` and `create-game` are separate buckets even though they share a
     * threshold and a key: spending twenty joins still leaves twenty creates.
     *
     * COUNTER STORAGE: `file` store, `storage/framework/cache/data` in the `app`
     * container. Keys are the middleware's digest.
     *
     * `CACHE_STORE=file` is set explicitly in `.env.example` and `compose.yaml`. It
     * is NOT the framework default — `config/cache.php` defaults to `database`.
     *
     * Why not `database`: counters shared the `cache` table with the Games in one
     * SQLite file. Two clients polling every 2s issue overlapping increments; one
     * reads a stale snapshot; SQLite returns SQLITE_BUSY immediately. `busy_timeout`
     * does not apply (the transaction must roll back) and Laravel does not retry.
     * Surfaced as a 500 on a polling request, to the player who was not acting.
     * Measured 13 failures in 30 concurrent requests. Reached production. ADR-004
     * and `.env.example` carry the measurement.
     *
     * Two consequences of `file`, both accepted:
     * - Counters reset on deployment. Nothing mounts `storage/`, so recreating the
     *   container zeroes every bucket. Recorded in `docs/deployment.md`.
     * - Counters are per-container. All workers in the single php-fpm container
     *   share them; a second app container or host keeps its own counts and
     *   multiplies every threshold. Scaling out needs a shared store first.
     *
     * `create-game` is required by no criterion. It guards a public endpoint that
     * writes a database row for an unauthenticated caller with no prior Game and
     * no established session, and is recorded here, in the design's limiter table
     * and in `routes/web.php` so a reviewer sees it is an addition.
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
     * The Rate_Limit_Subject of `$request` (Req 10.6): the Player_Session where
     * the request carries one, and the requesting IP address otherwise.
     *
     * The source is the session COOKIE the request presented, not
     * `session()->getId()`, and the difference is the whole control.
     * `StartSession::getSession()` calls `Store::setId()` with the cookie value,
     * and `setId()` replaces anything that is not a valid id with a freshly
     * generated one. `StartSession` runs above `ThrottleRequests` in the
     * middleware priority list, so by the time this callback runs every request
     * has a session id, including one that presented no cookie at all. Keying on
     * that id would hand a cookieless caller a brand-new bucket on every request
     * and silently retire Requirements 10.6 and 10.8 for exactly the caller they
     * exist to constrain, while the limiters kept working for ordinary browsers.
     * Reading the cookie makes the fallback real: no cookie means the IP branch.
     *
     * The session branch keys on `hash('sha256', $presented)`, so the value that
     * would let a reader of the cache resume somebody's session never reaches a
     * key. The middleware hashes again with `md5`, but that hash can be switched
     * off process-wide by `ThrottleRequests::shouldHashKeys(false)`; this one
     * cannot, which is why it is done here.
     *
     * The cookie value is trustworthy when read: `EncryptCookies` runs earlier and
     * nulls anything it cannot decrypt, so a non-null value was issued by this
     * application under its own `APP_KEY`. `Session::isValidId()` checks the shape.
     *
     * One limitation, recorded in the design and not compensated for here: a
     * caller who discards the cookie gets a fresh subject, so the session branch
     * is bypassable — at the cost of one request, precisely because the cookieless
     * case falls to the IP branch rather than to a free bucket.
     *
     * The `session:` and `ip:` prefixes keep the branches in separate key spaces.
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
     * Requirement 10.7 counts move requests per presented Player_Token rather than
     * per subject. "Presented" is the raw token the Player_Session holds under this
     * Game_Id — the only credential a move request can offer (ADR-005) — so
     * `PlayerTokens::heldFor()` is the source, and it is read here, before the
     * acting-player middleware runs.
     *
     * The route parameter is read with `originalParameter()`, not `parameter()`, so
     * it is the raw string the URL carried. Nothing substitutes a model for
     * `{game}` today, but binding substitution runs below `ThrottleRequests`, so
     * the distinction is worth getting right.
     *
     * The hash is not cosmetic: a raw Player_Token in a cache key would be a usable
     * credential sitting in the `cache` table, the exposure `PlayerTokens` avoids by
     * storing only a digest. `hash('sha256', ...)` matches what that class stores,
     * so no second scheme is introduced.
     *
     * Falling back to the Rate_Limit_Subject rather than `Limit::none()` keeps the
     * route capped at 60 per minute for a caller presenting no token, who would
     * otherwise issue unlimited `GameResolver` queries before being refused. The
     * `token:` prefix keeps the two buckets apart, so neither can exhaust the other.
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
