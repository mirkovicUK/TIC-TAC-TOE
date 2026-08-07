<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A tombstone: the Game_Id of a DELETED Game and the time it was deleted
 * (Req 13.3). Two attributes, exactly as the glossary defines an Expiry_Record.
 *
 * `deleted_at` IS NOT SOFT DELETION. There is no `SoftDeletes` trait here and
 * there must never be one: the Game this row names no longer exists, so there is
 * nothing to restore and no query to scope. The column is this row's own
 * creation time under the name the requirement gives it.
 *
 * There is deliberately no relationship to `Game` either. `game_id` carries no
 * foreign key — it cannot, because the sweep inserts the tombstone in the same
 * transaction as the delete of the row it names — so a `belongsTo` here would be
 * a relationship guaranteed to resolve to null, and an invitation to add the
 * foreign key that would break the sweep.
 *
 * @property string $game_id
 * @property Carbon $deleted_at
 */
final class ExpiryRecord extends Model
{
    /**
     * The Game_Id is the natural key, so there is no surrogate `id` column and
     * nothing for Eloquent to auto-increment.
     *
     * @var string
     */
    protected $primaryKey = 'game_id';

    /** @var bool */
    public $incrementing = false;

    /** @var string */
    protected $keyType = 'string';

    /**
     * The table has neither `created_at` nor `updated_at`: `deleted_at` is the
     * only timestamp a write-once tombstone has.
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
