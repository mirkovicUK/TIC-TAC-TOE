<?php

declare(strict_types=1);

namespace App\Games;

use App\Domain\TicTacToe\Analysis;
use App\Domain\TicTacToe\InvalidMoveList;
use App\Domain\TicTacToe\Move as DomainMove;
use App\Domain\TicTacToe\MoveList;
use App\Domain\TicTacToe\RulesEngine;
use App\Models\Game;

/**
 * The state of one Game as observed by one request: the game row, its Move_List,
 * and the `Analysis` the Rules_Engine derives from that Move_List.
 *
 * WHY THIS TYPE EXISTS. `SubmitMove::handle(GameSnapshot $observed, ...)` is a
 * pure function of its arguments and issues no `SELECT` for Game state between
 * its first guard and its insert. This class is what makes that invariant
 * *stateable*: the observed state arrives as a named parameter, so "read only
 * `$observed`" is a rule a reader can check by eye and a reviewer can point at,
 * whereas a `handle(Game $game, ...)` signature would not even name the thing the
 * guards are supposed to be reading.
 *
 * IT DOES NOT MAKE A BREACH IMPOSSIBLE, and the difference matters. `$observed->game`
 * is a live Eloquent model on a live connection, so `$game->refresh()`,
 * `$game->moves` and `Game::find($game->id)` are each one call away from inside
 * `SubmitMove`; `final readonly` pins the three references and says nothing about
 * the model's state. Nor does the private constructor prevent a re-read — it
 * prevents an *inconsistent snapshot*, which is a different guarantee (see below).
 * The invariant is held by convention in `SubmitMove` and guarded mechanically in
 * exactly two places: the query-log assertions in `SubmitMoveMechanismTest`, and
 * task 6.8's two calls over one snapshot.
 *
 * The invariant is load-bearing twice over.
 *
 * IN PRODUCTION it is what makes Requirement 5.3's "exactly one of two
 * concurrent Moves is accepted" a persisted invariant rather than a
 * checked-then-hoped-for one. Two competing requests each read their own
 * snapshot; under contention both read the same state, both pass every guard,
 * both derive `sequence_index = count($observed->moveList)`, and the collision is
 * settled by the unique index on `(game_id, sequence_index)` — which is the only
 * place it *can* be settled, because any read-then-write has a window between the
 * read and the write.
 *
 * IN THE TEST SUITE it is what makes the sequential conflict test (task 6.8,
 * Req 14.9) a faithful model of the concurrent case rather than a simulation of
 * it. One snapshot is passed to two successive calls, and because no guard
 * re-reads, the second call sees exactly what a genuinely concurrent second
 * request would see. A re-read inside `SubmitMove` would make that second call
 * fail the `markToMove` guard and return `not_your_turn` instead of `conflict` —
 * retiring the conflict path while the test carried on passing.
 */
final readonly class GameSnapshot
{
    /**
     * Private, so that a `GameSnapshot` cannot be assembled from an `Analysis`
     * that was derived from some other Move_List. The three fields are not
     * independent — the `Analysis` is a function of the Move_List — and an
     * inconsistent snapshot would be a lie the guards of `SubmitMove` have no way
     * to detect.
     */
    private function __construct(
        public Game $game,
        public MoveList $moveList,
        public Analysis $analysis,
    ) {}

    /**
     * Reads the Move_List of `$game` and derives the `Analysis` from it.
     *
     * THIS IS THE READ. It happens once, here, and this is the only query in the
     * class — which is what leaves `SubmitMove` with nothing to re-read. The
     * ordering is stated here rather than on the relationship so that the read
     * and its ordering sit in one place: `ORDER BY sequence_index` is served by
     * the unique index on `(game_id, sequence_index)`.
     *
     * The rows are converted with their PERSISTED sequence indices, not with
     * their positions in the result set. `MoveList::fromCellIndices()` would
     * renumber them 0..n-1 and silently repair a gap; `fromMoves()` carries them
     * verbatim, so a corrupt Move_List is rejected by the engine below instead of
     * being made to look well formed on the way in.
     *
     * @throws CorruptMoveListException if the persisted Move_List is not well
     *                                  formed — unreachable by Requirement 11.6,
     *                                  and therefore corruption rather than a
     *                                  user error. It is deliberately neither
     *                                  swallowed nor turned into an outcome the
     *                                  player could be shown.
     */
    public static function of(Game $game): self
    {
        $moves = [];

        foreach ($game->moves()->orderBy('sequence_index')->get() as $row) {
            $moves[] = new DomainMove($row->cell_index, $row->sequence_index);
        }

        $moveList = MoveList::fromMoves($moves);
        $analysis = RulesEngine::analyse($moveList);

        if ($analysis instanceof InvalidMoveList) {
            throw new CorruptMoveListException($game->id);
        }

        return new self($game, $moveList, $analysis);
    }
}
