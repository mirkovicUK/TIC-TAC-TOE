<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One row of `moves`: a Cell_Index and a Sequence_Index, and nothing else.
 *
 * There is no `mark` column, attribute or accessor. The Mark is the parity of the
 * Sequence_Index (Req 11.4), derived by `App\Domain\TicTacToe\Move`; a `mark()`
 * here would be the same modulo on the wrong side of the boundary.
 *
 * @property int $id
 * @property string $game_id
 * @property int $cell_index
 * @property int $sequence_index
 * @property Carbon $created_at
 */
final class Move extends Model
{
    /**
     * Append-only: the table has `created_at` and no `updated_at`.
     *
     * A null `UPDATED_AT` leaves timestamping on with no second column to stamp —
     * Eloquent skips the updated-at write and still stamps `created_at` on insert.
     * `$timestamps = false` would be the wrong fix: it also turns off `created_at`
     * and leaves each caller one forgotten assignment from a NOT NULL violation.
     */
    const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cell_index' => 'integer',
            'sequence_index' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
