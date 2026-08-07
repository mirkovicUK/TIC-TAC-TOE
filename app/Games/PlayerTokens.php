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
 * The binding to a (Game_Id, Mark) pair is the storage location and nothing
 * inside the token (Req 3.1): the token is an opaque secret, and the only fact
 * recorded about it is which slot of which row holds its hash. That is what
 * makes Requirement 3.4 structural — `resolve()` looks in one row, so a hash
 * stored on another row is not visible at all, not merely rejected. Putting the
 * Game_Id or Mark inside the token value would swap that guarantee for a parsing
 * step and a comparison. Do not.
 *
 * SHA-256 with no work factor is correct here, not something to harden later. A
 * KDF buys time against guessing a low-entropy password; this secret is 256 bits
 * from `random_bytes()`, and the hash exists only so a database read does not
 * hand out usable credentials. `resolve()` is on the polling path (Req 8.1), so
 * bcrypt or argon2 would add latency roughly once a second forever against no
 * attack.
 *
 * No method here leaks a raw token (Req 8.7): nothing logs, renders, or throws
 * one — this class throws no exception at all, so there is no diagnostic to
 * interpolate a token into, and none may be added. `mint()` and `heldFor()`
 * return one to a server-side caller only. A rejected token is reported as
 * `null`, which is all Requirement 9.6's single indistinguishable failure mode
 * permits a caller to know.
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
     * SHA-256. No side effects — nothing touched, nothing persisted.
     *
     * Separate from `issue()` because `JoinGame`'s guarded UPDATE must carry the
     * hash *in* the statement whose affected-row count decides the outcome. So
     * the hash has to exist before the outcome is known and the session write
     * has to happen only after, which `mint()` plus `remember()` allow and a
     * single `issue()` cannot.
     *
     * The pair comes back as a `MintedToken` so the guarded UPDATE reads
     * `$token->hash` and could not silently read the raw secret instead.
     */
    public function mint(): MintedToken
    {
        $raw = bin2hex(random_bytes(32));

        return new MintedToken($raw, hash('sha256', $raw));
    }

    /**
     * Records `$token`'s raw value as the one this session holds for `$gameId`.
     * Only the raw value is written, never the hash: the hash belongs on the row,
     * and the session is the only place the secret itself may live.
     *
     * Call this last. It is what makes a credential real from the browser's point
     * of view, so on any path where the hash might not be persisted — a guarded
     * UPDATE that may lose, a transaction that may roll back — the outcome must
     * be known first. `JoinGame`'s loser never calls it, which is why no orphan
     * credential exists without a cleanup step.
     *
     * Takes a Game_Id string, matching `heldFor()`: the session is keyed by id
     * and no attribute of the row is consulted.
     */
    public function remember(string $gameId, MintedToken $token): void
    {
        Session::put(self::SESSION_PREFIX.$gameId, $token->raw);
    }

    /**
     * Mints a token, sets its hash on `$game`'s slot for `$mark`, and puts the
     * raw value in the session — the composition for callers with no losing path.
     * `CreateGame` inserts a fresh row and can use it; `JoinGame` cannot, because
     * it must know whether its guarded UPDATE won before writing the session.
     *
     * This method does not persist `$game`; the caller must, in the same request.
     * A half-issued token is unrepairable in both directions — a persisted hash
     * with no session entry leaves a slot nobody can claim, a session entry whose
     * hash was never persisted leaves a secret matching nothing — and a lost
     * session cannot be recovered (ADR-005, Req 12.10).
     *
     * The attribute is assigned before the session write so the only interleaving
     * a caller can produce is "session written, row never saved". If its
     * transaction rolled back the row does not exist either, so the stale key
     * names no Game and `GameResolver` reports `not_recognised` (Req 13.8).
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
     * `hash_equals()` rather than `===`, so the comparison does not return early
     * on the first differing byte, with the stored column first to match
     * `hash_equals(string $known_string, string $user_string)`. `$presented` is
     * hashed before comparison, so it contributes 64 characters however long it
     * arrived and there is no user-controlled length to leak.
     *
     * A null or empty `$presented` returns null without comparing, and a null slot
     * is skipped rather than compared: `o_token_hash IS NULL` is precisely "no
     * second player has joined" (Req 2.1) and must not be matchable by presenting
     * an empty token.
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
     * Takes a Game_Id string, not a `Game`, and must keep doing so: two rows of
     * `GameResolver`'s visibility table are "session holds a token for the id and
     * there is no game row" — `game_expired` when an Expiry_Record exists (Req
     * 13.6), `not_recognised` when it does not (Req 13.8) — so a `Game` would be
     * unobtainable exactly where the question is asked.
     *
     * Returning the raw value is the one deliberate exception to "no token value
     * leaves this class", and it stays server-side: `GameResolver` passes it
     * straight to `resolve()` and discards it. Never a prop, never a response body
     * (Req 8.7).
     */
    public function heldFor(string $gameId): ?string
    {
        $held = Session::get(self::SESSION_PREFIX.$gameId);

        return is_string($held) && $held !== '' ? $held : null;
    }
}
