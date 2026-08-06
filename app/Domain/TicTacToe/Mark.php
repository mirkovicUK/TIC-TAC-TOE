<?php

declare(strict_types=1);

namespace App\Domain\TicTacToe;

/**
 * A player's mark. Never stored against a Move — always derived from parity.
 */
enum Mark: string
{
    case X = 'x';
    case O = 'o';

    /**
     * Req 11.4, 4.1: mark is derived from sequence parity, never stored.
     *
     * PHP's `%` keeps the sign of the dividend, so the remainder for a negative
     * operand is 0 or -1: a negative even index takes the X branch and a negative
     * odd index takes the O branch, matching the parity of a non-negative index.
     * No guard is applied here — ill-formed sequence indices are rejected by
     * RulesEngine::analyse, not by this type.
     */
    public static function forSequenceIndex(int $sequenceIndex): self
    {
        return $sequenceIndex % 2 === 0 ? self::X : self::O;
    }

    public function opponent(): self
    {
        return $this === self::X ? self::O : self::X;
    }
}
