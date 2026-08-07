<?php

declare(strict_types=1);

namespace App\Games;

/**
 * One freshly minted Player_Token, before anything has been done with it: the
 * raw 256-bit secret and its SHA-256, as two NAMED properties.
 *
 * WHY A VALUE OBJECT RATHER THAN `mint(): string` PLUS A PUBLIC
 * `hashOf(string): string`. The two strings are not interchangeable — one is a
 * secret the session holds, the other is what a `games` row may store — but to
 * PHP and to PHPStan they are both `string`, so nothing would distinguish them
 * at a call site. `JoinGame` (task 5.4) is where that matters: it interpolates
 * one of the two into a guarded `UPDATE ... SET o_token_hash = ?`, and putting
 * the RAW value there would write the secret straight into the database — the
 * exact disclosure Requirement 8.7 exists to prevent, in the exact place
 * Requirement 3.1's binding is established, and no type checker would say a
 * word. `$token->hash` in that statement is right and `$token->raw` in it is
 * visibly wrong; two bare strings named `$hash` and `$raw` in the same scope
 * are one transposition away from the leak and read identically afterwards.
 *
 * THIS CLASS DELIBERATELY OFFERS NO WAY TO STRINGIFY OR SERIALISE ITSELF. There
 * is no `__toString()`, it does not implement `JsonSerializable`, and it has no
 * accessor that dresses the raw value up as anything other than what it is. A
 * token that can be stringified is a token that can be accidentally logged or
 * rendered: `"...{$token}"` in a log line, `json_encode($token)` in a response,
 * a Blade `{{ $token }}` — each of which would be a silent Requirement 8.7
 * violation if the class cooperated. Without `__toString()` the first of those
 * is a `TypeError` at the point of the mistake, which is where a mistake should
 * surface.
 *
 * BE CLEAR ABOUT WHAT THAT DOES *NOT* PROTECT. `readonly` stops mutation, not
 * disclosure, and `raw` is a public property: `var_dump()`, `print_r()`,
 * `json_encode()` and Laravel's `dd()` all walk public properties and would
 * every one of them print the secret. Nothing in this class prevents that, and
 * no `#[SensitiveParameter]` or magic method can — the protection is
 * situational, and it is this: NO INSTANCE OF THIS CLASS IS EVER HANDED TO A
 * SERIALISER. `PlayerTokens::mint()` returns one, `JoinGame` and `CreateGame`
 * hold one for the duration of a request and pass it to
 * `PlayerTokens::remember()`, and there it ends. `GameRepresentation` (task
 * 5.5) never sees one; it is not a prop, not a response body, not an event
 * payload for `GameEventLogger`, whose records are redacted (Req 10.4). One
 * mercy from PHP is that a stack trace renders an argument object as
 * `Object(App\Games\MintedToken)` rather than as its properties, so an
 * exception thrown while one is in flight does not spill it — but that is a
 * detail of the trace formatter, not a guarantee to rely on. Adding
 * serialisation to this class, or handing an instance to something that
 * serialises, is the change that turns a contained secret into a leaked one.
 */
final readonly class MintedToken
{
    /**
     * The two properties are NOT independent: `$hash` must be
     * `hash('sha256', $raw)` or the pair is a lie, and the lie is unplayable
     * rather than merely wrong — a session holding a raw value whose digest was
     * never the one stored on the row matches nothing, forever, and a lost
     * session cannot be recovered (ADR-005, Req 12.10).
     *
     * The constructor is public and does not enforce that, because the
     * enforcement worth having is that there is exactly ONE producer:
     * `PlayerTokens::mint()`, which computes both from one `random_bytes()`
     * call. Nothing else in the application constructs one, and nothing else
     * should; a second producer is a second chance to pair a raw value with
     * the wrong digest.
     */
    public function __construct(
        public string $raw,
        public string $hash,
    ) {}
}
