<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Support;

/**
 * Independent, deliberately naive win oracle. Test-only.
 *
 * The engine must never be its own judge: if the enumeration asked the engine
 * when to stop recursing, Properties 3 and 4 would be tautologies. So this
 * shares no code with the domain namespace, not even a type — it keeps its own
 * hand-written line table, because one table read by both implementations could
 * be wrong in both identically and they would agree anyway. Small enough to
 * review by eye is the entire basis for trusting it.
 */
final class LineOracle
{
    /**
     * Three rows, three columns, two diagonals, written by hand against
     *
     *     0 1 2
     *     3 4 5
     *     6 7 8
     *
     * Held in a constant so the table is built once for the whole walk rather
     * than once per node (mitigation 1 of the runtime budget).
     *
     * @var list<array{int, int, int}>
     */
    private const array LINES = [
        [0, 1, 2], [3, 4, 5], [6, 7, 8],
        [0, 3, 6], [1, 4, 7], [2, 5, 8],
        [0, 4, 8], [2, 4, 6],
    ];

    /**
     * Every line the mark fully occupies — all of them, never just the first.
     *
     * @param  array<int, string|null>  $cells  nine cells of 'x', 'o' or null
     * @return list<array{int, int, int}>
     */
    public function completedLines(array $cells, string $mark): array
    {
        $completed = [];

        foreach (self::LINES as [$a, $b, $c]) {
            if (($cells[$a] ?? null) === $mark
                && ($cells[$b] ?? null) === $mark
                && ($cells[$c] ?? null) === $mark) {
                $completed[] = [$a, $b, $c];
            }
        }

        return $completed;
    }

    /**
     * True when the mark holds a line, or the board is full at nine moves.
     *
     * @param  array<int, string|null>  $cells
     */
    public function isTerminal(array $cells, int $moveCount, string $mark): bool
    {
        return $this->completedLines($cells, $mark) !== [] || $moveCount >= 9;
    }
}
