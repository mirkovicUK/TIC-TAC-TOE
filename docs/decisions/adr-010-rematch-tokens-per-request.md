# ADR-010: Rematch tokens are minted per request, not at creation

Bears on Requirements 3.1, 7.3, 7.6, 7.7 and 7.8.

## Decision

A Rematch is created with no tokens. Each player's token is minted when that player's session
presents a valid Player_Token for the preceding Game. The "go to rematch" control is a POST to
the same idempotent endpoint rather than a link.

## Alternatives considered

| Alternative | Why not |
| --- | --- |
| Minting both tokens when the Rematch is created | The server cannot write a token into the absent player's browser, so one credential would be one nobody can ever hold |
| A longer-lived cross-game token carrying identity | Breaks the one-token-per-`(Game_Id, Mark)` binding Requirement 3.1 rests on |

## Reason

Only the requesting player's session is present in the request. Minting both tokens at
creation would produce a credential that exists in the database and in no browser.

Recording the preceding Game_Id and deriving the Mark swap from it (Req 7.3) means the second
player's token can be minted correctly whenever they arrive, with identity continuity carried
solely by the preceding Game's token (Req 7.6, 7.7).

The control is a POST rather than a link because the endpoint both creates and joins: it has
to be idempotent, and a GET that mints a credential is the wrong shape for that.

## In practice

`CreateRematch::mintFor()` uses `PlayerTokens::mint()` and `remember()` rather than `issue()`,
for the same reason `JoinGame` does — the row may be one this request has just lost the race to
insert, so the hash must be persisted before the browser is told anything. The unique index on
`rematch_of_game_id` is what settles two simultaneous rematch requests, and it is also
Requirement 7.8's at-most-one-Rematch-per-Game.

Nothing in the token names the Game or the Mark. The slot the hash is written to is the binding
(Req 3.1), which is what makes minting-on-arrival safe rather than merely convenient.
