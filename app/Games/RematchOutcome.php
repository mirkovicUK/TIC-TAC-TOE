<?php

declare(strict_types=1);

namespace App\Games;

/**
 * The one way `CreateRematch` can refuse a request from an authorised Player:
 * `invalid_state`, spelled exactly as the design's outcome table spells it.
 *
 * Do not fold this case into `MoveOutcome`, `VisibilityOutcome` or `JoinOutcome`:
 * each would widen that class's return type past what it can produce, leaving
 * callers a case that never arrives.
 *
 * `CreateRematch::handle()` returns `ResolvedPlayer|RematchOutcome`, a union with no
 * common supertype, so a caller must narrow with `instanceof`. The case carries no
 * data because there is nothing to carry — the 303 back to the game page rebuilds a
 * full representation of the preceding Game.
 *
 * `not_authorised` (Req 7.11) is absent because `ResolveActingPlayer` throws before
 * this class is reached (Req 3.9). One of the eleven rejection outcomes asserted
 * pairwise distinct (Property 16).
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
