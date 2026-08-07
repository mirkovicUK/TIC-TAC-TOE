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
 * The five routes of task 5.6, task 6.2's move route and task 7.1's rematch route,
 * in the order the design's HTTP-surface table lists them. Only the
 * Health_Endpoint (task 10.1) is still deliberately absent.
 *
 * THE FOUR `throttle:` MIDDLEWARE ARE NOW ATTACHED (task 9.4), AND THE NAMES ARE
 * RESOLVED AT REQUEST TIME RATHER THAN HERE. `throttle:create-game` on
 * `POST /games`, `throttle:join` on `POST /join`, `throttle:state` on
 * `GET /games/{game}` and `throttle:move` on `POST /games/{game}/moves`, which is
 * the design's limiter table in full. Each names a limiter defined with
 * `RateLimiter::for()` in `App\Providers\AppServiceProvider::boot()`, where the
 * thresholds and the Rate_Limit_Subject live; nothing about either is visible from
 * this file, and that is the shape of the framework's API rather than a choice.
 *
 * THE COUPLING IS UNCHECKED UNTIL THE REQUEST ARRIVES, so keep the two files in
 * step. `ThrottleRequests::handle()` looks the name up in the `RateLimiter`
 * singleton and, finding nothing, throws `MissingRateLimiterException: Rate
 * limiter [join] is not defined.` — a 500 on the first request through the route,
 * with no boot-time or route-caching warning of any kind. Renaming a limiter in
 * the provider, or adding a `throttle:` here for a limiter that does not exist,
 * breaks the route silently until something exercises it. Task 9.5's
 * `MiddlewareConfigurationTest` and task 9.6's `RateLimitTest` are what notice.
 *
 * ORDER MATTERS ON THE TWO ROUTES THAT CARRY BOTH: `throttle:` is listed BEFORE
 * `acting.player`, as the design's table lists it. The framework's middleware
 * priority list places `ThrottleRequests` above `SubstituteBindings` but says
 * nothing about `ResolveActingPlayer`, so declaration order is what decides, and
 * throttle-first is the arrangement worth having — a flood is refused before it
 * costs a `GameResolver` query, and the 429 is reached without the request needing
 * to be about a Game that exists.
 *
 * `POST /games/{game}/rematch` CARRIES NO LIMITER, per the design's table. See the
 * note on that route below.
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

/*
 * `throttle:create-game` IS REQUIRED BY NO CRITERION AND IS DELIBERATE. Twenty
 * creations per Rate_Limit_Subject per minute, flagged as an addition in the
 * design's limiter table and in the provider that defines it. This is the cheapest
 * endpoint on the surface to abuse — it needs no prior Game, no established
 * session and no token, and it inserts a row — so it gets the same threshold as
 * `join` even though nothing demands one.
 */
Route::post('/games', CreateGameController::class)
    ->middleware('throttle:create-game')
    ->name('games.store');

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

/*
 * `throttle:join` — Requirement 10.6, twenty per Rate_Limit_Subject per minute.
 * The twentieth request passes and the twenty-first is refused; task 9.6 asserts
 * that boundary and nothing here restates it.
 */
Route::post('/join', JoinGameController::class)
    ->middleware('throttle:join')
    ->name('join.store');

/*
 * `throttle:state` — Requirement 10.8, one hundred and twenty per subject per
 * minute, which is four times the polling rate Requirement 8.1 demands. The margin
 * is deliberate and its limit is known: Rate_Limit_Subject is the Player_Session
 * while the polling rate is per Game, so one session polling four Games in four
 * tabs reaches the threshold. The design records that as accepted.
 */
Route::get('/games/{game}', ShowGameController::class)
    ->middleware(['throttle:state', 'acting.player'])
    ->name('games.show');

/*
 * Task 6.2 — submit a Move. Same `acting.player` middleware as the page above, so
 * authorisation is settled before `cell_index` is read at all (Req 3.9), and the
 * refusal is a thrown `GameNotVisibleException` that no later step can un-refuse.
 *
 * `throttle:move` IS THE ONE LIMITER NOT KEYED ON THE Rate_Limit_Subject.
 * Requirement 10.7 counts per PRESENTED Player_Token — sixty per minute — so the
 * limiter reads the token this session holds for this `{game}` and keys on its
 * hash. It runs BEFORE `acting.player`, which means it reads that token itself
 * rather than taking it from the resolved player; the session is available either
 * way, because `StartSession` is group middleware and sits above `ThrottleRequests`
 * in the framework's priority list. A request presenting no token for this Game
 * falls back to the subject, so the route is never unlimited.
 *
 * `SubmitMoveController` type-hints `Request` and not `App\Models\Game`, which is
 * what keeps route-model binding away from this second `{game}` route.
 */
Route::post('/games/{game}/moves', SubmitMoveController::class)
    ->middleware(['throttle:move', 'acting.player'])
    ->name('games.moves.store');

/*
 * Task 7.1 — create or enter the Rematch. Same `acting.player` middleware as the
 * two routes above, and here it carries the whole of Requirement 7.11: a session
 * holding no Player_Token for the PRECEDING Game is refused `not_authorised`
 * before `CreateRematch` is reached, so that criterion needs no code in the
 * service (Req 3.9).
 *
 * `{game}` IS THE PRECEDING GAME, NOT THE REMATCH — the Rematch has no id until
 * this request either finds or creates it, which is why the design's outcome table
 * routes the `invalid_state` rejection back to this same id while the accepted
 * path redirects to a different one.
 *
 * THE DESIGN'S LIMITER TABLE APPLIES NO NAMED LIMITER TO THIS ROUTE, and task 9.4
 * left it that way rather than inventing a fifth limiter the design does not name.
 * The endpoint is idempotent and converges on one row (Req 7.8), so a repeated POST
 * re-mints a token for a caller who already holds one and creates nothing. It is
 * also unreachable without a valid Player_Token for the preceding Game, so the
 * flood a limiter would stop is one an authorised player aims at their own row.
 *
 * `CreateRematchController` type-hints `Request` and not `App\Models\Game`, which
 * is what keeps route-model binding away from this third `{game}` route.
 */
Route::post('/games/{game}/rematch', CreateRematchController::class)
    ->middleware('acting.player')
    ->name('games.rematch.store');
