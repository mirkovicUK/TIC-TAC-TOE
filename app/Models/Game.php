<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\TicTacToe\Mark;
use App\Games\GameState;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One row of `games`. Thin by design: no repository, no lifecycle hooks, no
 * business rules — those live in `App\Games`, and the rules of play live in
 * `App\Domain\TicTacToe`, which is forbidden from knowing this class exists.
 *
 * This model does not generate its own `id`, and must not start: `CreateGame`
 * owns Game_Id generation (Req 1.2). A `booted()` hook or a `HasUuids` trait
 * would mint the id as a side effect of `save()`, splitting identity generation
 * across two places and leaving a caller unable to tell whether the id it
 * supplied is the id that was stored.
 *
 * @property string $id UUIDv7, supplied by CreateGame (Req 1.2)
 * @property string|null $join_code NULL on a rematch
 * @property GameState $state
 * @property Mark|null $winning_mark Paired with `state = won` by a CHECK
 * @property int $version_counter
 * @property string|null $x_token_hash sha256 of the X Player_Token
 * @property string|null $o_token_hash sha256 of the O Player_Token; NULL *is* "no second player"
 * @property string|null $rematch_of_game_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon $last_activity_at
 * @property-read Collection<int, Move> $moves
 * @property-read Game|null $rematch
 */
final class Game extends Model
{
    /**
     * The primary key is a UUIDv7 in a TEXT column, not an integer sequence.
     *
     * Both this and `$keyType` are needed, for different reasons: without
     * `$incrementing = false` Eloquent discards the supplied id as though the
     * insert generated one, and without `$keyType = 'string'` it casts the key to
     * an integer on the way out, so a UUID returns as `0`. Both fail quietly.
     *
     * @var bool
     */
    public $incrementing = false;

    /** @var string */
    protected $keyType = 'string';

    /**
     * `state` and `winning_mark` cast to the enums that name their legal values,
     * which the two CHECK constraints on this table already enforce in storage.
     *
     * Casting `winning_mark` to `Mark` points from `App\Models` into the domain,
     * which is the permitted direction: the domain may not name the persistence
     * layer, and the reverse is fine. Storing a bare string instead would scatter
     * `Mark::from()` across every read site.
     *
     * All three timestamps are cast explicitly, including the two Eloquent already
     * treats as dates, so `last_activity_at` is not the odd one out.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => GameState::class,
            'winning_mark' => Mark::class,
            'version_counter' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    /**
     * The Move_List rows of this Game, unordered on purpose: `GameSnapshot::of`
     * applies `ORDER BY sequence_index` where the read happens rather than hiding
     * it in the relationship.
     *
     * @return HasMany<Move, $this>
     */
    public function moves(): HasMany
    {
        return $this->hasMany(Move::class);
    }

    /**
     * The Game created as a rematch *of* this one, if one exists (Req 7.4, 7.8) —
     * the direction `CreateRematch` and `GameRepresentation` need.
     *
     * `HasOne` rather than `HasMany` because the unique index on
     * `rematch_of_game_id` makes at most one rematch per Game a persisted fact
     * (Req 7.8). The inverse direction is deliberately absent: nothing navigates
     * it, and `SweepExpiredGames` reads both directions of the pointer as plain
     * `rematch_of_game_id` values rather than through a relationship, because it
     * needs the whole chain at once and never a single row's parent.
     *
     * @return HasOne<Game, $this>
     */
    public function rematch(): HasOne
    {
        return $this->hasOne(self::class, 'rematch_of_game_id');
    }
}
