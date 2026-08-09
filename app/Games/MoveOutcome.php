<?php

declare(strict_types=1);

namespace App\Games;

/**
 * The five ways `SubmitMove` can refuse a Move from an authorised Player,
 * spelled as the design's outcome table spells them.
 *
 * This enum is fieldless, but not for `VisibilityOutcome`'s reason. Everyone
 * reaching these outcomes is already a Player (Req 3.9), and Requirement 5.4/5.5
 * require a rejection to be accompanied by the *current* Game_State, Move_List and
 * Version_Counter. The point is that `SubmitMove` does not hold that state:
 * `conflict` means another Move landed after `$observed` was read, so `$observed`
 * is exactly the state that is now wrong, and `SubmitMove` may not re-read (it is
 * a pure function of its arguments). The current state is delivered by the
 * transport — every one of these outcomes is a 303 to the game page, and the GET
 * that follows builds a fresh `GameSnapshot` and serialises it through
 * `GameRepresentation`.
 *
 * So do not fold this into a result object carrying an outcome plus a nullable
 * `?GameSnapshot`: a caller serialising the snapshot it was handed would render a
 * board that no longer exists, on the one path where staleness is the whole
 * meaning of the outcome. `SubmitMove::handle()` returns `MoveAccepted|MoveOutcome`
 * instead — the design's `MoveResult`, which has no PHP name because there are no
 * union aliases — so a caller must narrow with `instanceof` and static analysis
 * catches the one that forgets.
 *
 * Three rejections a caller might expect here are elsewhere: `not_authorised`
 * (`GameResolver`'s, Req 3.9), `rate_limited` (middleware) and a corrupt persisted
 * Move_List (`CorruptMoveListException`, a 500). No HTTP status either — see
 * `VisibilityOutcome`.
 */
enum MoveOutcome: string
{
    /**
     * The Game is still `waiting_for_opponent` (Req 4.5). Needed separately from
     * `not_your_turn` because Mark_To_Move on an empty Move_List is `X`, so the
     * Creator moving into a waiting Game would pass the turn guard.
     */
    case GameNotStarted = 'game_not_started';

    /** The Game is in a Terminal_State — `won` or `drawn` (Req 4.6). */
    case GameEnded = 'game_ended';

    /**
     * The Mark bound to the presented Player_Token is not the Mark_To_Move
     * (Req 3.5). A caller who is not a Player at all gets `not_authorised`
     * (Req 3.3).
     */
    case NotYourTurn = 'not_your_turn';

    /**
     * The target Cell is occupied, outside 0..8, or not an integer (Req 4.3, 4.4).
     * One value for all three, and not a Laravel validation payload: one
     * vocabulary for one condition.
     */
    case InvalidMove = 'invalid_move';

    /**
     * Another Move was recorded at the target Sequence_Index — or in the target
     * Cell — after the snapshot this request observed was read (Req 5.4). Raised
     * by a unique-constraint violation on `moves`, never by a check beforehand.
     */
    case Conflict = 'conflict';
}
