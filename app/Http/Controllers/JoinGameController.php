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
 * `join_code` IS NOT VALIDATED HERE, AND MUST NOT BE. It is read with `input()`,
 * which returns `mixed`, and handed to `JoinGame::handle(mixed $submitted)` exactly
 * as the request carried it. That signature is deliberate: a Form Request or a
 * `$request->string()` cast would answer a body like `{"join_code": ["x"]}` with a
 * 422 validation payload or a `TypeError`, and Requirement 2.2 wants every code
 * that matches no Game — unparseable, wrong length, or simply unused — to produce
 * the one `not_recognised` outcome. A distinguishable "wrong shape" reply is a free
 * oracle over the code space.
 *
 * BOTH REJECTIONS ARE A 303 BACK TO `/join`, NOT A 4xx, and that is the design's
 * third transport family. The caller is not a Player of the Game the code named —
 * that is what the rejection means — so Requirement 3.10 excludes the Board, the
 * Move_List, the Game_State and the Mark_To_Move from the response, and there is
 * nothing to render on a page about that Game. `JoinOutcome` is a fieldless enum, so
 * there is no Game here to disclose even by accident: the only thing that travels is
 * `$result->value`, flashed, which `Join.tsx` renders as a message.
 *
 * `game_full` AND `not_recognised` SHARE THE TRANSPORT AND DIFFER IN THE VALUE.
 * The design's outcome table is explicit that distinctness is carried by the value
 * rather than by the status, so both are 303 and the flash tells them apart. Note
 * what is NOT distinguished: a `not_recognised` from an unparseable string and one
 * from a well formed code nobody holds are the same value, because `JoinGame`
 * already collapsed them.
 *
 * THE ACCEPTED PATH REDIRECTS TO THE GAME, and the session holds the token by then —
 * either the O token `JoinGame` just remembered, or, on the short-circuit of
 * Requirements 2.4 and 2.5, the token it already held. The Creator pasting their own
 * code therefore lands on their own game page with the Mark X, which is why this
 * controller has one success branch rather than two.
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
