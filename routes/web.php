<?php

use App\Http\Controllers\CreateGameController;
use App\Http\Controllers\CreateRematchController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\JoinFormController;
use App\Http\Controllers\JoinGameController;
use App\Http\Controllers\ShowGameController;
use App\Http\Controllers\SubmitMoveController;
use Illuminate\Support\Facades\Route;

/*
 * The HTTP surface of tasks 5.6, 6.2 and 7.1, in the order the design's table
 * lists them. The Health_Endpoint is deliberately not among them: every route in this
 * file is registered inside `Route::middleware('web')->group()`, and `GET /health`
 * carries no middleware at all, so it is registered in the `then` callback of
 * `withRouting()` in `bootstrap/app.php` instead.
 *
 * No `{game}` route may bind the model: there is no `Route::model()` or
 * `Route::bind()` for that name, and no controller here type-hints
 * `App\Models\Game`. `SubstituteBindings` is `web` group middleware and group
 * middleware runs before route middleware, so a bound model would answer the
 * framework's own 404 for any id with no row, collapsing four rows of the
 * visibility table and taking Requirement 13.6's `game_expired` distinction with
 * it. `ResolveActingPlayerTest` pins that hazard.
 *
 * Each `throttle:` name is resolved at request time against a limiter defined
 * with `RateLimiter::for()` in `AppServiceProvider::boot()`, where the thresholds
 * and the Rate_Limit_Subject live. A name with no limiter behind it throws
 * `MissingRateLimiterException` — a 500 on the first request through the route,
 * with no boot-time or route-caching warning — so this file and the provider must
 * stay in step.
 *
 * Where a route carries both, `throttle:` is listed before `acting.player`
 * deliberately: declaration order is what decides here, and refusing a flood
 * before it costs a `GameResolver` query is the arrangement worth having.
 */

Route::get('/', HomeController::class)->name('home');

/*
 * `throttle:create-game` is required by no criterion and is deliberate. This is
 * the cheapest endpoint to abuse — no prior Game, session or token, and it inserts
 * a row — so it takes the same threshold as `join`.
 */
Route::post('/games', CreateGameController::class)
    ->middleware('throttle:create-game')
    ->name('games.store');

/*
 * The Join_Link target, and the one route whose PATH is load-bearing: a Join_Link
 * a player has already pasted into a message does not follow a rename, which is
 * why `GameRepresentationTest` asserts `props.game.joinUrl` equals
 * `url('/join/10ABC-DEFGH')` rather than asserting the route name.
 *
 * The parameter is optional because `GET /join` with no code is a real page — the
 * manual-entry form, and where every join rejection lands.
 */
Route::get('/join/{join_code?}', JoinFormController::class)->name('join');

Route::post('/join', JoinGameController::class)
    ->middleware('throttle:join')
    ->name('join.store');

Route::get('/games/{game}', ShowGameController::class)
    ->middleware(['throttle:state', 'acting.player'])
    ->name('games.show');

/*
 * `throttle:move` is the one limiter not keyed on the Rate_Limit_Subject:
 * Requirement 10.7 counts per presented Player_Token, so it reads the token this
 * session holds for this `{game}` and keys on its hash, falling back to the
 * subject when none is presented so the route is never unlimited. Running before
 * `acting.player` it reads that token itself; the session is available either way,
 * since `StartSession` outranks `ThrottleRequests` in the framework's priority
 * list.
 *
 * `acting.player` settles authorisation before `cell_index` is read at all
 * (Req 3.9).
 */
Route::post('/games/{game}/moves', SubmitMoveController::class)
    ->middleware(['throttle:move', 'acting.player'])
    ->name('games.moves.store');

/*
 * Task 7.1 — create or enter the Rematch. `{game}` is the PRECEDING Game, not the
 * Rematch, which has no id until this request either finds or creates it.
 * `acting.player` carries the whole of Requirement 7.11 here.
 *
 * The design's limiter table applies no limiter to this route and task 9.4 left it
 * that way. The endpoint is idempotent and converges on one row (Req 7.8), and it
 * is unreachable without a valid Player_Token for the preceding Game.
 */
Route::post('/games/{game}/rematch', CreateRematchController::class)
    ->middleware('acting.player')
    ->name('games.rematch.store');
