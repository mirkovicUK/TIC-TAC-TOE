<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Games\CreateGame;
use Illuminate\Http\RedirectResponse;

/**
 * `POST /games` — create a Game and send the Creator to it.
 *
 * NO REQUEST BODY (the design's request-shapes table: empty), so nothing is read
 * off the request and there is nothing to validate. The X Player_Token is in the
 * session by the time `handle()` returns — `CreateGame` put it there through
 * `PlayerTokens` — which is what makes the redirect below resolve to row 1 of the
 * visibility table rather than to `not_authorised`.
 *
 * 303, NOT 302. Inertia's protocol expects a state-changing visit to answer with a
 * redirect the browser follows as a GET; 303 says that explicitly rather than
 * relying on how a client treats a 302 after a POST. The framework rewrites 302 to
 * 303 for PUT, PATCH and DELETE only, so a POST has to say it itself.
 *
 * THE JOIN_CODE IS NOT IN THIS RESPONSE. Requirement 1.6 asks the Web_Client to
 * display the Join_Code and a Join_Link when a Game is created, and it does — on
 * the game page this redirects to, from `props.game.joinCode` and `joinUrl`, which
 * `GameRepresentation` produces while the Game is `waiting_for_opponent`. Flashing
 * the code here as well would be a second source for one fact, and one that would
 * disagree with the prop the moment a second Player joined.
 */
final class CreateGameController extends Controller
{
    public function __invoke(CreateGame $createGame): RedirectResponse
    {
        $game = $createGame->handle();

        // The id is passed as a STRING, not as the model: `route()` would call
        // `getRouteKey()` on a `Game` and the two happen to agree today, but
        // passing the row would be the first step towards a binding on this
        // parameter, which the whole visibility table depends on not existing.
        return redirect()->route('games.show', ['game' => $game->id], 303);
    }
}
