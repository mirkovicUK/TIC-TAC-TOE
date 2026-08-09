<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Games\GameSnapshot;
use App\Games\MoveOutcome;
use App\Games\SubmitMove;
use App\Http\Middleware\ResolveActingPlayer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * `POST /games/{game}/moves` — submit one Move (Req 4.4, 5.4, 5.5).
 *
 * `cell_index` is read with `input()` and handed over uncast, and that must not be
 * tidied up. `SubmitMove::handle()` takes `mixed $cellIndex` and answers
 * `invalid_move` for anything that is not a free Cell in 0..8 — one vocabulary for
 * one condition (Req 4.3, 4.4), where a Form Request would answer with a 422 that
 * is not in the design's outcome table. A cast is worse still because it succeeds:
 * `->integer()` turns `'banana'` into `0`, a perfectly legal Cell, so a malformed
 * payload becomes a Move in the top-left corner. `SubmitMoveTest` asserts this over HTTP
 * with `'4'`, `'banana'` and an array, the only place the absence of a cast is
 * falsifiable.
 *
 * Any `mark` in the payload is ignored; `cell_index` is the only key touched. The
 * acting Mark comes from the resolved player, which `GameResolver` derived from the
 * session's Player_Token and nothing else (Req 3.2, 3.6).
 *
 * No authorisation check, and `Request` in the signature rather than
 * `App\Models\Game` — see `ResolveActingPlayer` for both. Reaching `__invoke()` is
 * the authorisation answer, settled before `cell_index` was looked at (Req 3.9),
 * and a `Game` type-hint would 404 before the visibility table could speak.
 *
 * Both answers are a 303 to the game page; the difference is the flash. Accepted
 * flashes nothing, and the following GET reports the new state through
 * `GameRepresentation` (Req 8.3) — which is also why `MoveAccepted` is not read
 * here; `SubmitMove` passes its fields to `GameEventLogger` before returning it
 * (Req 10.4), and nothing on this side of the boundary needs them. Rejected
 * flashes `outcome`, and the redirect is what makes Requirements 5.4 and 5.5
 * true: the fresh `GameSnapshot` pairs the fieldless outcome with the *current*
 * state, which for `conflict` is precisely the state this request did not observe.
 *
 * A 4xx is not used for a rejection: Inertia expects a state-changing visit to
 * answer with a redirect, and 409 is reserved by its asset-version mechanism. The
 * distinctness is carried by the flashed value, which `HandleInertiaRequests`
 * shares as the `outcome` prop.
 *
 * `GameSnapshot::of()` may throw `CorruptMoveListException` and is allowed to: a
 * persisted Move_List the Rules_Engine rejects is unreachable by Requirement 11.6,
 * so it is corruption rather than a user error and the default 500 is the design's
 * mapping. Catching it would report a corrupt row as an ordinary rejection.
 */
final class SubmitMoveController extends Controller
{
    public function __invoke(Request $request, SubmitMove $submitMove): RedirectResponse
    {
        $resolved = ResolveActingPlayer::resolved($request);

        // One snapshot, read here and passed in: `SubmitMove` issues no `SELECT` of
        // its own, so two concurrent requests derive the same Sequence_Index and the
        // unique index on `(game_id, sequence_index)` settles which wins (Req 5.3).
        $result = $submitMove->handle(
            GameSnapshot::of($resolved->game),
            $resolved->mark,
            $request->input('cell_index'),
        );

        // A string id, not the model, for `CreateGameController`'s reason: passing
        // the row is the first step towards a binding on this parameter.
        $redirect = redirect()->route('games.show', ['game' => $resolved->game->id], 303);

        return $result instanceof MoveOutcome
            ? $redirect->with('outcome', $result->value)
            : $redirect;
    }
}
