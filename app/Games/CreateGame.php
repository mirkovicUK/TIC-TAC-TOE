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
 * ONE INSERT, NO SELECT. There is no read anywhere in this class — in
 * particular, no query asking whether a generated Join_Code is already taken.
 * That check would be a read-then-write race (two requests both find a code
 * free, both insert it, and one of them is wrong), and it would be a race whose
 * loser is silent. Requirement 1.4's "at most one Game per Join_Code" is carried
 * by `games_join_code_unique` instead, which is the only mechanism that can
 * carry it: a unique index has no window between the check and the write, and it
 * holds against writers that never ran this code at all.
 *
 * WHAT IS DELIBERATELY NOT HERE.
 *
 *   - **Logging.** The design's class table lists this class as "Insert game,
 *     mint X token, log" and cites Requirement 10.3. `GameEventLogger` is task
 *     10.x and does not exist yet, and this task's own criteria are 1.1–1.5 with
 *     no logging criterion among them. The `game.created` record belongs here
 *     when that class arrives; it is left out rather than approximated, because a
 *     stand-in written now would be a second writer of lifecycle records and the
 *     design gives `GameEventLogger` that role exclusively (Req 10.4's redaction
 *     is the reason it is exclusive).
 *   - **An empty Move_List (Req 1.1).** Nothing to do: a Move_List is the `moves`
 *     rows of a Game, not a column, so a Game with no `moves` rows *is* a Game
 *     with an empty Move_List. There is no empty value to write and no
 *     initialisation step that could be forgotten.
 *   - **Anything about the response.** No redirect, no representation, no prop.
 *     The controller at task 5.6 does that, and `GameRepresentation` (task 5.5)
 *     is the only serialiser.
 */
final class CreateGame
{
    /**
     * How many times an insert may be attempted before the collision is allowed
     * to surface. See `handle()` for the arithmetic that makes three generous.
     */
    private const int MAX_INSERT_ATTEMPTS = 3;

    public function __construct(
        private readonly PlayerTokens $tokens,
    ) {}

    /**
     * Inserts the Game and returns it.
     *
     * RETURNS THE `Game` AND NOTHING ELSE, which is everything task 5.6's
     * controller needs. It redirects to `GET /games/{game}` on the `id`, and the
     * Join_Code it must display is on the row — `JoinCode::parse($game->join_code)`
     * recovers the `XXXXX-XXXXX` form, so a second return value carrying the
     * display string would be a second source for one fact. The raw Player_Token
     * is deliberately NOT returned: it is in the session, where
     * `GameResolver` reads it from, and a token that is returned is a token that
     * can end up in a response body (Req 8.7).
     *
     * ORDER OF THE WRITES, AND WHY THE TOKEN IS ISSUED BEFORE THE LOOP.
     * `PlayerTokens::issue()` assigns the hash to the model and writes the raw
     * value to the session, and it does not persist the row — the caller owns
     * persistence, which is this method. Issuing once, outside the retry loop,
     * means one token exists for one Game however many insert attempts it takes;
     * issuing inside would mint a fresh secret per attempt and leave the session
     * holding the last one, which is correct but pointlessly noisy.
     *
     * The session is therefore written before the row is saved, and that is the
     * ordering `PlayerTokens` documents as the one that fails least badly: if
     * every attempt fails and the exception propagates, no row exists, so the
     * stale session key names no Game and `GameResolver` answers
     * `not_recognised` (Req 13.8) rather than authorising anything.
     *
     * `save()` RUNS ONCE PER ATTEMPT and is the only write. `issue()` not
     * persisting is what makes that true — were it to save, one logical creation
     * would be an INSERT followed by an UPDATE, and the row would exist for an
     * instant with no token.
     *
     * THE COLLISION PATH, and why it is a bounded retry rather than nothing at
     * all. A Join_Code is 50 bits, so 2^50 ≈ 1.13 × 10^15 codes. The chance that
     * one freshly generated code collides is the number of codes currently
     * stored divided by that: with a million live Join_Codes it is under
     * 10^-9, and the sweep (Req 13.1–13.3) keeps the real number orders of
     * magnitude below a million. Two independent collisions in a row is that
     * squared. So this loop is not a practical concern — it exists because the
     * alternative is that a 10^-9 event is a 500 on the create path, and one
     * regenerated code costs nothing.
     *
     * The retry is BOUNDED and the exhaustion path is explicit: on the third
     * failure the exception is rethrown rather than looped on. A caller then
     * sees `UniqueConstraintViolationException`, which is an unhandled 500 — the
     * honest answer, because three collisions at these odds is not a busy
     * database, it is a broken generator, and a broken generator must not be
     * quietly retried forever.
     *
     * One consequence of the id being generated ONCE, outside the loop, is worth
     * naming: SQLite reports a primary-key collision as a unique-constraint
     * violation too, so a UUIDv7 id collision would be retried three times with
     * the same id and then surface. That is the right outcome — ~74 random bits
     * of id do not collide, and if they do, a new Join_Code is not the fix — but
     * it does mean the retry only genuinely repairs Join_Code collisions.
     *
     * @throws UniqueConstraintViolationException if every attempt collides
     */
    public function handle(): Game
    {
        $game = new Game;

        // Assigned one at a time because mass assignment is closed on this model
        // (nothing is `$fillable`), which is the point of closing it: every
        // column written at creation is written here, visibly.
        //
        // The id is generated in PHP, not by the database (Req 1.2). UUIDv7 is
        // time-ordered with ~74 random bits and derives from no monotonically
        // increasing sequence. The model deliberately does not generate its own
        // id — see `Game`'s docblock for why identity generation lives in exactly
        // one place.
        $game->id = Str::uuid7()->toString();
        $game->state = GameState::WaitingForOpponent;
        $game->version_counter = 0;
        $game->last_activity_at = now();

        // `join_code` is set inside the loop; `winning_mark` and
        // `rematch_of_game_id` are left unset, so the insert omits them and they
        // default to NULL — which is what the design's constraint-satisfaction
        // table expects of this path ("both absent"). `o_token_hash` stays NULL
        // too, which is what `waiting_for_opponent` means (Req 2.1) and what the
        // CHECK on that state requires.
        $this->tokens->issue($game, Mark::X);

        $attempt = 1;

        while (true) {
            $game->join_code = JoinCode::generate()->stored;

            try {
                $game->save();

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
