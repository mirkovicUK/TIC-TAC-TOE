<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Games\CreateGame;
use Illuminate\Http\RedirectResponse;

/**
 * `POST /games` — create a Game and send the Creator to it.
 *
 * No request body, so nothing is read off the request. The X Player_Token is in the
 * session by the time `handle()` returns, which is what makes the redirect below
 * resolve to row 1 of the visibility table rather than to `not_authorised`.
 *
 * 303, not 302: Inertia expects a state-changing visit to answer with a redirect the
 * browser follows as a GET, and the framework rewrites 302 to 303 for PUT, PATCH and
 * DELETE only, so a POST has to say it itself.
 *
 * The Join_Code is not in this response. Requirement 1.6 is served on the game page
 * this redirects to, from `props.game.joinCode` and `joinUrl`; flashing it here as
 * well would be a second source for one fact, and one that would disagree with the
 * prop the moment a second Player joined.
 */
final class CreateGameController extends Controller
{
    public function __invoke(CreateGame $createGame): RedirectResponse
    {
        $game = $createGame->handle();

        // A string id, not the model: `route()` would call `getRouteKey()` on a
        // `Game` and the two agree today, but passing the row is the first step
        // towards a binding on this parameter, which the whole visibility table
        // depends on not existing.
        return redirect()->route('games.show', ['game' => $game->id], 303);
    }
}
