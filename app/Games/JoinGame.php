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
 * THE AFFECTED-ROW COUNT DECIDES THE OUTCOME, AND NOTHING IN PHP RE-CHECKS IT.
 * `1` means this request claimed the slot; `0` means the row no longer satisfies
 * the guard — someone else claimed it, or the Game moved on — and the caller
 * receives the same `game_full` that Requirement 2.3 specifies for a Game which
 * already has two Players (Req 2.7). The design's error table records that zero
 * rows is "not an error condition — the expected loser path", and it is treated
 * as one here: no exception, no retry, no log of a failure.
 *
 * A `SELECT` OF `state` FOLLOWED BY AN UNGUARDED `UPDATE` WOULD BE THE SAME CODE
 * WITH THE RACE PUT BACK. Two requests would both read `waiting_for_opponent`,
 * both pass the check, and both write — the second silently overwriting the
 * first's `o_token_hash` and leaving a Player holding a credential that matches
 * nothing, unrecoverably (ADR-005, Req 12.10). There is no window here because
 * the condition and the write are one statement, which is precisely why
 * Requirement 2.7 is satisfiable without a transaction, a lock or a retry loop.
 * Do not add a state guard in PHP "for clarity": the guard would be
 * unfalsifiable in the failing case and would make the statement look optional.
 *
 * ORDER OF OPERATIONS, AND WHY IT IS THE ONLY ORDER AVAILABLE.
 *
 *   1. Normalise the submitted string to a `JoinCode`. Unparseable → answer.
 *   2. Look the row up by `join_code`. No row → `not_recognised` (Req 2.2).
 *   3. *Then* the session short-circuit, which needs that row in hand: deciding
 *      whether this session already holds a token for the Game means comparing a
 *      session value against the two hash columns, and those columns are on the
 *      row step 2 fetched. So "short-circuits first" means before the UPDATE, not
 *      before the lookup — the lookup is what makes the short-circuit expressible.
 *   4. Mint, then run the guarded UPDATE, then — only if it won — write the
 *      session.
 *
 * WHAT IS DELIBERATELY NOT HERE.
 *
 *   - **Logging.** The design's class table lists this class as "…, mint O token,
 *     log" and cites Requirement 10.3, but `GameEventLogger` is task 10.x and
 *     does not exist yet; this task's criteria are 2.1–2.7 with no logging
 *     criterion among them. The `game.joined` record belongs here when that class
 *     arrives, and is left out rather than approximated, because the design gives
 *     `GameEventLogger` the role of sole writer of lifecycle records exclusively
 *     (Req 10.4's redaction is why it is exclusive) — and a stand-in here would be
 *     the one place a Join_Code is in scope next to a logger (Req 10.5).
 *   - **Anything about the response.** No redirect, no status, no representation.
 *     `JoinOutcome` carries no HTTP status for the same reason `VisibilityOutcome`
 *     does not: the design's outcome table puts both rejections at 303 → `/join`
 *     and an accepted join at 303 → the game page, and that mapping is task 5.6's.
 *     Nothing in `App\Games` knows what an HTTP status is.
 *   - **A rate limit.** `throttle:join` is route middleware (Req 10.6), applied at
 *     task 10.x. A limiter inside a service would be untestable from the transport
 *     and would fire on a path no HTTP request took.
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
     * THE RETURN TYPE IS A UNION, AND IT REUSES `ResolvedPlayer` RATHER THAN
     * INTRODUCING A SECOND (Game, Mark) PAIR. Three things had to be true for that
     * to be honest rather than merely convenient, and they are:
     *
     *   - **The success value means the same thing here as it does there.** An
     *     instance of `ResolvedPlayer` is the statement "this session is an
     *     authorised Player of this Game, holding a Player_Token bound to this
     *     Mark". On the accepted-join path that is true the moment `remember()`
     *     returns — a `GameResolver::resolve()` call made immediately afterwards,
     *     in the same session, would construct an *equal* instance from the
     *     persisted row. On the short-circuit path it is not merely equivalent but
     *     identical in origin: the Mark comes from `PlayerTokens::resolve()`, the
     *     same call `GameResolver` makes. The two classes are not two shapes that
     *     happen to match; they carry one fact, established here and observed
     *     there.
     *   - **The rejection type had to be new regardless.** `VisibilityOutcome` has
     *     no `game_full` case and must not gain one — it is exactly the seven-row
     *     visibility table — so `JoinOutcome` exists whichever success type is
     *     chosen. Given a purpose-built rejection type, a purpose-built success
     *     type would buy nothing: the structural guarantee that matters (a
     *     rejection cannot carry game state) comes from the *union with a
     *     fieldless enum*, not from the identity of the success class.
     *   - **A duplicate would have to be converted.** Task 5.6's controllers and
     *     `ResolveActingPlayer` already speak `ResolvedPlayer`; a `JoinedGame` with
     *     the same two fields would be translated to it at the boundary, and a
     *     translation between two identical types is a place for the Mark and the
     *     Game to be paired wrongly.
     *
     * The alternative deliberately rejected is the shape this is not: one result
     * object with an outcome plus a nullable `?Game` and `?Mark`. Every rejection
     * would then carry the row it refused to show, and Requirement 3.10 would rest
     * on every caller checking the outcome before reading the fields.
     *
     * TAKES `mixed`, NOT `string`, for the reason the design gives for
     * `cell_index`: the check that turns "this cannot be a Join_Code" into an
     * outcome belongs where the outcome vocabulary lives. A `string` parameter
     * would force task 5.6 to cast `join_code` out of the request, and a body
     * carrying a non-string — `{"join_code": ["x"]}`, which any prober can send —
     * would then be either a `TypeError` (a 500 for a value a user supplied) or a
     * Laravel validation payload (a second vocabulary for one condition).
     * Requirement 2.2's "matches no Game" covers it here, once, in the same value
     * as every other unmatched code.
     *
     * @param  mixed  $submitted  The Join_Code as the request carried it, in any
     *                            transcription and of any type.
     */
    public function handle(mixed $submitted): ResolvedPlayer|JoinOutcome
    {
        // Normalisation is `JoinCode::parse()`'s job and lives there with its
        // inverse, `display()`: upper-cased, hyphens stripped, I/L folded to 1 and
        // O to 0 the way Crockford's decoder folds them. A second normaliser in
        // this class would be an inverse implemented twice, and the failure mode
        // of the two drifting is that codes are generated and displayed which can
        // never be joined.
        //
        // A non-string, and a string that is not a well formed Join_Code, are the
        // SAME ANSWER as a well formed code matching no row. Requirement 2.2 draws
        // no distinction, and drawing one would tell a prober which codes are
        // worth trying: a distinguishable "wrong shape" reply is a free oracle for
        // the code space, at no cost to the prober and no benefit to a player, who
        // gets a message to retype their code either way.
        $code = is_string($submitted) ? JoinCode::parse($submitted) : null;

        if ($code === null) {
            return JoinOutcome::NotRecognised;
        }

        // The stored form is the unhyphenated ten characters, which is why
        // `JoinCode` insists the column holds that form: a normalised
        // ten-character lookup can never match an eleven-character stored value.
        // The unique index (Req 1.4) is what makes `first()` unambiguous rather
        // than "one of the matches".
        $game = Game::query()->where('join_code', $code->stored)->first();

        if ($game === null) {
            return JoinOutcome::NotRecognised;
        }

        // SHORT-CIRCUIT (Req 2.4, 2.5). A session already holding a valid
        // Player_Token for this Game gets the Game back with the Mark that token
        // is bound to, and NOTHING IS WRITTEN: no second Player, no state change,
        // no Version_Counter increment. That is one branch covering two criteria —
        // 2.5 is the Creator pasting their own Join_Code, which is 2.4 with the
        // Mark happening to be X — and it must come before the UPDATE, because
        // reaching the UPDATE at all would either claim the O slot for a Player who
        // already holds X, or answer `game_full` to a Player of the Game.
        //
        // It cannot come before the lookup: the question "does this session hold a
        // token for this Game" is answered by comparing a session value against
        // the two hash columns of the row, and the row is what the lookup fetched.
        // The session read itself is keyed by Game_Id, which is equally only known
        // once the code has been resolved to a row.
        $held = $this->tokens->resolve($game, $this->tokens->heldFor($game->id));

        if ($held !== null) {
            return new ResolvedPlayer($game, $held);
        }

        // Minted BEFORE the statement, because the statement must carry the hash
        // and the outcome is not known until it has run. This is why
        // `PlayerTokens` separates `mint()` from `remember()` and why this class
        // must not use `issue()`: `issue()` writes the session as part of minting,
        // which on the losing path below would leave the browser holding a
        // credential for a slot it never claimed.
        $token = $this->tokens->mint();

        // ONE STATEMENT. The three WHERE conditions are the whole of the
        // concurrency control, and `version_counter + 1` is an expression the
        // database evaluates (Req 2.6) — never a value read into PHP and
        // incremented, which would be a read-modify-write with a window of its own.
        //
        // Eloquent's builder rather than `DB::table()` so that `updated_at` is
        // maintained the way every other write to this row maintains it; the
        // enums are passed as their backing values because it is the stored
        // representation the guard compares.
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

        // THE LOSING PATH WRITES NOTHING AND RETURNS HERE. `$token` goes out of
        // scope unremembered, so "no orphan credential exists" is a consequence of
        // the control flow rather than of a cleanup step that could be skipped,
        // fail, or be forgotten by whoever adds the next branch above it. Note
        // what is NOT here: no session key to unset, no hash to null out, no
        // compensating UPDATE.
        if ($claimed === 0) {
            return JoinOutcome::GameFull;
        }

        // Won. The hash is persisted, so the credential is now real and the
        // session write is safe — `remember()`'s "call this last" in the order the
        // conditional path requires.
        $this->tokens->remember($game->id, $token);

        // Re-read so the returned model agrees with the row: the guarded UPDATE
        // went through the query builder, which knows nothing of this instance, so
        // `$game` still holds `waiting_for_opponent` and the old Version_Counter.
        //
        // THIS READ IS AFTER THE DECISION, NOT BEFORE IT, and it is not a re-check
        // — the outcome was settled by `$claimed` two statements ago and nothing
        // below can change it. The alternative, assigning the new values to the
        // model by hand, would mean writing `version_counter + 1` a second time in
        // PHP and asserting the result rather than reading it; that arithmetic
        // happens to be right today only because no other writer can touch a
        // waiting Game, which is a fact about the rest of the application rather
        // than about this line. One read on a path taken once per Game is cheap,
        // and it is not the polling path.
        $game->refresh();

        // `Mark::O` is a constant, not a lookup: this class claims exactly one
        // slot, and `o_token_hash` is the column the statement above wrote. The
        // Mark is still bound by storage location and by nothing else (Req 3.1).
        return new ResolvedPlayer($game, Mark::O);
    }
}
