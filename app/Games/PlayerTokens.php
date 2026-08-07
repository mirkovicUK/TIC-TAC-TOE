<?php

declare(strict_types=1);

namespace App\Games;

use App\Domain\TicTacToe\Mark;
use App\Models\Game;
use Illuminate\Support\Facades\Session;

/**
 * Issues, stores and verifies Player_Tokens: the whole of the credential scheme
 * of ADR-005, which has no accounts, no passwords and no tokens table.
 *
 * A Player_Token is 32 bytes from `random_bytes()` rendered as hex — 256 bits,
 * comfortably above Requirement 3.8's 128-bit floor from a cryptographically
 * secure source. The raw value lives in the server-side session and nowhere
 * else; its SHA-256 lives in one of two nullable columns on one `games` row.
 *
 * THE BINDING TO A (Game_Id, Mark) PAIR IS THE STORAGE LOCATION, AND NOTHING
 * ELSE (Req 3.1). There is no game id inside the token, nothing signed, nothing
 * to parse: the token is an opaque 256-bit secret, and the only fact recorded
 * about it is *which slot of which row* holds its hash. That is what makes
 * Requirement 3.4 — a token bound to another Game resolves to no Mark here —
 * true by construction rather than by a check that could be forgotten:
 * `resolve()` looks in one row, so a hash stored on a different row is not
 * merely rejected, it is not visible. A future change that puts the Game_Id
 * (or the Mark) inside the token value would replace a structural guarantee
 * with a parsing step and a comparison, and would need Requirement 3.4 tested
 * against every malformed and forged shape that step could accept. Do not.
 *
 * SHA-256 WITH NO WORK FACTOR IS CORRECT HERE, and is not an oversight to be
 * hardened later. A password needs a KDF precisely because it carries perhaps
 * 30 bits of entropy and is therefore guessable; a work factor buys time
 * against a guessing attack. This secret is 256 bits from `random_bytes()`, so
 * there is no guessing attack to slow down — the hash exists only so that a
 * database read does not hand out usable credentials. Substituting bcrypt or
 * argon2 would add latency to *every* `resolve()`, and `resolve()` is on the
 * polling path: two browsers polling every 2 seconds (Req 8.1) means a KDF
 * would run roughly once a second forever, for no attack it defends against.
 *
 * NO METHOD HERE LEAKS A RAW TOKEN (Req 8.7). Nothing in this class logs,
 * throws or renders a token value. Two methods hand one to a server-side
 * caller, and both are named here so the exceptions are visible rather than
 * implied: `mint()`, whose `MintedToken` a service holds for the duration of
 * one request and passes to `remember()` (see `MintedToken` for why that object
 * is never given to a serialiser), and `heldFor()`, which serves the resolve
 * path. Neither is reached by a serialiser. In particular
 * this class throws no exception at all — contrast
 * `CorruptMoveListException`, which carries a Game_Id in its message because a
 * Game_Id is not a secret; there is no equivalent diagnostic here into which a
 * token could be interpolated, and there must not be one added. A rejected
 * token is reported as `null`, which is all a caller needs and all Requirement
 * 9.6's single indistinguishable failure mode permits it to know.
 */
final class PlayerTokens
{
    /**
     * The session key prefix. One key per Game, so a browser holding tokens for
     * several Games keeps them apart, and `heldFor()` can ask about a Game_Id
     * whose row may no longer exist.
     */
    private const SESSION_PREFIX = 'player_tokens.';

    /**
     * Mints a Player_Token: 32 bytes from `random_bytes()` as hex, and its
     * SHA-256. NO SIDE EFFECTS — no model is touched, no session key written,
     * nothing persisted. It returns the pair and forgets it.
     *
     * THIS IS THE PRIMITIVE `JoinGame` (task 5.4) NEEDS, and the reason it
     * exists separately from `issue()`. `JoinGame` claims the O slot with a
     * conditional `UPDATE ... WHERE state = 'waiting_for_opponent' AND
     * o_token_hash IS NULL` whose affected-row count decides the outcome: 1
     * claims the slot, 0 is `game_full`. It cannot know which it is until after
     * the statement has run, and the statement needs the hash *in* it. So the
     * hash must exist before the outcome is known, while the session write must
     * happen only after — which is exactly the shape this method plus
     * `remember()` provide, and exactly the shape a single `issue()` cannot.
     *
     * The two values come back as a `MintedToken` rather than as a string plus a
     * separate hashing helper, so that the guarded UPDATE reads `$token->hash`
     * and could not have silently read the raw secret instead. That reasoning is
     * in `MintedToken`, along with why the object is never serialised.
     */
    public function mint(): MintedToken
    {
        $raw = bin2hex(random_bytes(32));

        return new MintedToken($raw, hash('sha256', $raw));
    }

    /**
     * Records `$token`'s raw value as the one this session holds for `$gameId`,
     * and does nothing else: no model touched, no hash stored.
     *
     * ONLY THE RAW VALUE IS WRITTEN, never the hash — the hash belongs on the
     * row, and the session is the only place the secret itself may live.
     *
     * CALL THIS LAST. It is the step that makes a credential real from the
     * browser's point of view, so on any path where the hash might not be
     * persisted — a guarded UPDATE that may lose, a transaction that may roll
     * back — the outcome must be known first. `JoinGame`'s losing request
     * therefore never calls this at all: it discards its `MintedToken` and
     * leaves the session untouched, so "no orphan credential exists" is a
     * consequence of the control flow rather than of a cleanup step that could
     * be skipped or fail.
     *
     * TAKES A GAME_ID STRING, NOT A `Game`, matching `heldFor()`. `JoinGame`
     * does have the row in hand and could pass it, but the id is the whole of
     * what this method depends on — the session is keyed by id and no attribute
     * of the row is consulted — and taking a `Game` would suggest otherwise. It
     * also keeps the two session-facing methods a matching pair: one writes the
     * key `heldFor()` reads, and both name it the same way.
     */
    public function remember(string $gameId, MintedToken $token): void
    {
        Session::put(self::SESSION_PREFIX.$gameId, $token->raw);
    }

    /**
     * Mints a token, sets its hash on `$game`'s slot for `$mark`, and puts the
     * raw value in the session: `mint()`, assign, `remember()`, composed for the
     * callers that have no losing path to worry about.
     *
     * THIS METHOD DOES NOT PERSIST `$game`. It assigns the attribute and leaves
     * the write to the caller, deliberately:
     *
     *   - `CreateGame` (task 5.2) inserts a *new* row. It assigns the rest of
     *     the row, calls this, and then `save()`s once. Were this method to
     *     save, that would be an INSERT followed by an UPDATE for one logical
     *     creation, and the game row would exist for an instant in a state the
     *     schema tolerates but the design does not intend.
     *   - `JoinGame` (task 5.4) claims the O slot with a *conditional* UPDATE
     *     whose affected-row count decides the outcome, and that statement must
     *     carry the hash itself. A `save()` in here would be a second,
     *     unguarded write racing the guarded one.
     *
     * So the contract is: **the caller is responsible for persistence, in the
     * same request.** That responsibility is not a detail. A half-issued token
     * locks a player out of their own Game permanently, and it fails in both
     * directions: a hash persisted with no session entry leaves a slot claimed
     * by a credential nobody holds, and a session entry whose hash was never
     * persisted leaves the player holding a secret that matches nothing. Since
     * a lost session cannot be recovered (ADR-005, Req 12.10), neither is
     * repairable — the Game is simply unplayable by that player.
     *
     * The ordering below is the one that fails least badly. The attribute is
     * assigned first and the session written second, so the only interleaving a
     * caller can produce is "session written, row never saved" — and if the
     * caller's transaction rolled back, the row does not exist either, so the
     * stale session key names no Game and `GameResolver` reports
     * `not_recognised` (Req 13.8) rather than authorising anything.
     *
     * WHY THIS METHOD SURVIVES ALONGSIDE THE PRIMITIVES. `CreateGame` (task 5.2)
     * inserts a fresh row: there is no other writer to race, no conditional
     * statement and therefore no losing path, so mint-assign-remember is
     * unconditionally correct there and the composition is what it wants.
     * `JoinGame` (task 5.4) uses `mint()` and `remember()` directly instead,
     * because it must know whether its guarded UPDATE won before it is safe to
     * write the session — and because it does, the session is never written on a
     * losing path and "no orphan credential exists" is structural rather than
     * dependent on a retraction. That is the whole reason the three methods are
     * shaped this way; forcing `CreateGame` through the primitives as well would
     * make the common case worse in exchange for nothing.
     */
    public function issue(Game $game, Mark $mark): void
    {
        $token = $this->mint();

        match ($mark) {
            Mark::X => $game->x_token_hash = $token->hash,
            Mark::O => $game->o_token_hash = $token->hash,
        };

        $this->remember($game->id, $token);
    }

    /**
     * The Mark `$presented` is bound to on `$game`, or null if it is bound to
     * neither slot of *this* row.
     *
     * Requirement 3.2: this is the only source of the acting Mark for any
     * request. No `mark` field in a payload is consulted anywhere (Req 3.6),
     * and there is no other way to obtain one.
     *
     * Only `$game`'s two columns are examined, which is what makes Requirement
     * 3.4 structural: a token minted for another Game hashes to a value stored
     * on that other row, and no query here can see it. See the class docblock.
     *
     * `hash_equals()` rather than `===`, so the comparison does not return
     * early on the first differing byte. On the argument order: the signature
     * is `hash_equals(string $known_string, string $user_string)`, and the
     * stored column is passed first to match it. Whether that matters here is
     * worth being precise about — it does not, and not by luck. The only
     * timing signal `hash_equals()` is documented to emit is on a *length*
     * mismatch, where it returns immediately; but both operands are SHA-256
     * hex digests, and `$presented` is hashed *before* comparison, so it
     * contributes 64 characters however long or short it arrived. There is no
     * user-controlled length to leak. The conventional order is used anyway,
     * because a reader should not have to reconstruct that argument to see that
     * the call is right.
     *
     * A null or empty `$presented` returns null without comparing: no request
     * presented a credential, so there is nothing to verify. A null slot is
     * skipped rather than compared — `o_token_hash` IS NULL is precisely "no
     * second player has joined" (Req 2.1), and it must not be reachable by
     * presenting an empty or null token.
     */
    public function resolve(Game $game, ?string $presented): ?Mark
    {
        if ($presented === null || $presented === '') {
            return null;
        }

        $hash = hash('sha256', $presented);

        $x = $game->x_token_hash;

        if ($x !== null && hash_equals($x, $hash)) {
            return Mark::X;
        }

        $o = $game->o_token_hash;

        if ($o !== null && hash_equals($o, $hash)) {
            return Mark::O;
        }

        return null;
    }

    /**
     * The raw Player_Token this session holds for `$gameId`, or null.
     *
     * TAKES A GAME_ID STRING, NOT A `Game`, AND MUST KEEP DOING SO. Two of the
     * seven rows of `GameResolver`'s visibility table (task 5.3) are "session
     * holds a token for the id, and there is no game row" — the expired case,
     * answered `game_expired` when an Expiry_Record exists (Req 13.6) and
     * `not_recognised` when it does not (Req 13.8). Reaching those rows means
     * asking the session about an id that resolves to no row, so a `Game`
     * parameter would be unobtainable at exactly the two places the question is
     * asked. The id is also all the answer depends on: the session is keyed by
     * id and nothing about the row is consulted.
     *
     * Returning the raw value is the one deliberate exception to "no token
     * value leaves this class". It stays server-side: the caller is
     * `GameResolver`, which passes it straight to `resolve()` and discards it.
     * It is never handed to `GameRepresentation`, never a prop, never a
     * response body (Req 8.7).
     */
    public function heldFor(string $gameId): ?string
    {
        $held = Session::get(self::SESSION_PREFIX.$gameId);

        return is_string($held) && $held !== '' ? $held : null;
    }
}
