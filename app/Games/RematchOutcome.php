<?php

declare(strict_types=1);

namespace App\Games;

/**
 * The one way `CreateRematch` can refuse a request from an authorised Player:
 * `invalid_state`, spelled exactly as the design's outcome table spells it.
 *
 * One fieldless rejection enum per decision is the pattern already established
 * three times in this namespace, returned as one half of a union whose other half
 * is the success value. Do not fold this case into `MoveOutcome`,
 * `VisibilityOutcome` or `JoinOutcome`: each would widen that class's return type
 * past what it can actually produce, leaving callers a case that never arrives.
 *
 * The case carries no data because there is nothing for it to carry, not because
 * of concealment — a caller reaching it has already passed `GameResolver`, and the
 * 303 back to the game page rebuilds a full representation of the preceding Game.
 * `CreateRematch::handle()` returns `ResolvedPlayer|RematchOutcome`, a union with
 * no common supertype, so a caller must narrow with `instanceof`.
 *
 * `not_authorised` (Req 7.11) is absent because `ResolveActingPlayer` throws
 * before this class is reached (Req 3.9), and no path here could produce it. The
 * HTTP status is not here either; nothing in `App\Games` knows what one is.
 *
 * Task 12.1 asserts the application's eleven rejection outcomes are pairwise
 * distinct (Property 16); this is one of them.
 */
enum RematchOutcome: string
{
    /**
     * A Rematch was requested for a preceding Game that is not in a
     * Terminal_State — still `waiting_for_opponent`, or still `active`
     * (Req 7.2, 7.3, 7.10).
     */
    case InvalidState = 'invalid_state';
}
