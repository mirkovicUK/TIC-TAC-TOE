# ADR-005: Per-game, per-mark tokens instead of accounts

Bears on Requirements 3.1, 8.7 and 12.10.

## Decision

No user accounts. A 256-bit Player_Token bound to one `(Game_Id, Mark)` pair, stored hashed
on the Game row and held raw in the server-side session.

## Alternatives considered

| Alternative | Why not |
| --- | --- |
| Registration and login | Out of scope, and would dominate a few hours' budget |
| Signed stateless claims in a cookie | A key to manage and a revocation story, for a credential that outlives nothing |
| Trusting the Join_Code as the credential | The failure Requirement 3 exists to prevent |

## Reason

Treating the Join_Code as the credential is the specific hole this scheme closes: a third
party holding the code could otherwise play another person's moves.

The binding is the slot, not the token's contents. `PlayerTokens::mint()` produces
`bin2hex(random_bytes(32))` — 256 bits with no structure and no counter — and nothing in the
token names the Game or the Mark. Which column the hash is written to is what binds it
(Req 3.1).

The accepted cost is that a lost session cannot be recovered. The README states that plainly,
and states that it follows from the deliberate absence of accounts (Req 12.10).

## In practice

The credential class had to be split during implementation. A single `issue()` that minted
and wrote the session in one step could not serve the joining path: `JoinGame` must carry the
hash *inside* the guarded UPDATE whose affected-row count decides the outcome, so the hash
exists before the outcome is known. `issue()` would therefore have written a credential into
the browser for a slot the request went on to lose.

`mint()` now returns a `MintedToken` with no side effects and `remember()` is the session
write alone, so no orphan credential exists because nothing wrote one — not because something
cleaned one up. `issue()` survives as the composition of both, for `CreateGame`, which inserts
a fresh row with no competing writer and therefore no losing path.

`mint()` returns a two-property object rather than a raw string plus a hashing helper because
both values are `string` to PHP and to PHPStan, and a transposition would write the secret
into the database at exactly the point Requirement 8.7 exists to prevent.
