<?php

declare(strict_types=1);

namespace App\Games;

/**
 * What one run of `SweepExpiredGames` did: Games deleted, Games deferred, and
 * Expiry_Records purged (Req 13.1–13.4).
 *
 * `gamesDeferred` is reported because a deferral is otherwise invisible: the Game
 * is eligible, is still there after the run, and nothing in its row records why.
 * Deletions and purges can both be observed after the fact by counting rows; a
 * deferral cannot.
 *
 * Three named ints rather than an `array{int, int, int}` for the reason
 * `ResolvedPlayer` is not a tuple — three values of one type destructured by
 * position are one transposition away from reporting the wrong count.
 */
final readonly class SweepReport
{
    public function __construct(
        /** Games deleted, each with an Expiry_Record written in the same transaction. */
        public int $gamesDeleted,
        /**
         * Games that were Eligible_For_Expiry and were kept because a Rematch
         * descending from them survives — including the ancestors deferred with
         * it, since deferring a Game defers the whole chain above it.
         */
        public int $gamesDeferred,
        /** Expiry_Records deleted for being past the 30-day retention (Req 13.4). */
        public int $recordsPurged,
    ) {}
}
