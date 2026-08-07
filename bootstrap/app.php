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

        // Caddy proxies to php-fpm over FastCGI, so without this `$request->ip()` only
        // ever sees Caddy's container address. The range cannot name Caddy — Compose
        // does not fix its subnet unless the subnet is declared, and `TrustProxies`
        // matches IPs and CIDRs rather than service names — so `'*'` stands in for a
        // declared static subnet. It is acceptable only because port 9000 is not
        // published to the host: the one thing able to reach php-fpm is the `web`
        // container. Trusting any peer lets anything that CAN reach php-fpm spoof its
        // client address and defeat every IP-keyed limit, so publishing 9000, or adding
        // a second service able to reach `app`, invalidates this and forces the
        // declared-subnet option instead.
        //
        // What it buys: the IP branch of the Rate_Limit_Subject, which `join`,
        // `create-game` and `state` are keyed on and which is reachable because no
        // state-changing request is guaranteed to carry an established session. Without
        // it they collapse into global buckets keyed on Caddy's address (Req 10.6, 10.8).
        $middleware->trustProxies(at: '*');

        // `ResolveActingPlayer` is an alias and is deliberately on no global or `web`
        // stack: it resolves the `{game}` route parameter through `GameResolver`, so on
        // `GET /` or `POST /join` it would throw for want of a Game_Id. Routes attach it
        // per route; the alias exists so those definitions read as English.
        //
        // Because it is route middleware, `SubstituteBindings` in the `web` group runs
        // first — which is why no game-scoped controller may type-hint `App\Models\Game`.
        // The reasoning and the test that pins it are in the middleware's own docblock.
        $middleware->alias([
            'acting.player' => ResolveActingPlayer::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Denial of visibility renders one page keyed by outcome: `not_authorised`
        // (403), `not_recognised` (404) and `game_expired` (410). `NotAPlayer.tsx`
        // decides the copy from the value and the status comes from the exception,
        // which is where the design puts the outcome-to-status mapping.
        //
        // There is nothing else to pass: `GameNotVisibleException` carries an outcome
        // and a status and no Game, so Requirement 3.10's exclusion of the Board, the
        // Move_List, the Game_State and the Mark_To_Move is a property of the
        // exception's shape rather than of this closure remembering to omit them.
        // Registered for the exception class rather than by status code, so a framework
        // 404 from elsewhere is not dressed up as a refusal about a Game.
        //
        // `->toResponse($request)` then `->setStatusCode()`, in that order:
        // `Inertia\Response` has no status of its own, so the status is set on the
        // response it produces.
        $exceptions->render(fn (GameNotVisibleException $exception, Request $request) => Inertia::render('NotAPlayer', [
            'outcome' => $exception->outcome->value,
        ])->toResponse($request)->setStatusCode($exception->getStatusCode()));
    })->create();
