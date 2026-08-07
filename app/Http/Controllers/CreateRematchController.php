<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Games\CreateRematch;
use App\Games\RematchOutcome;
use App\Http\Middleware\ResolveActingPlayer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * `POST /games/{game}/rematch` — create or enter the Rematch of a finished Game
 * (Req 7.2–7.11, 7.15).
 *
 * NO REQUEST BODY (the design's request-shapes table: empty), so nothing is read
 * off the request and there is nothing to validate. Both facts this action needs
 * come from `ResolveActingPlayer::resolved($request)`: the preceding Game row and
 * the Mark the requesting session's Player_Token is bound to on it, which is the
 * whole of the identity continuity Requirement 7.7 permits.
 *
 * A POST AND NOT A LINK, WHICH IS THE WHOLE OF ADR-010'S CLIENT-SIDE CONSEQUENCE.
 * Both controls task 7.2 renders — "play again" while the Game is terminal, and
 * "go to the rematch" once `rematchGameId` is present — post here. A plain link to
 * the Rematch's URL would land the second Player on a Game for which their session
 * holds no Player_Token, and `acting.player` would refuse it with
 * `not_authorised`: the token is minted BY this request and cannot exist before
 * it. That is why the endpoint is idempotent rather than create-only.
 *
 * NO AUTHORISATION CHECK, exactly as in `SubmitMoveController`. `acting.player`
 * has already run and thrown `GameNotVisibleException` for every refusal, so
 * reaching the first line of `__invoke()` IS the authorisation answer, and it was
 * settled before the preceding Game's `state` was so much as looked at (Req 3.9).
 * That is Requirement 7.11's `not_authorised`, in full, with no code here: a
 * session holding no token for the preceding Game never arrives.
 *
 * IT TYPE-HINTS `Request`, NOT `App\Models\Game`. `SubstituteBindings` runs in the
 * `web` group, before route middleware, so a `Game` parameter here would have the
 * framework answer its own 404 for any id with no row and collapse four rows of
 * the visibility table. This is the third `{game}` route and the constraint is the
 * same on all three.
 *
 * BOTH ANSWERS ARE A 303, AND THEY REDIRECT TO DIFFERENT GAMES.
 *
 *   - Accepted: 303 → `GET /games/{rematch}` with nothing flashed. The session now
 *     holds a token for that Game — `CreateRematch` minted it — so the GET that
 *     follows resolves to row 1 of the visibility table and renders the new empty
 *     board. Note which id this is: the *Rematch's*, which is the only place in the
 *     application where a redirect leaves the Game the request named.
 *   - Rejected: 303 → `GET /games/{game}` — the *preceding* Game, the one the
 *     request named — with `outcome` flashed. `invalid_state` means no Rematch
 *     exists to redirect to, and the design's outcome table puts the outcome at
 *     "303 → game page" with the full representation, which the following GET
 *     supplies from a fresh read. The player sees their still-unfinished game and
 *     one line saying why nothing happened.
 *
 * A 4xx is not used for the rejection, for `SubmitMoveController`'s reasons:
 * Inertia expects a state-changing visit to answer with a redirect, and 409 is
 * reserved by its asset-version mechanism. The outcome's distinctness is carried by
 * the flashed value, which `HandleInertiaRequests` shares as the `outcome` prop.
 *
 * THE RAW PLAYER_TOKEN IS NOWHERE IN THIS FILE, and could not be: `CreateRematch`
 * returns a `ResolvedPlayer`, which carries the Game and the Mark and no
 * credential. The token reached the session inside the service (Req 8.7).
 */
final class CreateRematchController extends Controller
{
    public function __invoke(Request $request, CreateRematch $createRematch): RedirectResponse
    {
        $resolved = ResolveActingPlayer::resolved($request);

        $result = $createRematch->handle($resolved->game, $resolved->mark);

        // The id is passed as a STRING rather than the model, for the reason
        // `CreateGameController` gives: passing the row is the first step towards a
        // binding on this parameter, which the visibility table depends on not
        // existing.
        if ($result instanceof RematchOutcome) {
            return redirect()
                ->route('games.show', ['game' => $resolved->game->id], 303)
                ->with('outcome', $result->value);
        }

        return redirect()->route('games.show', ['game' => $result->game->id], 303);
    }
}
