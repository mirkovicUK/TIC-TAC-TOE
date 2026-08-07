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
 * It type-hints `Request` and not `App\Models\Game`, which is the whole point:
 * `SubstituteBindings` runs in the `web` group before route middleware, so a `Game`
 * parameter here would 404 before the visibility table could distinguish
 * `game_expired` from `not_recognised`. See `ResolveActingPlayer` for the full
 * hazard and the test that pins it. `resolved()` supplies the row plus the acting
 * Mark, which Requirement 3.2 says must come from the Player_Token and nothing else.
 *
 * No authorisation check here: `acting.player` has already thrown
 * `GameNotVisibleException` for every refusal, so reaching `__invoke()` is the
 * authorisation answer (Req 3.9), and a second check could only disagree with it.
 *
 * One prop, named `game`, because `useGamePolling` (task 6.4) reloads with
 * `only: ['game']` — the key is part of the client contract, not a local choice.
 *
 * `GameSnapshot::of()` may throw `CorruptMoveListException` and is allowed to: a
 * persisted Move_List the Rules_Engine rejects is unreachable by Requirement 11.6,
 * so the design maps it to a 500 plus an invariant-violation record rather than to
 * an outcome a player is shown.
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
