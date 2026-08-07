<?php

use App\Http\Exceptions\GameNotVisibleException;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveActingPlayer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Inertia\Inertia;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Forgery protection stays at Laravel 13's defaults by decision, not omission:
        // no `allowSameSite()`, no `useOriginOnly()`. See design section 3, HTTP surface.
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        // TRUSTED PROXY RANGE `*`, AND THE PRECONDITION THAT MAKES IT ACCEPTABLE.
        // Caddy proxies to php-fpm over FastCGI, so without this the request source is
        // Caddy's container address and `$request->ip()` never sees a client. The range
        // cannot name Caddy: Compose does not fix its subnet unless the subnet is
        // declared, and `TrustProxies` matches on IPs and CIDRs rather than on service
        // names, so there is no address to write down ahead of time. `'*'` is the
        // alternative to declaring a static subnet, and it is acceptable ONLY because
        // port 9000 is not published to the host — the one thing on the network able to
        // reach php-fpm is the `web` container. Trusting any peer means anything that
        // CAN reach php-fpm may spoof its own client address and defeat every IP-keyed
        // limit, so PUBLISHING 9000, OR ADDING A SECOND SERVICE ABLE TO REACH `app`,
        // INVALIDATES THIS REASONING and forces the declared-subnet option instead.
        //
        // What it buys: `join`, `create-game` and `state` are keyed on the
        // Rate_Limit_Subject, whose IP branch is reachable because no state-changing
        // request is guaranteed to carry an established session. Without this they
        // collapse into single global buckets keyed on Caddy's address (Req 10.6, 10.8).
        // `move` is unaffected — it is keyed on the presented token's hash.
        $middleware->trustProxies(at: '*');

        // `ResolveActingPlayer` is an ALIAS and is deliberately NOT appended to
        // the global or `web` stacks. It resolves the `{game}` route parameter
        // through `GameResolver`, so it only makes sense on a route that names
        // one; on `GET /` or `POST /join` it would throw, since there is no
        // Game_Id to resolve. Task 5.6 attaches it per route, as
        // `->middleware('acting.player')` or by class name — the two are
        // equivalent, and the alias exists so the route definitions read as
        // English.
        //
        // One consequence of it being route middleware rather than group
        // middleware: `SubstituteBindings` in the `web` group runs first. That is
        // why no game-scoped controller may type-hint `App\Models\Game` — the
        // framework would 404 on a missing row before this middleware could
        // distinguish `game_expired` from `not_recognised`. The reasoning, and the
        // test that pins it, are in the middleware's own docblock.
        $middleware->alias([
            'acting.player' => ResolveActingPlayer::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // DENIAL OF VISIBILITY RENDERS ONE PAGE, KEYED BY OUTCOME, CARRYING ONLY
        // THE OUTCOME. `not_authorised` (403), `not_recognised` by Game_Id (404)
        // and `game_expired` (410) are the design's first transport family, and
        // this is the whole of it: `NotAPlayer.tsx` decides the copy from the
        // value, and the status comes from the exception, which is where the
        // design puts the outcome-to-status mapping.
        //
        // ONE PROP, AND THERE IS NOTHING ELSE TO PASS. `GameNotVisibleException`
        // carries a `VisibilityOutcome` and a status and no Game, so Requirement
        // 3.10's exclusion of the Board, the Move_List, the Game_State and the
        // Mark_To_Move is a property of the exception's shape rather than of this
        // closure remembering to omit them. Registered for the exception class
        // rather than by status code so that a framework 404 from somewhere else
        // is not dressed up as a refusal about a Game.
        //
        // `->toResponse($request)` then `->setStatusCode()`, in that order:
        // `Inertia\Response` has no status of its own, so the status is set on the
        // response it produces — an HTML document for a first visit, the Inertia
        // JSON payload for a client-side one, each carrying the correct status
        // either way.
        $exceptions->render(fn (GameNotVisibleException $exception, Request $request) => Inertia::render('NotAPlayer', [
            'outcome' => $exception->outcome->value,
        ])->toResponse($request)->setStatusCode($exception->getStatusCode()));
    })->create();
