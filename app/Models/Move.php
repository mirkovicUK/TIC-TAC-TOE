<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One row of `moves`: a Cell_Index and a Sequence_Index, and nothing else.
 *
 * There is no `mark` attribute and no accessor for one. The Mark is the parity
 * of the Sequence_Index (Req 11.4), derived by `App\Domain\TicTacToe\Move` on the
 * domain side of the boundary. A `mark()` here would be a second implementation
 * of the same modulo, on the wrong side of it.
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
     * Append-only, expressed in the one place Eloquent reads it.
     *
     * The table has `created_at` and NO `updated_at`, so the default behaviour —
     * `$timestamps = true`, which writes both — would make every insert fail on
     * a missing column. The two obvious fixes are both wrong. Setting
     * `$timestamps = false` turns off `created_at` as well and leaves each
     * caller to set it by hand, which is one forgotten assignment away from a
     * NOT NULL violation. Adding an `updated_at` column to the schema would
     * contradict the migration's append-only note.
     *
     * A null `UPDATED_AT` is the mechanism that says "created_at only":
     * `HasTimestamps::updateTimestamps()` skips the updated-at write when
     * `getUpdatedAtColumn()` returns null, and still stamps `created_at` on
     * insert. Timestamping stays on; there is simply no second column to stamp.
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
