<?php

declare(strict_types=1);

namespace App\Games;

/**
 * The two ways `JoinGame` can refuse a submitted Join_Code: `game_full` and
 * `not_recognised`, spelled exactly as the design's outcome table spells them.
 *
 * LIKE `VisibilityOutcome`, THIS ENUM CARRIES NO DATA, AND FOR THE SAME REASON.
 * A caller submitting a Join_Code is, by definition, not yet a Player of the Game
 * that code names — so on both of these paths Requirement 3.10 excludes the
 * Board, the Move_List, the Game_State and the Mark_To_Move from the response,
 * and the design's outcome table puts both rejections at "the caller is not a
 * player, so nothing about the game is disclosed". An enum case has no `game`
 * property to read and nothing for a serialiser to walk, so a controller
 * *cannot* disclose game state from a rejection, whether or not its author
 * remembered not to. `JoinGame::handle()` therefore returns
 * `ResolvedPlayer|JoinOutcome`, a genuine union with no common supertype and no
 * shared fields: a caller must narrow with `instanceof` before it can read
 * anything at all, and static analysis rejects the code that forgets to.
 *
 * WHY THIS IS A SECOND ENUM RATHER THAN TWO MORE CASES ON `VisibilityOutcome`.
 * The obvious economy — one `Outcome` enum for the whole service — would make
 * `GameResolver`'s return type able to express `game_full`, which is not one of
 * the seven rows of its visibility table and which nothing in that class can
 * produce. That table is the entirety of what `GameResolver` promises, and a
 * return type wider than the promise is a return type a caller has to handle
 * cases from that can never arrive. The two enums are separate because the two
 * decisions are separate: one answers "may this session see this Game", the
 * other answers "may this session become the O Player of this Game".
 *
 * `NotRecognised` DELIBERATELY SHARES ITS BACKING VALUE WITH
 * `VisibilityOutcome::NotRecognised`, and that is not an accident to be tidied
 * away. The design's outcome table lists `not_recognised` ONCE, raised by
 * Requirement 2.2 for a Join_Code and by Requirement 13.8 for a Game_Id, with
 * one row and one client-facing message; Property 16 asks for the eleven
 * *conditions* to produce pairwise distinct outcome *values*, and these two
 * conditions share one value by design rather than being a twelfth. The
 * transport differs — a Join_Code rejection is a 303 back to `/join`, a Game_Id
 * rejection is a 404 page — but that difference belongs to the transport and is
 * settled by which type raised the outcome, not by the string.
 *
 * THE HTTP STATUS IS DELIBERATELY NOT HERE, exactly as it is not on
 * `VisibilityOutcome`: distinctness is carried by the value, and the 303 to
 * `/join` that both of these get is task 5.6's decision to make. Nothing in
 * `App\Games` knows what an HTTP status is.
 */
enum JoinOutcome: string
{
    /**
     * The submitted code matched a Game that no longer has a free O slot, and the
     * requesting session holds no Player_Token for it (Req 2.3) — including the
     * loser of a concurrent join, which reaches this by the guarded UPDATE
     * affecting zero rows (Req 2.7).
     */
    case GameFull = 'game_full';

    /**
     * The submitted code matched no Game — because no row holds it, or because the
     * string could not be a Join_Code at all (Req 2.2). One value for both, so a
     * caller learns nothing about the code space.
     */
    case NotRecognised = 'not_recognised';
}
