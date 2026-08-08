<?php

declare(strict_types=1);

namespace App\Games;

use App\Domain\TicTacToe\Mark;
use App\Models\Game;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Creates a Game: one row in `waiting_for_opponent` with a fresh Join_Code, and
 * the X Player_Token issued to the requesting session (Req 1.1–1.5).
 *
 * One INSERT, no SELECT. Nothing here reads the database — in particular there is
 * no query asking whether a generated Join_Code is already taken, because that
 * would be a read-then-write race whose loser is silent. Req 1.4's "at most one
 * Game per Join_Code" is carried by the `games_join_code_unique` index instead: a
 * unique index has no window between the check and the write, and it holds against
 * writers that never ran this code at all.
 *
 * An empty Move_List (Req 1.1) needs no write — a Move_List is a Game's `moves`
 * rows, not a column, so a Game with no `moves` rows already has one.
 *
 * Do not add a uniqueness pre-check. The one `game.created` record goes through
 * `GameEventLogger` (Req 10.3), which stays the exclusive writer of lifecycle
 * records so that Req 10.5's redaction has a single place to hold — nothing here
 * formats a record of its own, and in particular the Join_Code on this row is
 * never handed to it. Response shape is the controller's, and
 * `GameRepresentation` is the only serialiser.
 */
final class CreateGame
{
    /**
     * How many times an insert may be attempted before the collision is allowed
     * to surface. See `handle()` for why three is generous.
     */
    private const int MAX_INSERT_ATTEMPTS = 3;

    /**
     * `GameEventLogger` needs no constructor arguments of its own, so the default
     * is exactly the instance the container would inject and this class stays
     * constructible without one.
     */
    public function __construct(
        private readonly PlayerTokens $tokens,
        private readonly GameEventLogger $events = new GameEventLogger,
    ) {}

    /**
     * Inserts the Game and returns it.
     *
     * Returns the `Game` and nothing else. The Join_Code is already on the row
     * (`JoinCode::parse($game->join_code)` recovers the `XXXXX-XXXXX` form), so a
     * second return value would be a second source for one fact. The raw
     * Player_Token is deliberately not returned: it lives in the session, where
     * `GameResolver` reads it, and a returned token is one that can reach a
     * response body (Req 8.7).
     *
     * The token is issued once, before the retry loop, because
     * `PlayerTokens::issue()` writes the raw value to the session and assigns the
     * hash to the model without persisting it — the caller owns persistence, so
     * `save()` is the only write and runs once per attempt. Writing the session
     * before the row is the ordering that fails least badly: if every attempt
     * fails, no row exists, so the stale session key names no Game and
     * `GameResolver` answers `not_recognised` (Req 13.8).
     *
     * The retry is bounded. A Join_Code is 50 bits (2^50 ≈ 1.13 × 10^15 codes), so
     * a collision against a million live codes is under 10^-9 and two in a row is
     * that squared; the loop exists only so that a 10^-9 event is not a 500 on the
     * create path. On the third failure the exception is rethrown, because three
     * collisions at these odds is a broken generator rather than a busy database
     * and must not be retried forever. Note the id is generated once, outside the
     * loop: SQLite reports a primary-key collision as a unique-constraint
     * violation too, so a UUIDv7 collision would be retried with the same id and
     * then surface, which means the retry only repairs Join_Code collisions.
     *
     * @throws UniqueConstraintViolationException if every attempt collides
     */
    public function handle(): Game
    {
        $game = new Game;

        // Assigned one at a time because mass assignment is closed on this model
        // (nothing is `$fillable`), so every column written at creation is written
        // here, visibly. The id is generated in PHP, not by the database (Req 1.2);
        // the model deliberately does not generate its own — see `Game`'s docblock
        // for why identity generation lives in exactly one place.
        $game->id = Str::uuid7()->toString();
        $game->state = GameState::WaitingForOpponent;
        $game->version_counter = 0;
        $game->last_activity_at = now();

        // `join_code` is set inside the loop. `winning_mark` and
        // `rematch_of_game_id` are left unset, so the insert omits them and they
        // default to NULL ("both absent" in the design's constraint table), and
        // `o_token_hash` stays NULL, which is what `waiting_for_opponent` means
        // (Req 2.1) and what the CHECK on that state requires.
        $this->tokens->issue($game, Mark::X);

        $attempt = 1;

        while (true) {
            $game->join_code = JoinCode::generate()->stored;

            try {
                $game->save();

                // After the insert, which is what makes one record per created Game
                // (Req 10.3) rather than one per attempt: a collision throws before
                // this line, and a success leaves the loop immediately below it.
                $this->events->gameCreated($game->id);

                return $game;
            } catch (UniqueConstraintViolationException $collision) {
                if ($attempt >= self::MAX_INSERT_ATTEMPTS) {
                    throw $collision;
                }

                $attempt++;
            }
        }
    }
}
