<?php

declare(strict_types=1);

namespace App\Domain\TicTacToe;

/**
 * The whole derivation, in one pass over one Move_List (Req 11.1).
 *
 * Pure: no persistence, no session, no transport, no framework (Req 11.9,
 * ADR-003). One entry point, so Requirement 11.5's "halt immediately and report
 * only a single uniform error state" has exactly one implementation.
 */
final class RulesEngine
{
    /**
     * Derives Board occupancy, Mark_To_Move, Outcome and the completed
     * Winning_Lines from `$moveList`, or rejects it.
     *
     * The five well-formedness classes map one-to-one onto five guards, in the
     * order the requirements list them: length, move-after-a-win, sequence
     * index, cell range, repeated cell. Every guard returns the same
     * detail-free value and derives nothing on the way out (Req 11.5, 14.8).
     */
    public static function analyse(MoveList $moveList): Analysis|InvalidMoveList
    {
        $moveCount = $moveList->count();

        if ($moveCount > 9) {
            return InvalidMoveList::Error;
        }

        /** @var array<int, Mark|null> $cells Exactly nine entries keyed 0..8. */
        $cells = array_fill(0, 9, null);

        /** @var list<WinningLine> $lines */
        $lines = [];

        foreach ($moveList as $position => $move) {
            // At the top, not the bottom: a Move_List whose final Move
            // completes a line is well formed, one with any Move *after* that
            // is not.
            if ($lines !== []) {
                return InvalidMoveList::Error;
            }

            // "Strictly increasing from zero with no gaps" over a list is
            // exactly "index equals position", so this one comparison covers a
            // gap, a repeat and a start other than zero.
            if ($move->sequenceIndex !== $position) {
                return InvalidMoveList::Error;
            }

            if ($move->cellIndex < 0 || $move->cellIndex > 8) {
                return InvalidMoveList::Error;
            }

            if ($cells[$move->cellIndex] !== null) {
                return InvalidMoveList::Error;
            }

            $mark = $move->mark();
            $cells[$move->cellIndex] = $mark;
            $lines = self::completedLinesFor($cells, $mark);
        }

        $outcome = match (true) {
            // A non-empty line set implies at least one Move, so the final
            // Move's sequence index is `$moveCount - 1`; the guard above has
            // already established that it equals its position, so its parity is
            // the Mark that completed the line.
            $lines !== [] => Outcome::wonBy(Mark::forSequenceIndex($moveCount - 1)),
            $moveCount === 9 => Outcome::Drawn,
            default => Outcome::InProgress,
        };

        return new Analysis(
            board: new Board($cells),
            outcome: $outcome,
            // Parity of the length, defined in terminal states too: Req 4.1 is
            // unconditional.
            markToMove: Mark::forSequenceIndex($moveCount),
            winningLines: $lines,
            moveCount: $moveCount,
        );
    }

    /**
     * Every line `$mark` now occupies in full, not the first: a double winning
     * line is reachable in legal play (`X0 O1 X2 O3 X6 O5 X8 O7 X4` completes
     * both diagonals), which is why Requirement 6, criteria 3 and 5 are plural.
     *
     * @param  array<int, Mark|null>  $cells  Exactly nine entries keyed 0..8.
     * @return list<WinningLine>
     */
    private static function completedLinesFor(array $cells, Mark $mark): array
    {
        $completed = [];

        foreach (WinningLine::all() as $line) {
            [$a, $b, $c] = $line->cells();

            if ($cells[$a] === $mark && $cells[$b] === $mark && $cells[$c] === $mark) {
                $completed[] = $line;
            }
        }

        return $completed;
    }
}
