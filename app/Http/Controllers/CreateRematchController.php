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
 * No request body, so nothing is read off the request. Both facts this action needs
 * come from `ResolveActingPlayer::resolved($request)`: the preceding Game row and
 * the Mark the requesting session's Player_Token is bound to on it, which is the
 * whole of the identity continuity Requirement 7.7 permits.
 *
 * A POST and not a link, which is the client-side consequence of minting tokens per
 * request rather than at creation. Both controls
 * task 7.2 renders post here, because a plain link to the Rematch's URL would land
 * the second Player on a Game their session holds no Player_Token for — the token
 * is minted BY this request. Hence idempotent rather than create-only.
 *
 * No authorisation check, and `Request` in the signature rather than
 * `App\Models\Game` — see `ResolveActingPlayer` for both. Reaching `__invoke()` is
 * the authorisation answer, settled before the preceding Game's `state` was looked
 * at (Req 3.9), which is Requirement 7.11's `not_authorised` in full with no code
 * here. A `Game` type-hint would 404 before the visibility table could speak.
 *
 * Both answers are a 303, and they go to different Games. Accepted goes to
 * `GET /games/{rematch}` with nothing flashed — the session holds a token for it by
 * then — and is the only redirect in the application that leaves the Game the
 * request named. Rejected goes to the preceding Game with `outcome` flashed,
 * because `invalid_state` means there is no Rematch to redirect to; the following
 * GET supplies the full representation from a fresh read.
 *
 * A 4xx is not used for the rejection, for `SubmitMoveController`'s reasons.
 *
 * The raw Player_Token is nowhere in this file and could not be: `CreateRematch`
 * returns a `ResolvedPlayer`, and the token reached the session inside the service
 * (Req 8.7).
 */
final class CreateRematchController extends Controller
{
    public function __invoke(Request $request, CreateRematch $createRematch): RedirectResponse
    {
        $resolved = ResolveActingPlayer::resolved($request);

        $result = $createRematch->handle($resolved->game, $resolved->mark);

        // A string id, not the model, for `CreateGameController`'s reason: passing
        // the row is the first step towards a binding on this parameter.
        if ($result instanceof RematchOutcome) {
            return redirect()
                ->route('games.show', ['game' => $resolved->game->id], 303)
                ->with('outcome', $result->value);
        }

        return redirect()->route('games.show', ['game' => $result->game->id], 303);
    }
}
