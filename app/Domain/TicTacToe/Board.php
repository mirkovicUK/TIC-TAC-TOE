<?php

declare(strict_types=1);

namespace App\Domain\TicTacToe;

/**
 * Derived Board occupancy. Never stored, never constructed from user input:
 * RulesEngine::analyse builds the cell array itself and hands it over, so the
 * "exactly nine entries keyed 0..8" contract is carried by this docblock rather
 * than by a runtime guard.
 */
final readonly class Board
{
    /**
     * @param  array<int, Mark|null>  $cells  Exactly nine entries keyed 0..8.
     */
    public function __construct(private array $cells) {}

    public function occupantOf(int $cellIndex): ?Mark
    {
        return $this->cells[$cellIndex] ?? null;
    }

    public function isOccupied(int $cellIndex): bool
    {
        return $this->occupantOf($cellIndex) instanceof Mark;
    }

    /**
     * @return list<int>
     */
    public function vacantCells(): array
    {
        $vacant = [];

        foreach ($this->cells as $cellIndex => $occupant) {
            if (! $occupant instanceof Mark) {
                $vacant[] = $cellIndex;
            }
        }

        return $vacant;
    }

    /**
     * @return array<int, Mark|null>
     */
    public function cells(): array
    {
        return $this->cells;
    }
}
