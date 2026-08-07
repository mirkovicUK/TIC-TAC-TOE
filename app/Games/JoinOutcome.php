<?php

declare(strict_types=1);

namespace App\Games;

/**
 * The two ways `JoinGame` can refuse a submitted Join_Code: `game_full` and
 * `not_recognised`, spelled exactly as the design's outcome table spells them.
 *
 * The cases carry no data, which is what makes Req 3.10 structural rather than
 * disciplined: a caller submitting a Join_Code is not yet a Player of the Game it
 * names, and an enum case has no `game` property for a controller to disclose.
 * `JoinGame::handle()` returns `ResolvedPlayer|JoinOutcome`, a union with no common
 * supertype, so a caller must narrow with `instanceof` before reading anything.
 *
 * Separate from `VisibilityOutcome` because the decisions are separate — "may this
 * session see this Game" versus "may this session become the O Player of it". One
 * merged enum would let `GameResolver`'s return type express `game_full`, which is
 * not a row of its visibility table and which it cannot produce.
 *
 * `NotRecognised` shares its backing value with `VisibilityOutcome::NotRecognised`
 * by design, not by accident: the design lists `not_recognised` once, raised by
 * Req 2.2 for a Join_Code and Req 13.8 for a Game_Id, with one client-facing
 * message. Do not split them to satisfy Property 16, which asks for distinct
 * values per outcome rather than per condition; the differing transport (303 to
 * `/join` versus a 404 page) is settled by which type raised the outcome. The HTTP
 * status is not here; nothing in `App\Games` knows what one is.
 */
enum JoinOutcome: string
{
    /**
     * The code matched a Game with no free O slot, and the session holds no
     * Player_Token for it (Req 2.3) — including the loser of a concurrent join,
     * whose guarded UPDATE affected zero rows (Req 2.7).
     */
    case GameFull = 'game_full';

    /**
     * The code matched no Game, or could not be a Join_Code at all (Req 2.2). One
     * value for both, so a caller learns nothing about the code space.
     */
    case NotRecognised = 'not_recognised';
}
