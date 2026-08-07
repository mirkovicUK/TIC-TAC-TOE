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
 * Holding an instance is the statement "this session is an authorised Player of
 * this Game, holding a Player_Token bound to this Mark". It is the only thing
 * `GameResolver` returns that carries a Game — every rejection is a fieldless
 * `VisibilityOutcome` or `JoinOutcome` case — so an unauthorised caller cannot
 * reach a Game through either return value.
 *
 * It deliberately carries no `GameSnapshot`, for three reasons. Authorisation is
 * decided by two token-hash columns of one row, so bundling `GameSnapshot::of()`
 * would add a Move_List query to every answer that does not use it, including the
 * two-second polling path (Req 8.1). The rematch path resolves the preceding Game
 * only to guard on its `state`, and would build and discard a snapshot each time.
 * And `GameSnapshot::of()` can throw `CorruptMoveListException` — a 500 — which
 * would land ahead of the authorisation answer that Requirement 3.9 requires to be
 * settled first. Consumers build their own snapshot at the point they need it.
 */
final readonly class ResolvedPlayer
{
    /**
     * Public because there is nothing to guard, and there are exactly three
     * producers, each of which has established the pair before constructing one:
     * `GameResolver`, on the branch where `PlayerTokens::resolve()` returned a Mark
     * for the row; `JoinGame`, on its two accepted paths (Req 2.4, 2.5); and
     * `CreateRematch`, for the *rematch* row rather than the Game it was handed,
     * with the swapped Mark `$precedingMark->opponent()` (Req 7.3).
     *
     * The test each producer meets: a `GameResolver::resolve()` call made
     * immediately afterwards in that session would build an equal instance from the
     * persisted row. Nothing else may construct one — a hand-built instance is an
     * unauthorised request claiming to be authorised, which is why
     * `ResolveActingPlayer` is the only thing that puts one where a controller can
     * read it.
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
