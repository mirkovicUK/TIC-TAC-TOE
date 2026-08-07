<?php

declare(strict_types=1);

namespace App\Games;

/**
 * One freshly minted Player_Token, before anything has been done with it: the raw
 * 256-bit secret and its SHA-256, as two named properties.
 *
 * Named rather than returned as two bare strings because the two are not
 * interchangeable but are both `string` to PHP and PHPStan. `JoinGame` is where
 * that matters: it interpolates one of them into a guarded
 * `UPDATE ... SET o_token_hash = ?`, and the raw value there would write the
 * secret into the database (Req 8.7) with no type checker objecting.
 *
 * There is deliberately no `__toString()`, no `JsonSerializable` and no accessor
 * that dresses the raw value up as anything else, so `"...{$token}"` is a
 * `TypeError` at the point of the mistake rather than a silent Req 8.7 violation.
 *
 * `readonly` stops mutation, not disclosure: `raw` is a public property, so
 * `var_dump()`, `print_r()`, `json_encode()` and `dd()` would each print the
 * secret. The protection is situational — no instance is ever handed to a
 * serialiser. `PlayerTokens::mint()` returns one, `CreateGame` and `JoinGame` hold
 * one for a request and pass it to `PlayerTokens::remember()`, and there it ends;
 * it is never a prop, a response body, or a `GameEventLogger` payload (Req 10.4).
 * Adding serialisation here, or handing an instance to something that serialises,
 * is the change that turns a contained secret into a leaked one.
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
