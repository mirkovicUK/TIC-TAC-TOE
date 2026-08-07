<?php

declare(strict_types=1);

namespace App\Games;

/**
 * The three ways `GameResolver` can refuse to show a Game: the rejection half of
 * its seven-row visibility table.
 *
 * THIS ENUM CARRIES NO DATA, AND THAT IS THE WHOLE REASON IT IS THE REJECTION
 * TYPE. Requirement 3.10 excludes the Board, the Move_List, the Game_State and
 * the Mark_To_Move from the response to any request that presents no valid
 * Player_Token, and Property 7 restates it as "a response containing no Board, no
 * Move_List, no Game_State and no Mark_To_Move". A rejection shaped as an enum
 * case makes that structural rather than disciplined: there is no `game`
 * property to read, no `snapshot()` to call and nothing for a serialiser to walk,
 * so a controller *cannot* disclose game state from a rejection, whether or not
 * its author remembered not to. Contrast the shape this deliberately is not — an
 * outcome plus a nullable `?Game` on one object — where every rejection would
 * carry the row it refused to show and the guarantee would rest on every caller
 * checking the outcome before reading the field.
 *
 * `GameResolver::resolve()` therefore returns `ResolvedPlayer|VisibilityOutcome`,
 * a genuine union: the success and rejection shapes have no common supertype and
 * no fields in common, so a caller must narrow with `instanceof` before it can
 * read anything at all, and static analysis rejects the code that forgets to.
 *
 * TWO OF THE SEVEN ROWS ANSWER `NotRecognised` FOR DIFFERENT DATABASE STATES,
 * and the cases here are what makes that indistinguishable rather than merely
 * equal: rows 6 and 7 return this same case, so there is no second value a
 * caller could compare, log or render that would separate "no Game and a
 * tombstone" from "no Game and nothing". Likewise `NotAuthorised` is the single
 * value for all three failure modes of Requirement 9.6 — no token, unrecognised
 * token, token bound to another Game — which is what lets the Web_Client render
 * one message for all of them.
 *
 * THE HTTP STATUS IS DELIBERATELY NOT HERE. The design is explicit that
 * distinctness is carried by the *value* and that the status is how the transport
 * expresses it; 403, 404 and 410 therefore live in
 * `App\Http\Exceptions\GameNotVisibleException`, on the transport side of the
 * boundary, so that nothing in `App\Games` needs to know what an HTTP status is.
 *
 * The backing values are the outcome vocabulary spelled exactly as the design's
 * outcome table spells it. Task 12.1 asserts all eleven rejection outcomes of the
 * application are pairwise distinct (Property 16); three of them are these, and
 * they are distinct from one another by being distinct cases of one enum.
 */
enum VisibilityOutcome: string
{
    /**
     * Rows 2 and 5: a Game row exists and the request presents no Player_Token
     * bound to it (Req 3.3, 3.4, 3.10, 9.6).
     */
    case NotAuthorised = 'not_authorised';

    /**
     * Row 3: no Game row, an Expiry_Record, and a session presenting a token for
     * that Game_Id (Req 13.6, 13.7).
     */
    case GameExpired = 'game_expired';

    /**
     * Rows 4, 6 and 7: no Game row, and either no Expiry_Record or no token
     * presented (Req 13.8).
     */
    case NotRecognised = 'not_recognised';
}
