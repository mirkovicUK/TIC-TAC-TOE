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
 * `SubmitMove::handle(GameSnapshot $observed, ...)` is a pure function of its
 * arguments and issues no `SELECT` for Game state between its first guard and its
 * insert. This type is what makes that invariant stateable: the observed state
 * arrives as a named parameter, so "read only `$observed`" is a rule a reader can
 * check by eye.
 *
 * It does not make a breach impossible. `$observed->game` is a live Eloquent model,
 * so `refresh()`, `$game->moves` and `Game::find()` are each one call away from
 * inside `SubmitMove`; `final readonly` pins the three references and says nothing
 * about the model's state. The no-re-query rule is a convention held in
 * `SubmitMove`, guarded only by the query-log assertions in
 * `SubmitMoveMechanismTest` and by the conflict test's two calls over one snapshot.
 *
 * That convention is load-bearing twice. In production it makes Requirement 5.3's
 * "exactly one of two concurrent Moves is accepted" a persisted invariant: both
 * requests read the same state, pass every guard, derive
 * `sequence_index = count($observed->moveList)`, and the collision is settled by
 * the unique index on `(game_id, sequence_index)` — the only place it can be
 * settled, since any read-then-write leaves a window. In the suite it is what makes
 * the sequential conflict test (Req 14.9) a faithful model of the concurrent case:
 * a re-read inside `SubmitMove` would make the second call fail the `markToMove`
 * guard and return `not_your_turn` instead of `conflict`, retiring the conflict path
 * while the test kept passing.
 */
final readonly class GameSnapshot
{
    /**
     * Private, so a snapshot cannot be assembled from an `Analysis` derived from
     * some other Move_List. The fields are not independent — the `Analysis` is a
     * function of the Move_List — and an inconsistent pairing is a lie
     * `SubmitMove`'s guards have no way to detect.
     */
    private function __construct(
        public Game $game,
        public MoveList $moveList,
        public Analysis $analysis,
    ) {}

    /**
     * Reads the Move_List of `$game` and derives the `Analysis` from it.
     *
     * The only query in the class, which is what leaves `SubmitMove` with nothing
     * to re-read. The ordering lives here rather than on the relationship so read
     * and ordering sit together; `ORDER BY sequence_index` is served by the unique
     * index on `(game_id, sequence_index)`.
     *
     * Rows are converted with their persisted sequence indices, not their positions
     * in the result set. `MoveList::fromCellIndices()` would renumber them 0..n-1
     * and silently repair a gap; `fromMoves()` carries them verbatim so a corrupt
     * Move_List is rejected below instead of being made to look well formed.
     *
     * @throws CorruptMoveListException if the persisted Move_List is not well
     *                                  formed — unreachable by Requirement 11.6,
     *                                  so corruption rather than user error, and
     *                                  neither swallowed nor turned into an
     *                                  outcome a player could be shown.
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
