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

## How a request is authorised

Every step, in order. A move is only reached if all of them pass.

```
1. cookie → decrypt → session id → SELECT payload FROM sessions

2. game id from URL → key into payload → raw secret
                                       (or null → refuse 403)

3. SELECT * FROM games WHERE id = <game id from URL>
                                       (no row → 404 or 410)

4. sha256(secret) vs x_token_hash, then o_token_hash
       no match  → refuse 403
       match     → you are X or O, whichever column matched

5. only now: the move
```

Two things follow from step 4. The token carries no identity of its own — nothing in it names
the Game or the Mark — so **which column the hash matches is what makes you X or O**. And the
Join_Code appears nowhere in this path, which is the hole the scheme closes: a third party
holding the code cannot play another person's moves.

The accepted cost is that a lost session cannot be recovered. There is no step 1 without the
cookie, and no account to recover through. The README states that (Req 12.10).
