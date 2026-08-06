<?php

declare(strict_types=1);

namespace App\Domain\TicTacToe;

/**
 * A single Move: a plain cell index and a plain sequence index.
 *
 * Both values are stored exactly as given. Neither is range-checked here, and
 * neither may be: Requirements 11.5 and 14.8 require the engine to be handed
 * ill-formed Move_Lists, which a validating constructor would make
 * unconstructable. Well-formedness is checked by RulesEngine::analyse.
 */
final readonly class Move
{
    public function __construct(
        public int $cellIndex,
        public int $sequenceIndex,
    ) {}

    public function mark(): Mark
    {
        return Mark::forSequenceIndex($this->sequenceIndex);
    }
}
