<?php

declare(strict_types=1);

namespace App\Games;

/**
 * The one way `CreateRematch` can refuse a request from an authorised Player:
 * `invalid_state`, spelled exactly as the design's outcome table spells it.
 *
 * A ONE-CASE ENUM, AND THE ALTERNATIVES WERE ALL WORSE. The value had to go
 * somewhere, and there are three existing enums it could have been added to
 * instead. Each addition would have widened a return type past what the class
 * behind it can produce:
 *
 *   - `MoveOutcome` is the five refusals `SubmitMove` can answer with. Adding a
 *     sixth would make `SubmitMove::handle()`'s return type able to express an
 *     outcome nothing in that class raises, so every caller narrowing a
 *     `MoveOutcome` would have a case to handle that can never arrive — and the
 *     enum's own docblock, which enumerates the five and says what is
 *     deliberately absent, would become false.
 *   - `VisibilityOutcome` is *exactly* the seven-row visibility table of
 *     `GameResolver`, no more and no less. `invalid_state` is not a row of it:
 *     that table is decided by the two token-hash columns, and a Game's
 *     lifecycle state is deliberately not consulted there at all, because a
 *     Player of a finished Game is still a Player of it (Req 9.2, 9.5).
 *   - `JoinOutcome` answers "may this session become the O Player of this Game",
 *     which is a different question from "may this Game have a Rematch".
 *
 * So the pattern this file follows is the one already established three times
 * over in this namespace: one fieldless rejection enum per decision, returned as
 * one half of a union whose other half is the success value. The economy of a
 * single application-wide `Outcome` enum is available and is deliberately not
 * taken; `JoinOutcome`'s docblock argues that at length and the argument applies
 * unchanged here.
 *
 * THE ENUM CARRIES NO DATA, for `MoveOutcome`'s reason rather than
 * `VisibilityOutcome`'s. A caller reaching this outcome has already passed
 * `GameResolver` and is a Player of the preceding Game, so concealment is not the
 * point — the design's outcome table puts `invalid_state` at "303 → game page"
 * with "`outcome` + full representation", which is disclosure. The state that
 * accompanies the outcome is supplied by the redirect: the following GET builds a
 * fresh representation of the preceding Game, so the rejection value itself has
 * nothing to carry. `CreateRematch::handle()` therefore returns
 * `ResolvedPlayer|RematchOutcome`, a genuine union with no common supertype, so a
 * caller must narrow with `instanceof` before it can read anything.
 *
 * WHAT IS NOT IN THIS ENUM, and must not be added. `not_authorised`, which
 * Requirement 7.11 raises for a session holding no Player_Token for the preceding
 * Game: that is `GameResolver`'s answer, thrown by `ResolveActingPlayer` before
 * `CreateRematch` is constructed, let alone called (Req 3.9). There is no such
 * case here because there is no path through this class that could reach one — a
 * caller without a `ResolvedPlayer` has no `Mark` to pass.
 *
 * THE HTTP STATUS IS DELIBERATELY NOT HERE, as it is not on the other three
 * outcome enums. Distinctness is carried by the value; the 303 to the game page
 * is the controller's. Nothing in `App\Games` knows what an HTTP status is.
 *
 * Task 12.1 asserts all eleven rejection outcomes of the application are pairwise
 * distinct (Property 16); this is the eleventh minus `rate_limited`, and it is
 * distinct from the other ten by not sharing a backing value with any of them.
 */
enum RematchOutcome: string
{
    /**
     * A Rematch was requested for a preceding Game that is not in a
     * Terminal_State — still `waiting_for_opponent`, or still `active` (Req 7.10).
     *
     * Requirement 7.2 conditions creation on a Terminal_State, and the swap of
     * Requirement 7.3 is only meaningful once the preceding Game has finished, so
     * this is a refusal rather than an early start.
     */
    case InvalidState = 'invalid_state';
}
