<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Games\JoinGame;
use App\Games\JoinOutcome;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * `POST /join` — claim the O slot of the Game a submitted Join_Code names, or land
 * back on the join form with the reason (Req 2.1–2.3).
 *
 * `join_code` is read with `input()` and handed over uncast, for the reason
 * `SubmitMoveController` gives about `cell_index`: `JoinGame::handle()` takes
 * `mixed` so that unparseable, wrong-length and simply unused codes all produce the
 * one `not_recognised` outcome (Req 2.2). A 422 or a `TypeError` for a body like
 * `{"join_code": ["x"]}` would be a free oracle over the code space.
 *
 * Both rejections are a 303 back to `/join` with `outcome` flashed, not a 4xx: the
 * caller is not a Player of the Game the code named, so Requirement 3.10 leaves
 * nothing about it to render. `JoinOutcome` is fieldless, so only `$result->value`
 * travels, and `game_full` and `not_recognised` differ by that value rather than by
 * status.
 *
 * There is one success branch rather than two because Requirements 2.4 and 2.5
 * short-circuit: the Creator pasting their own code lands on their own game page as
 * X, with the token they already held.
 */
final class JoinGameController extends Controller
{
    public function __invoke(Request $request, JoinGame $joinGame): RedirectResponse
    {
        $result = $joinGame->handle($request->input('join_code'));

        if ($result instanceof JoinOutcome) {
            return redirect()
                ->route('join', [], 303)
                ->with('outcome', $result->value);
        }

        return redirect()->route('games.show', ['game' => $result->game->id], 303);
    }
}
