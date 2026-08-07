<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveActingPlayer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

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
    })->create();
