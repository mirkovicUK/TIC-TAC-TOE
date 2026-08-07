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
 * WHAT THIS MODEL DOES NOT DO, AND MUST NOT START DOING: it does not generate
 * its own `id`. Requirement 1.2 requires a Game_Id that derives from no
 * monotonically increasing database sequence, and `CreateGame` owns the
 * generation of it. A `booted()` hook or a `HasUuids` trait here would mint the
 * id as a side effect of `save()`, which would put identity generation in two
 * places at once and make `CreateGame`'s contract ambiguous — a caller could no
 * longer tell whether the id it supplied is the id that was stored.
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
     * Both this and `$keyType` are required, and for different reasons: without
     * `$incrementing = false` Eloquent treats the insert as producing a
     * generated id and discards the one supplied, and without
     * `$keyType = 'string'` it casts the key to an integer on the way out — a
     * UUID would come back as `0`. Either omission fails quietly rather than
     * loudly.
     *
     * @var bool
     */
    public $incrementing = false;

    /** @var string */
    protected $keyType = 'string';

    /**
     * `state` and `winning_mark` are cast to the enums that name their legal
     * values, which the two CHECK constraints on this table already enforce at
     * the storage layer. The cast makes the same statement in PHP: a guard reads
     * `$game->state === GameState::WaitingForOpponent` rather than comparing
     * strings, and `$game->winning_mark = $analysis->winner()` assigns a
     * `?Mark` straight from the engine with no `->value` in between.
     *
     * Casting `winning_mark` to `Mark` creates a reference from `App\Models`
     * into `App\Domain\TicTacToe`. That is the permitted direction — the
     * architecture test forbids the domain naming `App\Models`, `App\Http` or
     * `Illuminate`, and says nothing about the reverse — and it is the right
     * direction here: the domain stays a library that knows nothing of
     * persistence, while the persistence layer is free to speak the domain's
     * vocabulary. The alternative, storing a bare `'x'`/`'o'` string and
     * converting at every read site, would put `Mark::from()` in
     * `GameRepresentation`, in `CreateRematch` and in every test that asserts a
     * winner, and each of those is a place the conversion could be forgotten.
     *
     * The three timestamps are cast explicitly, including the two Eloquent
     * already treats as dates, so that all three read the same way and
     * `last_activity_at` — which Eloquent knows nothing about — is not the odd
     * one out.
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
     * The Move_List rows of this Game, unordered here on purpose: ordering is
     * `ORDER BY sequence_index`, and it is applied where the snapshot is read
     * (`GameSnapshot::of`) rather than hidden in the relationship, so the one
     * read that matters states its own ordering.
     *
     * @return HasMany<Move, $this>
     */
    public function moves(): HasMany
    {
        return $this->hasMany(Move::class);
    }

    /**
     * The Game created as a rematch *of* this one, if one exists (Req 7.4,
     * 7.8) — the direction `CreateRematch` and `GameRepresentation` need: one
     * looks for an existing rematch before creating one, the other reports its
     * id as `rematchGameId`.
     *
     * A `HasOne` and not a `HasMany`, which is not a simplification: the unique
     * index on `rematch_of_game_id` makes at most one rematch per Game a
     * persisted fact (Req 7.8), so the relationship states in PHP exactly what
     * the schema enforces.
     *
     * The inverse (`the game this one is a rematch of`) is deliberately absent.
     * Nothing navigates that way: `CreateRematch` is handed the preceding Game
     * directly, and the sweep clears the back-reference with a bulk `UPDATE`
     * rather than by traversing a relationship.
     *
     * @return HasOne<Game, $this>
     */
    public function rematch(): HasOne
    {
        return $this->hasOne(self::class, 'rematch_of_game_id');
    }
}
