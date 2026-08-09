<?php

declare(strict_types=1);

namespace App\Games;

/**
 * The three ways `GameResolver` can refuse to show a Game: the rejection half of
 * its seven-row visibility table.
 *
 * The cases carry no data, which is what makes Req 3.10 and Property 7 structural
 * rather than disciplined — there is no `game` property to read, no `snapshot()` to
 * call and nothing for a serialiser to walk. Do not replace this with an outcome
 * plus a nullable `?Game` on one object: every rejection would then carry the row
 * it refused to show. `GameResolver::resolve()` returns
 * `ResolvedPlayer|VisibilityOutcome`, a union with no common supertype, so a caller
 * must narrow with `instanceof` before reading anything.
 *
 * Several table rows collapse onto one case deliberately: rows 6 and 7 both answer
 * `NotRecognised`, so no value could separate "no Game and a tombstone" from "no
 * Game and nothing", and `NotAuthorised` is the single value for all three failure
 * modes of Req 9.6, which is what lets the Web_Client render one message.
 *
 * NO HTTP STATUS ANYWHERE IN `App\Games`, and this is the namespace-wide rule the
 * other outcome enums refer to. 403, 404 and 410 live in
 * `App\Http\Exceptions\GameNotVisibleException`, on the transport side of the
 * boundary. Backing values are the design's outcome vocabulary; the eleven rejection
 * outcomes are asserted pairwise distinct (Property 16).
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
