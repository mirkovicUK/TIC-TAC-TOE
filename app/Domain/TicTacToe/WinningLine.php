<?php

declare(strict_types=1);

namespace App\Domain\TicTacToe;

/**
 * The eight lines that win a game.
 *
 * Cells are numbered 0..8, left to right and top to bottom:
 *
 *     0 1 2
 *     3 4 5
 *     6 7 8
 */
enum WinningLine
{
    case TopRow;
    case MiddleRow;
    case BottomRow;
    case LeftColumn;
    case MiddleColumn;
    case RightColumn;
    case MainDiagonal;
    case AntiDiagonal;

    /**
     * @return array{int, int, int}
     */
    public function cells(): array
    {
        return match ($this) {
            self::TopRow => [0, 1, 2],
            self::MiddleRow => [3, 4, 5],
            self::BottomRow => [6, 7, 8],
            self::LeftColumn => [0, 3, 6],
            self::MiddleColumn => [1, 4, 7],
            self::RightColumn => [2, 5, 8],
            self::MainDiagonal => [0, 4, 8],
            self::AntiDiagonal => [2, 4, 6],
        };
    }

    /**
     * @return list<self> All eight lines.
     */
    public static function all(): array
    {
        return self::cases();
    }
}
