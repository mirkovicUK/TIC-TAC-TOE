<?php

declare(strict_types=1);

namespace App\Games;

use App\Domain\TicTacToe\Mark;
use App\Models\Game;

/**
 * A Game, and the Mark the Player_Token in the requesting session is bound to on
 * it: row 1 of `GameResolver`'s visibility table, and the accepted half of
 * `JoinGame`.
 *
 * The existence of an instance of this class is the statement "this session is an
 * authorised Player of this Game, holding a Player_Token bound to this Mark". It
 * is the only thing `GameResolver` returns that carries a Game at all — every
 * rejection is a bare `VisibilityOutcome` case with no fields, and every
 * `JoinGame` rejection a bare `JoinOutcome` case — so a caller holding one has
 * already passed the authorisation check by virtue of holding it, and a caller
 * that has not cannot reach a Game through either return value.
 *
 * IT DOES NOT CARRY A `GameSnapshot`, AND THAT IS DELIBERATE. A snapshot would be
 * convenient for two of the three consumers, and it is still the wrong thing to
 * put here, for three reasons.
 *
 *   - **The authorisation decision does not depend on the Move_List.** Row 1 is
 *     decided by the two token-hash columns of one row and nothing else.
 *     `GameSnapshot::of()` performs a second query — the Move_List read — and
 *     making it part of resolution would mean every authorisation answer was
 *     preceded by a query no part of the answer uses. `GameResolver` runs on the
 *     polling path, twice every two seconds per Game (Req 8.1), so that is a real
 *     cost paid on reads as well as writes.
 *   - **Not every authorised request wants one.** `POST /games/{game}/rematch`
 *     resolves the *preceding* Game, guards on its `state` — a column on the row
 *     — and then represents the *rematch*. A snapshot of the resolved Game would
 *     be built and discarded on that path every time.
 *   - **`GameSnapshot::of()` can throw.** It raises `CorruptMoveListException`
 *     for a persisted Move_List the Rules_Engine rejects, which the design maps
 *     to a 500 and an invariant-violation record. Building one during resolution
 *     would put that 500 ahead of the authorisation answer, and Requirement 3.9
 *     is explicit that authorisation is settled first and is the only outcome
 *     reported for a request that fails it.
 *
 * So the consumers build their own: `SubmitMove` (task 6.1) takes a
 * `GameSnapshot` and is handed `GameSnapshot::of($resolved->game)` by its
 * controller, and `GameRepresentation` (task 5.5) does the same on the paths that
 * serialise a board. One query, at the point that needs it, on the paths that
 * need it.
 */
final readonly class ResolvedPlayer
{
    /**
     * The constructor is public because there is nothing to guard: the pair is
     * only meaningful together, and there are exactly THREE producers, each of which
     * has established the fact before constructing one.
     *
     *   - `GameResolver` constructs one solely on the branch where
     *     `PlayerTokens::resolve()` returned a Mark for this row — the fact
     *     *observed*.
     *   - `JoinGame` constructs one on its two accepted paths: after its guarded
     *     UPDATE claimed the O slot and `PlayerTokens::remember()` put the matching
     *     token in the session, and on its short-circuit, where the Mark comes from
     *     the same `PlayerTokens::resolve()` call the resolver makes (Req 2.4, 2.5)
     *     — the fact *established*. Either way, a `GameResolver::resolve()` call
     *     made immediately afterwards in that session would construct an equal
     *     instance from the persisted row, which is what makes the reuse honest
     *     rather than merely convenient.
     *   - `CreateRematch` (task 7.1) constructs one for the REMATCH, not for the
     *     Game it was handed: by the time it does, the minted hash is in that row's
     *     slot for the swapped Mark and the raw value is in the session, so the same
     *     "an equal instance would be resolved from the persisted row" test holds.
     *     Its Mark is the one place a `ResolvedPlayer`'s Mark is not read straight
     *     off a token — it is `$precedingMark->opponent()` (Req 7.3) — and the pair
     *     is still established rather than asserted, because the token it names was
     *     minted into that slot two statements earlier.
     *
     * Nothing else in the application constructs one, and nothing else should: a
     * hand-built instance would be an unauthorised request claiming to be an
     * authorised one, which is why `ResolveActingPlayer` is the only thing that
     * ever puts one where a controller can read it.
     */
    public function __construct(
        /** The Game row as read during resolution. Its Move_List is NOT loaded. */
        public Game $game,
        /**
         * The Mark bound to the presented Player_Token, from
         * `PlayerTokens::resolve()` and from nothing else — never from a payload
         * (Req 3.2, 3.6).
         */
        public Mark $mark,
    ) {}
}
