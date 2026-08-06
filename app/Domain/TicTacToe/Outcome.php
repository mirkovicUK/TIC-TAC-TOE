<?php

declare(strict_types=1);

namespace App\Domain\TicTacToe;

/**
 * Req 11.3: exactly one of these four describes a Well_Formed_Move_List.
 *
 * `waiting_for_opponent` is deliberately absent — it is a Game_Service concern,
 * not a domain one.
 */
enum Outcome: string
{
    case InProgress = 'in_progress';
    case WonByX = 'won_by_x';
    case WonByO = 'won_by_o';
    case Drawn = 'drawn';

    public static function wonBy(Mark $mark): self
    {
        return match ($mark) {
            Mark::X => self::WonByX,
            Mark::O => self::WonByO,
        };
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::InProgress => false,
            self::WonByX, self::WonByO, self::Drawn => true,
        };
    }

    public function winner(): ?Mark
    {
        return match ($this) {
            self::WonByX => Mark::X,
            self::WonByO => Mark::O,
            self::InProgress, self::Drawn => null,
        };
    }
}
