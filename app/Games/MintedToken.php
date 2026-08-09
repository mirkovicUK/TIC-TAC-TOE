<?php

declare(strict_types=1);

namespace App\Games;

/**
 * One freshly minted Player_Token: the raw 256-bit secret and its SHA-256, as two
 * named properties.
 *
 * Named rather than two bare strings because they are not interchangeable and are
 * both `string` to PHP and PHPStan. `JoinGame` interpolates one into a guarded
 * `UPDATE ... SET o_token_hash = ?`, where the raw value would write the secret
 * into the database (Req 8.7) with no type checker objecting.
 *
 * No `__toString()`, no `JsonSerializable`, no accessor — so `"...{$token}"` is a
 * `TypeError` at the mistake rather than a silent Req 8.7 violation.
 *
 * `readonly` stops mutation, not disclosure: `raw` is public, so `var_dump()`,
 * `json_encode()` or `dd()` would print the secret. What protects it is that no
 * instance is ever handed to a serialiser — `mint()` returns one, a request passes
 * it to `remember()`, and there it ends. Never a prop, a response body or a log
 * payload (Req 10.4).
 */
final readonly class MintedToken
{
    /**
     * The two properties are not independent: `$hash` must be
     * `hash('sha256', $raw)` or the pair is unplayable — a session holding a raw
     * value whose digest was never stored matches nothing, forever, and a lost
     * session cannot be recovered (ADR-005, Req 12.10).
     *
     * The constructor does not enforce that, because the enforcement worth having
     * is that there is exactly one producer: `PlayerTokens::mint()`, which computes
     * both from one `random_bytes()` call. A second producer is a second chance to
     * pair a raw value with the wrong digest.
     */
    public function __construct(
        public string $raw,
        public string $hash,
    ) {}
}
