<?php

use App\Http\Controllers\CreateGameController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\JoinFormController;
use App\Http\Controllers\JoinGameController;
use App\Http\Controllers\ShowGameController;
use Illuminate\Support\Facades\Route;

/*
 * The five routes of task 5.6, in the order the design's HTTP-surface table lists
 * them. `POST /games/{game}/moves` (task 6.2), `POST /games/{game}/rematch`
 * (task 7.x) and the Health_Endpoint (task 10.x) are deliberately absent.
 *
 * NO `throttle:` MIDDLEWARE IS ATTACHED YET, AND THAT IS A DECISION RATHER THAN
 * AN OMISSION. The design's table puts `throttle:create-game` on `POST /games`,
 * `throttle:join` on `POST /join` and `throttle:state` on `GET /games/{game}`.
 * Those are NAMED limiters, defined with `RateLimiter::for()` at task 10.x, and
 * `ThrottleRequests` resolves the name at REQUEST time: a route referencing a
 * limiter nobody has defined throws `RuntimeException: Rate limiter [join] is not
 * defined.` on the first request through it. Attaching them now would therefore
 * make every route in this file 500 until task 10.x runs — trading a missing
 * control for a broken application — and defining the limiters here would
 * pre-empt that task's real content, which is the Rate_Limit_Subject (session
 * where one exists, IP otherwise, keyed on a hash of the session id) and the four
 * thresholds. Task 10.x adds `->middleware('throttle:...')` to the three routes
 * below at the moment the limiters exist.
 *
 * ROUTE-MODEL BINDING IS KEPT AWAY FROM `{game}`. No `Route::model()` and no
 * `Route::bind()` for that name appears here or anywhere else, and
 * `ShowGameController` does not type-hint `App\Models\Game` — it reads the acting
 * player from `ResolveActingPlayer::resolved($request)`. `SubstituteBindings`
 * lives in the `web` group and group middleware runs before route middleware, so
 * a bound model would answer the framework's own 404 for any id with no row and
 * collapse four rows of the visibility table, taking Requirement 13.6's
 * `game_expired` distinction with it. `ResolveActingPlayerTest` pins that hazard
 * with a failing route rather than with this comment.
 */

Route::get('/', HomeController::class)->name('home');

Route::post('/games', CreateGameController::class)->name('games.store');

/*
 * The Join_Link target, and the one route whose PATH AND NAME ARE BOTH LOAD-BEARING.
 * `GameRepresentation::joinUrlFor()` builds `props.game.joinUrl` from
 * `route('join', ...)`, and `GameRepresentationTest` asserts that URL equals
 * `url('/join/10ABC-DEFGH')`, so moving this path or renaming this route breaks a
 * Join_Link a player has already pasted into a message — which is why the
 * assertion is on the URL rather than on the route name.
 *
 * The parameter is optional because `GET /join` with no code is a real page: it is
 * the manual-entry form, and it is where every join rejection lands (303 with the
 * outcome flashed).
 */
Route::get('/join/{join_code?}', JoinFormController::class)->name('join');

Route::post('/join', JoinGameController::class)->name('join.store');

Route::get('/games/{game}', ShowGameController::class)
    ->middleware('acting.player')
    ->name('games.show');
