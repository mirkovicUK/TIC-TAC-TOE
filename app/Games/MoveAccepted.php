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
 * FOUR FIELDS, AND THEY ARE THE FOUR THINGS A CALLER CANNOT RECOVER WITHOUT A
 * QUERY. `SubmitMove` derives the Sequence_Index from the observed Move_List and
 * the Outcome from the appended one, and both are gone the moment `handle()`
 * returns — so a caller wanting either would have to re-read, which is the one
 * thing this whole design is arranged to avoid. The Mark and the Cell_Index are
 * here for the same reason they are on the design's `move.accepted` log record:
 * they are what was written, reported by the writer.
 *
 * `Outcome` RATHER THAN `GameState` PLUS `?Mark`. The transaction writes two
 * columns, `state` and `winning_mark`, and both are functions of this one value:
 * `GameState::fromOutcome($result->outcome)` is the state that was written and
 * `$result->outcome->winner()` is the Mark. Carrying the two columns instead would
 * mean carrying a pair that can disagree, in a type whose whole purpose is to
 * report what a single `match` derived. It is also the exact field the design's
 * `game.finished` record needs — its `result` is `won_by_x`, `won_by_o` or
 * `drawn`, which is `Outcome`'s backing value — and `isTerminal()` is how task
 * 10.x will know whether to emit that record at all.
 *
 * IT CARRIES NO `Version_Counter`, AND NOT FOR WANT OF USEFULNESS. The increment
 * is `version_counter = version_counter + 1`, an expression the database
 * evaluates (Req 4.7), so the resulting value is not known in PHP without a
 * `SELECT`. Adding one after the commit would not breach the purity invariant —
 * that forbids reads between the first guard and the insert — but it would be a
 * query on the write path serving nothing: task 6.2's controller answers a 303,
 * and the GET that follows re-reads the row and reports the Version_Counter
 * through `GameRepresentation` (Req 8.3). Computing it as
 * `$observed->game->version_counter + 1` was the other option and is worse: it
 * would assert the arithmetic rather than observe it, and it would be a second
 * implementation of the increment sitting next to the real one.
 *
 * IT CARRIES NO `Game` AND NO `GameSnapshot`. A snapshot of the post-Move state
 * would cost a second Move_List read and a second `RulesEngine::analyse()` call,
 * and the caller that needs one — the GET after the redirect — builds it from the
 * committed row anyway.
 *
 * @see MoveOutcome for the rejection half of the union, and for why it is fieldless
 */
final readonly class MoveAccepted
{
    /**
     * The constructor is public because there is nothing to guard, and there are
     * exactly two producers to guard against: `SubmitMove`, which constructs one
     * inside the committed transaction, and a test constructing an expected value
     * to compare against. Nothing about an instance is a claim a caller could get
     * wrong on its own — it reports a write rather than authorising one, which is
     * what makes this different from `ResolvedPlayer`.
     */
    public function __construct(
        /**
         * The Mark of the accepted Move: the Mark bound to the presented
         * Player_Token, and equal to `Mark::forSequenceIndex($sequenceIndex)` by
         * the turn guard `SubmitMove` applied (Req 3.2, 11.4). Never from a
         * payload (Req 3.6).
         */
        public Mark $mark,
        /** The Cell the Move occupies, in 0..8. */
        public int $cellIndex,
        /**
         * The length of the Move_List before acceptance (Req 4.2) — the value
         * written to `moves.sequence_index`, and the value whose uniqueness
         * within the Game settled the conflict this Move did not lose.
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
