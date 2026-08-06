<?php

declare(strict_types=1);

namespace App\Domain\TicTacToe;

/**
 * Req 11.5: one uniform, detail-free rejection value.
 *
 * The single case carries no data and no message on purpose — every
 * well-formedness violation is reported identically, so nothing about the
 * rejected Move_List can be derived from the rejection.
 */
enum InvalidMoveList
{
    case Error;
}
