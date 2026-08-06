<?php

declare(strict_types=1);

namespace App\Domain\TicTacToe;

/**
 * Everything RulesEngine::analyse derives from a Well_Formed_Move_List, as one
 * aggregate.
 *
 * `markToMove` is defined in terminal states too, where it identifies who would
 * move next (Req 4.1 is unconditional); the client displays it only while the
 * game is active.
 */
final readonly class Analysis
{
    /**
     * @param  list<WinningLine>  $winningLines
     */
    public function __construct(
        public Board $board,
        public Outcome $outcome,
        public Mark $markToMove,
        public array $winningLines,
        public int $moveCount,
    ) {}

    public function winner(): ?Mark
    {
        return $this->outcome->winner();
    }

    public function isTerminal(): bool
    {
        return $this->outcome->isTerminal();
    }
}
