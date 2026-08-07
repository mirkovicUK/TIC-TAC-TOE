<?php

declare(strict_types=1);

namespace App\Games;

use App\Domain\TicTacToe\Mark;
use App\Domain\TicTacToe\Outcome;

/**
 * A committed Move: the success half of `SubmitMove`'s return union, and the
 * statement "this Move is in the `moves` table and the `games` row now describes
 * the Move_List that includes it".
 *
 * The four fields are the four things a caller cannot recover without a query.
 * `SubmitMove` derives the Sequence_Index from the observed Move_List and the
 * Outcome from the appended one, and both are gone once `handle()` returns.
 *
 * `Outcome` rather than `GameState` plus `?Mark`: the transaction writes `state`
 * and `winning_mark`, and both are functions of this one value
 * (`GameState::fromOutcome()`, `Outcome::winner()`), so carrying the two columns
 * would carry a pair that can disagree. It is also the field the design's
 * `game.finished` record needs, whose `result` is `Outcome`'s backing value.
 *
 * No Version_Counter, because the increment is `version_counter + 1` evaluated by
 * the database (Req 4.7) and so unknown in PHP without a `SELECT`. Do not compute
 * it as `$observed->game->version_counter + 1`: that asserts the arithmetic rather
 * than observing it, and duplicates the increment. The GET after the 303 re-reads
 * the row and reports it through `GameRepresentation` (Req 8.3).
 *
 * No `Game` and no `GameSnapshot`: a post-Move snapshot costs a second Move_List
 * read and `RulesEngine::analyse()` call, and the GET after the redirect builds one
 * from the committed row anyway.
 *
 * @see MoveOutcome for the rejection half of the union, and for why it is fieldless
 */
final readonly class MoveAccepted
{
    /**
     * Public because there is nothing to guard: the two producers are `SubmitMove`,
     * inside the committed transaction, and tests building an expected value. Unlike
     * `ResolvedPlayer`, an instance reports a write rather than authorising one.
     */
    public function __construct(
        /**
         * The Mark bound to the presented Player_Token, equal to
         * `Mark::forSequenceIndex($sequenceIndex)` by the turn guard (Req 3.2,
         * 11.4). Never from a payload (Req 3.6).
         */
        public Mark $mark,
        /** The Cell the Move occupies, in 0..8. */
        public int $cellIndex,
        /**
         * The length of the Move_List before acceptance (Req 4.2) — written to
         * `moves.sequence_index`, and the value whose uniqueness within the Game
         * settled the conflict this Move did not lose.
         */
        public int $sequenceIndex,
        /**
         * What the Rules_Engine derived from the Move_List *including* this Move.
         * `GameState::fromOutcome()` recovers the persisted Game_State and
         * `winner()` the persisted winning Mark (Req 6.2, 6.4).
         */
        public Outcome $outcome,
    ) {}
}
