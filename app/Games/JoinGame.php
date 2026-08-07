<?php

declare(strict_types=1);

namespace App\Games;

use App\Domain\TicTacToe\Mark;
use App\Models\Game;
use Illuminate\Support\Facades\DB;

/**
 * Claims the O slot of the Game a submitted Join_Code names: one guarded UPDATE
 * whose affected-row count is the whole of the concurrency control (Req 2.1–2.7,
 * ADR-006).
 *
 * ```sql
 * UPDATE games
 *    SET state = 'active',
 *        o_token_hash = :hash,
 *        version_counter = version_counter + 1,
 *        last_activity_at = :now
 *  WHERE id = :id
 *    AND state = 'waiting_for_opponent'
 *    AND o_token_hash IS NULL;
 * ```
 *
 * The affected-row count decides the outcome and nothing in PHP re-checks it.
 * `1` claimed the slot; `0` means the row no longer satisfies the guard, and the
 * caller gets the same `game_full` Requirement 2.3 specifies for a Game with two
 * Players (Req 2.7). Zero rows is the expected loser path: no exception, no
 * retry, no failure log.
 *
 * A `SELECT` of `state` followed by an unguarded `UPDATE` is the same code with
 * the race put back: two requests both read `waiting_for_opponent`, both pass,
 * both write, and the second overwrites the first's `o_token_hash`, leaving a
 * Player holding a credential that matches nothing, unrecoverably (ADR-005, Req
 * 12.10). Condition and write being one statement is why Requirement 2.7 needs
 * no transaction, lock or retry loop. Do not add a state guard in PHP "for
 * clarity" — it would be unfalsifiable in the failing case and make the
 * statement look optional.
 *
 * The order — parse, look up by `join_code`, session short-circuit, then mint and
 * the guarded UPDATE — is the only one available: the short-circuit compares a
 * session value against the row's two hash columns, so it can come before the
 * UPDATE but not before the lookup.
 *
 * No logging here; the `game.joined` record belongs to `GameEventLogger` (task
 * 10.x), sole writer of lifecycle records because of Requirement 10.4's
 * redaction. Nothing about the response either — task 5.6 maps outcomes to
 * statuses, and `throttle:join` is route middleware (Req 10.6).
 */
final class JoinGame
{
    public function __construct(
        private readonly PlayerTokens $tokens,
    ) {}

    /**
     * Resolves `$submitted` to a Game and either makes this session its O Player
     * or refuses, with nothing in between.
     *
     * The union reuses `ResolvedPlayer` because it carries the same fact
     * `GameResolver` would establish from the persisted row — on the short-circuit
     * path from the very same `PlayerTokens::resolve()` call. The rejection type is
     * separate because `VisibilityOutcome` is exactly the seven-row visibility
     * table and must not gain a `game_full` case. What keeps Requirement 3.10
     * structural is the union with a fieldless enum: a rejection has nowhere to put
     * a Game.
     *
     * Takes `mixed`, not `string`: a body carrying `{"join_code": ["x"]}` would
     * otherwise be a `TypeError` (a 500 for a user-supplied value) or a validation
     * payload (a second vocabulary for one condition). Requirement 2.2's "matches
     * no Game" covers it here.
     *
     * @param  mixed  $submitted  The Join_Code as the request carried it, in any
     *                            transcription and of any type.
     */
    public function handle(mixed $submitted): ResolvedPlayer|JoinOutcome
    {
        // Normalisation belongs to `JoinCode::parse()`, next to its inverse
        // `display()`. A second normaliser here would be an inverse implemented
        // twice, and the two drifting means codes that can never be joined.
        //
        // A non-string, a malformed code and a well formed code matching no row all
        // get the same answer. Requirement 2.2 draws no distinction, and a
        // distinguishable "wrong shape" reply would be a free oracle over the code
        // space for no benefit to a player.
        $code = is_string($submitted) ? JoinCode::parse($submitted) : null;

        if ($code === null) {
            return JoinOutcome::NotRecognised;
        }

        // The column holds the unhyphenated ten characters: a normalised
        // ten-character lookup can never match an eleven-character stored value.
        // The unique index (Req 1.4) is what makes `first()` unambiguous.
        $game = Game::query()->where('join_code', $code->stored)->first();

        if ($game === null) {
            return JoinOutcome::NotRecognised;
        }

        // Short-circuit (Req 2.4, 2.5): a session already holding a valid
        // Player_Token gets the Game back with that token's Mark and nothing is
        // written — no second Player, no state change, no Version_Counter
        // increment. One branch covers both criteria, since 2.5 is 2.4 with the
        // Mark happening to be X. It must precede the UPDATE, which would otherwise
        // claim the O slot for a Player already holding X, or answer `game_full` to
        // a Player of the Game.
        $held = $this->tokens->resolve($game, $this->tokens->heldFor($game->id));

        if ($held !== null) {
            return new ResolvedPlayer($game, $held);
        }

        // Minted before the statement, because the statement must carry the hash and
        // the outcome is not known until it has run. This is why `PlayerTokens`
        // separates `mint()` from `remember()` and why this class must not use
        // `issue()`: `issue()` writes the session while minting, which on the losing
        // path below would leave the browser holding a credential for a slot it
        // never claimed.
        $token = $this->tokens->mint();

        // One statement. The three WHERE conditions are the whole of the concurrency
        // control, and `version_counter + 1` is an expression the database evaluates
        // (Req 2.6) — never read into PHP and incremented, which would be a
        // read-modify-write with a window of its own.
        //
        // Eloquent's builder rather than `DB::table()` so `updated_at` is maintained
        // as it is for every other write to this row; the enums go in as their
        // backing values, the stored representation the guard compares.
        $claimed = Game::query()
            ->whereKey($game->id)
            ->where('state', GameState::WaitingForOpponent->value)
            ->whereNull('o_token_hash')
            ->update([
                'state' => GameState::Active->value,
                'o_token_hash' => $token->hash,
                'version_counter' => DB::raw('version_counter + 1'),
                'last_activity_at' => now(),
            ]);

        // The losing path writes nothing: `$token` goes out of scope unremembered,
        // so "no orphan credential exists" follows from the control flow rather than
        // from a cleanup step that could be skipped or forgotten by whoever adds the
        // next branch above. No session key to unset, no compensating UPDATE.
        if ($claimed === 0) {
            return JoinOutcome::GameFull;
        }

        // Won, so the hash is persisted and the session write is safe —
        // `remember()`'s "call this last" on the conditional path.
        $this->tokens->remember($game->id, $token);

        // Re-read so the returned model agrees with the row: the guarded UPDATE went
        // through the query builder, which knows nothing of this instance, so `$game`
        // still holds `waiting_for_opponent` and the old Version_Counter.
        //
        // After the decision, not before it, and not a re-check — `$claimed` settled
        // the outcome. Assigning the new values by hand instead would mean computing
        // `version_counter + 1` a second time in PHP and asserting rather than
        // reading it. One read on a once-per-Game path, not the polling path.
        $game->refresh();

        // `Mark::O` is a constant, not a lookup: this class claims exactly one slot,
        // and `o_token_hash` is the column the statement above wrote. The Mark is
        // still bound by storage location and nothing else (Req 3.1).
        return new ResolvedPlayer($game, Mark::O);
    }
}
