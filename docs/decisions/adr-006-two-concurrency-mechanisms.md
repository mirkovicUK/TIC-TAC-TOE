# ADR-006: Two concurrency mechanisms, each matched to its race

Bears on Requirements 2.7, 5.1 and 14.9.

## Decision

Two different mechanisms for two different races.

- **Move conflicts** are resolved by the unique `(game_id, sequence_index)` index.
- **Concurrent joins** are resolved by a conditional `UPDATE ... WHERE state =
  'waiting_for_opponent'` and its affected-row count.

## Alternatives considered

| Alternative | Why not |
| --- | --- |
| A single optimistic-locking column checked on both paths | Would weaken one of the two guarantees to make one mechanism cover both |
| Table locks | Serialises everything to settle a contest between two requests |
| A serialised queue per game | A queue worker, for nine writes |

## Reason

The two races have different shapes.

A move race is a contest to occupy a Sequence_Index. A unique index settles it as a persisted
invariant that also holds against direct writes to the database (Req 5.1) — not only against
writes that come through the application.

A join race is a contest to claim a slot on one row. A guarded UPDATE settles it in a single
statement with no read-then-write window (Req 2.7).

Using one mechanism for both would mean giving up one of those two properties.

## In practice

`JoinGame`'s statement carries three WHERE conditions — the id, `state =
'waiting_for_opponent'` and `o_token_hash IS NULL` — and the affected-row count is what
decides between claiming the slot and answering `game_full`.

`SubmitMove` carries a docblock forbidding the additions that would break the move half:
no `refresh()`, no second snapshot, no `lockForUpdate()`, no transaction re-reading around
the guards. None of them changes a single-request outcome, which is what makes them easy to
add; the second of two competing calls would then observe the first's write and the race
would stop being decided by the index.
