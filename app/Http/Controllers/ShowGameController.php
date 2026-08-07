<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Games\GameRepresentation;
use App\Games\GameSnapshot;
use App\Http\Middleware\ResolveActingPlayer;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * `GET /games/{game}` — the game page, and the target the Web_Client polls
 * (Req 8.1, 8.3, 8.4).
 *
 * IT TYPE-HINTS `Request` AND NOT `App\Models\Game`, WHICH IS THE WHOLE POINT.
 * Implicit route-model binding is driven by a controller's signature, and
 * `SubstituteBindings` runs in the `web` group — before route middleware — so a
 * `Game` parameter here would have the framework answer its own 404 for any id with
 * no row, collapsing rows 3, 4, 6 and 7 of the visibility table into one status and
 * destroying the `game_expired` distinction Requirement 13.6 requires. It would fail
 * quietly, because 404 is the right answer for two of those four rows.
 * `ResolveActingPlayer::resolved()` supplies what a binding would have, plus the
 * acting Mark — the fact Requirement 3.2 says must come from the Player_Token and
 * nothing else. `ResolveActingPlayerTest` pins the hazard with a route that
 * demonstrates it.
 *
 * THERE IS NO AUTHORISATION CHECK IN THIS METHOD, and its absence is not an
 * oversight. `acting.player` has already run and thrown `GameNotVisibleException` for
 * every refusal, so reaching the first line of `__invoke()` IS the authorisation
 * answer (Req 3.9). A second check here could only disagree with the first.
 *
 * ONE PROP, NAMED `game`, BECAUSE POLLING NAMES IT. `useGamePolling` (task 6.4)
 * issues Inertia partial reloads with `only: ['game']`, so the prop key is part of
 * the client contract and not a local choice. Everything the page needs is inside
 * it: `GameRepresentation` is the only serialiser, and it is handed the snapshot and
 * the Mark from the token, never a payload.
 *
 * `GameSnapshot::of()` MAY THROW, and is allowed to. `CorruptMoveListException` for a
 * persisted Move_List the Rules_Engine rejects is unreachable by Requirement 11.6, so
 * it is corruption rather than a user error and the design maps it to a 500 plus an
 * invariant-violation record (task 10.x adds the record). It is deliberately not
 * caught and turned into an outcome a player could be shown.
 */
final class ShowGameController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $resolved = ResolveActingPlayer::resolved($request);

        return Inertia::render('Game', [
            'game' => GameRepresentation::of(
                GameSnapshot::of($resolved->game),
                $resolved->mark,
            ),
        ]);
    }
}
