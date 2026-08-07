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
 * `cell_index` IS READ WITH `input()` AND HANDED OVER UNTOUCHED. No Form Request,
 * no `$request->integer('cell_index')`, no `(int)` cast, no `validate()`. That is
 * the whole substance of this controller and the one thing a future edit must not
 * "tidy up":
 *
 *   - `SubmitMove::handle()` takes `mixed $cellIndex` and answers `invalid_move`
 *     for anything that is not an integer in 0..8 or is already occupied — one
 *     vocabulary for one condition (Req 4.3, 4.4). A Form Request would answer a
 *     bad Cell with a 422 validation payload instead, which is a second vocabulary
 *     for the same condition and not an outcome in the design's table at all.
 *   - `->integer()` would be worse than validation, because it succeeds: it casts
 *     `'banana'` to `0`, a perfectly legal Cell, turning a malformed payload into
 *     a Move in the top-left corner. `SubmitMove`'s `is_int()` check is strict for
 *     exactly this reason, and it can only do its job if it is handed the decoded
 *     value.
 *   - `input()` returns the decoded body value as it arrived and is typed `mixed`,
 *     so `'4'`, `'banana'`, `['4']` and `null` all reach the guard and all come
 *     back `invalid_move`. Inertia posts JSON, so a Cell clicked in `Board.tsx`
 *     arrives as an `int` (task 6.3).
 *
 * Task 6.6 asserts this through the HTTP surface with `'4'`, `'banana'` and an
 * array, which is the only place the absence of a cast is falsifiable — inside
 * `SubmitMove` the parameter is already `mixed`, so nothing there can tell whether
 * its caller cast the value first.
 *
 * ANY `mark` IN THE PAYLOAD IS IGNORED, AND THERE IS NOWHERE HERE FOR ONE TO BE
 * READ (Req 3.2, 3.6). The acting Mark is `ResolveActingPlayer::resolved($request)
 * ->mark`, which `GameResolver` derived from the Player_Token in the session and
 * from nothing else. `cell_index` is the only key this controller touches.
 *
 * NO AUTHORISATION CHECK, exactly as in `ShowGameController`. `acting.player` has
 * already run and thrown `GameNotVisibleException` for every refusal, so reaching
 * the first line of `__invoke()` IS the authorisation answer, and it was settled
 * before `cell_index` was so much as looked at (Req 3.9). It follows that no
 * unauthorised caller can learn anything about a Cell from this route.
 *
 * IT TYPE-HINTS `Request`, NOT `App\Models\Game`. `SubstituteBindings` runs in the
 * `web` group, before route middleware, so a `Game` parameter here would have the
 * framework answer its own 404 for any id with no row and collapse four rows of
 * the visibility table. The Game comes from the resolved player instead.
 *
 * BOTH ANSWERS ARE A 303 TO THE GAME PAGE, and the difference is the flash.
 *
 *   - Accepted: 303 → `GET /games/{game}` with nothing flashed. The design's
 *     sequence diagram says so, and the GET that follows reads the committed row
 *     and reports the new Game_State, Move_List and Version_Counter through
 *     `GameRepresentation` (Req 8.3). That is also why `MoveAccepted` is not read
 *     here — its four fields describe a write the next GET re-reads anyway, and
 *     they exist for `GameEventLogger` (task 10.x), not for this response.
 *   - Rejected: 303 → the same page with `outcome` flashed. The redirect is the
 *     mechanism that makes Requirements 5.4 and 5.5 true rather than a detail of
 *     the response: `MoveOutcome` is fieldless, and for `conflict` the snapshot
 *     this request observed is precisely the state that is now stale — the
 *     Move_List missing the Move that beat it. The following GET builds a FRESH
 *     `GameSnapshot`, so the outcome arrives together with the *current* state,
 *     which is what those two criteria ask for.
 *
 * A 4xx is not used for a rejection: Inertia expects a state-changing visit to
 * answer with a redirect, and 409 is reserved by its asset-version mechanism. The
 * outcome's distinctness is carried by the flashed value, which
 * `HandleInertiaRequests` shares as the `outcome` prop — the same mechanism the
 * join-form rejections use.
 *
 * `GameSnapshot::of()` MAY THROW AND IS ALLOWED TO. `CorruptMoveListException` for
 * a persisted Move_List the Rules_Engine rejects is unreachable by Requirement
 * 11.6, so it is corruption rather than a user error: the framework's default
 * 500 is the design's mapping and needs no code here (task 6.1 recorded this).
 * Catching it would report a corrupt row to a Player as an ordinary rejection.
 */
final class SubmitMoveController extends Controller
{
    public function __invoke(Request $request, SubmitMove $submitMove): RedirectResponse
    {
        $resolved = ResolveActingPlayer::resolved($request);

        // ONE SNAPSHOT, READ HERE, PASSED IN. `SubmitMove` issues no `SELECT` of
        // its own — that is its purity invariant — so this is the read its guards
        // see, and the Sequence_Index it derives is `count($observed->moveList)`.
        // Two concurrent requests therefore derive the same value and the unique
        // index on `(game_id, sequence_index)` settles which one wins (Req 5.3).
        $result = $submitMove->handle(
            GameSnapshot::of($resolved->game),
            $resolved->mark,
            $request->input('cell_index'),
        );

        // The id is passed as a STRING rather than the model, for the reason
        // `CreateGameController` gives: passing the row is the first step towards a
        // binding on this parameter, which the visibility table depends on not
        // existing.
        $redirect = redirect()->route('games.show', ['game' => $resolved->game->id], 303);

        return $result instanceof MoveOutcome
            ? $redirect->with('outcome', $result->value)
            : $redirect;
    }
}
