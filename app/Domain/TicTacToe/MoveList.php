<?php

declare(strict_types=1);

namespace App\Domain\TicTacToe;

/**
 * An ordered list of Moves. The single source of truth for a game: Board
 * occupancy, Mark_To_Move and Outcome are all derived from it (Req 11.1).
 *
 * Immutable. `append()` returns a new instance; nothing here mutates.
 *
 * @implements \IteratorAggregate<int, Move>
 */
final readonly class MoveList implements \Countable, \IteratorAggregate
{
    /**
     * @param  list<Move>  $moves
     */
    private function __construct(public array $moves) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Sequence indices are the positions, so this always builds a contiguous list.
     */
    public static function fromCellIndices(int ...$cellIndices): self
    {
        $moves = [];

        foreach (array_values($cellIndices) as $position => $cellIndex) {
            $moves[] = new Move($cellIndex, $position);
        }

        return new self($moves);
    }

    /**
     * Ill-formed input survives construction untouched — gaps, duplicates, cell
     * indices outside 0..8, more than nine entries — because Requirements 11.5
     * and 14.8 require the engine to be handed exactly such lists.
     *
     * @param  list<Move>  $moves  Accepted verbatim, including ill-formed input.
     */
    public static function fromMoves(array $moves): self
    {
        return new self($moves);
    }

    /**
     * Appends a Move whose sequence index is the current length, so a list built
     * only through this method is contiguous from zero.
     */
    public function append(int $cellIndex): self
    {
        $moves = $this->moves;
        $moves[] = new Move($cellIndex, $this->count());

        return new self($moves);
    }

    public function count(): int
    {
        return count($this->moves);
    }

    /**
     * @return list<int>
     */
    public function cellIndices(): array
    {
        return array_map(
            static fn (Move $move): int => $move->cellIndex,
            $this->moves,
        );
    }

    /**
     * @return \Traversable<int, Move>
     */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->moves);
    }
}
