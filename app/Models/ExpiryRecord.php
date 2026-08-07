<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A tombstone: the Game_Id of a DELETED Game and the time it was deleted
 * (Req 13.3). Two attributes, exactly as the glossary defines an Expiry_Record.
 *
 * `deleted_at` is not soft deletion, and `SoftDeletes` must never be added: the
 * Game this row names no longer exists, so there is nothing to restore and no
 * query to scope. The column is this row's own creation time under the name the
 * requirement gives it.
 *
 * There is deliberately no relationship to `Game` and no foreign key on
 * `game_id` — the sweep inserts the tombstone in the same transaction as the
 * delete of the row it names, so a `belongsTo` would always resolve to null and
 * would invite the foreign key that breaks the sweep.
 *
 * @property string $game_id
 * @property Carbon $deleted_at
 */
final class ExpiryRecord extends Model
{
    /**
     * The Game_Id is the natural key: no surrogate `id` column exists.
     *
     * @var string
     */
    protected $primaryKey = 'game_id';

    /** @var bool */
    public $incrementing = false;

    /** @var string */
    protected $keyType = 'string';

    /**
     * The table has neither `created_at` nor `updated_at`; `deleted_at` is the only
     * timestamp a write-once tombstone has.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'deleted_at' => 'datetime',
        ];
    }
}
