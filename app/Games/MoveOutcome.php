<?php

declare(strict_types=1);

namespace App\Games;

/**
 * The five ways `SubmitMove` can refuse a Move from an authorised Player,
 * spelled exactly as the design's outcome table spells them.
 *
 * LIKE `JoinOutcome` AND `VisibilityOutcome`, THIS ENUM CARRIES NO DATA — but
 * *not* for the reason those two carry none, and the difference is the one thing
 * worth reading carefully here.
 *
 * `VisibilityOutcome` is fieldless because Requirement 3.10 forbids the response
 * from disclosing any game state at all: a refused caller is not a Player, so a
 * rejection that could carry a `Game` would be a rejection that could leak one.
 * That argument does not apply here. Every caller who reaches these five outcomes
 * has already passed `GameResolver` (Req 3.9) and is a Player of the Game, and
 * Requirement 5.5 together with the design's outcome table require the *opposite*
 * of concealment: a rejection of an authorised Player's action returns the
 * outcome **together with** the current Game_State, Move_List and Version_Counter
 * (Req 5.4, 5.5). So the shape cannot be justified by "a rejection must not carry
 * state". It has to be justified by where the state comes from.
 *
 * THE STATE THAT MUST ACCOMPANY A REJECTION IS NOT STATE `SubmitMove` HOLDS, AND
 * THIS IS SHARPEST FOR `Conflict`. Requirement 5.4 asks for the *current*
 * Game_State, Move_List and Version_Counter — and `conflict` means, by
 * definition, that another Move landed after the snapshot in hand was read. So
 * `$observed` is precisely the state that is now wrong: the Move_List missing the
 * Move that beat this one, and the Version_Counter from before it. A rejection
 * carrying `$observed` would satisfy the letter of "the outcome plus a
 * representation" while rendering the stale board — worse than carrying nothing,
 * because the caller could not tell it was stale.
 *
 * Obtaining the current state would require a re-read, and `SubmitMove`'s
 * invariant forbids one: it is a pure function of `($observed, $actingMark,
 * $cellIndex)` and issues no `SELECT`. Those two facts are not in tension; they
 * are the same decision seen from two sides, and together they settle the shape.
 * The current state is delivered by the transport instead: the design puts every
 * one of these five outcomes at "303 → game page with the outcome flashed", and
 * the GET that follows the redirect builds a *fresh* `GameSnapshot` and returns
 * the full representation through `GameRepresentation` (task 6.2). The redirect is
 * therefore not an implementation detail of the response — it is the mechanism
 * that makes Requirement 5.5 true, and it is what allows the rejection value
 * itself to stay fieldless.
 *
 * The shape it deliberately is not: one result object with an outcome plus a
 * nullable `?GameSnapshot`. Every rejection would then carry a snapshot that is
 * stale on the one path where staleness is the entire meaning of the outcome, and
 * a caller doing the obvious thing — serialising the snapshot it was handed —
 * would show the player a board that no longer exists.
 *
 * `SubmitMove::handle()` therefore returns `MoveAccepted|MoveOutcome`, a genuine
 * union with no common supertype and no shared fields, so a caller must narrow
 * with `instanceof` before it can read anything and static analysis rejects the
 * code that forgets to. The design names that union `MoveResult`; PHP has no
 * union type aliases, so the name lives in this docblock and in the signature's
 * comment rather than in a class — a marker interface implemented by both halves
 * would give a rejection a supertype it does not need and would let a caller hold
 * a `MoveResult` it never narrowed.
 *
 * THE HTTP STATUS IS DELIBERATELY NOT HERE, exactly as it is not on the other two
 * outcome enums. Distinctness is carried by the value; the 303 is task 6.2's.
 * Nothing in `App\Games` knows what an HTTP status is.
 *
 * WHAT IS NOT IN THIS ENUM, and must not be added: `not_authorised`, which is
 * `GameResolver`'s and settled before `SubmitMove` is called at all (Req 3.9);
 * `rate_limited`, which is framework middleware; and any case for a corrupt
 * persisted Move_List, which is `CorruptMoveListException` and a 500 rather than
 * an outcome a player can be shown.
 *
 * Task 12.1 asserts all eleven rejection outcomes of the application are pairwise
 * distinct (Property 16); five of them are these, and they are distinct from one
 * another by being distinct cases of one enum.
 */
enum MoveOutcome: string
{
    /**
     * The Game is still `waiting_for_opponent`, so there is no second Player and
     * no turn to take (Req 4.5). Distinct from `not_your_turn`: the Mark_To_Move
     * on an empty Move_List is `X`, so the Creator moving into a waiting Game
     * would otherwise pass the turn guard.
     */
    case GameNotStarted = 'game_not_started';

    /** The Game is in a Terminal_State — `won` or `drawn` (Req 4.6). */
    case GameEnded = 'game_ended';

    /**
     * The Mark bound to the presented Player_Token is not the Mark_To_Move
     * (Req 3.5). Distinct from `not_authorised`, which is what a caller who is
     * not a Player of the Game receives (Req 3.3).
     */
    case NotYourTurn = 'not_your_turn';

    /**
     * The target Cell is occupied, outside 0..8, or not an integer (Req 4.3,
     * 4.4). One value for all three, and deliberately not a Laravel validation
     * payload: the design keeps one vocabulary for one condition.
     */
    case InvalidMove = 'invalid_move';

    /**
     * Another Move was recorded at the target Sequence_Index — or in the target
     * Cell — after the snapshot this request observed was read (Req 5.4). Raised
     * by a unique-constraint violation on `moves`, never by a check beforehand.
     */
    case Conflict = 'conflict';
}
