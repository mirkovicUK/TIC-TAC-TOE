# ADR-004: SQLite on a named volume

Bears on Requirements 10.1 and 10.2, and on the Deployment section of the design.

## Decision

SQLite, with WAL journalling, a 5-second busy timeout and foreign keys on, in a Docker
named volume (`sqlite-data`). The settings live in `config/database.php`.

## Alternatives considered

| Alternative | Why not |
| --- | --- |
| Postgres or MySQL in a second container | Triples the operational surface of the hosted instance for a workload of nine writes per game |
| A managed database | A monthly bill and a second network dependency, for two players |

## Reason

The workload is two players and nine writes per game.

WAL keeps polling readers off the writer's back: a reader takes its read mark without
waiting on a writer, which is what lets the health probe answer inside Requirement 10.1's
one-second bound while a write is in flight.

Foreign keys are enabled explicitly because SQLite disables them per connection by default
— a footgun worth naming in the record rather than discovering later.

## In practice: the settings did not prevent a production failure

WAL and the busy timeout were both in force, and neither helped.

`.env.example` originally shipped `CACHE_STORE=database`, which is also Laravel's own
default, so the rate limiters' counters lived in the `cache` table of the same SQLite file
as the games and the sessions. `Illuminate\Cache\DatabaseStore::incrementOrDecrement()`
performs the increment as a SELECT followed by an UPDATE inside one transaction, with
`lockForUpdate()` a no-op because SQLite has no `FOR UPDATE`. Two players polling every 2
seconds (ADR-001) issue overlapping increments continuously: one reads the counter, the
other commits first, and the first's UPDATE now holds a stale snapshot.

SQLite answers SQLITE_BUSY for that case **immediately**. `busy_timeout` does not apply,
because waiting cannot help a transaction that has to roll back and be retried, and Laravel
does not retry. The exception surfaced as a 500 on a polling request, to the player who was
not even acting. Measured at 13 failures in 30 concurrent requests.

**The fix was the cache store, not the database.** `CACHE_STORE=file`, which takes the
counters out of SQLite entirely: zero failures at 30 concurrent requests and at 60, with the
limiter still refusing past its threshold. `array` was not an option — it is per-request, so
nothing accumulates and rate limiting would stop existing while appearing configured.

`.env.example` and `compose.yaml` carry the full reasoning, including the cost of `file`:
its increment is an unlocked read-then-write, so heavy concurrency can lose an increment and
leave the limiter marginally permissive. That is the better failure to prefer over an error
page for the player whose turn it was.

The decision above still stands. What changed is the claim that WAL plus a busy timeout
covers SQLITE_BUSY: it covers one kind of it and not this one.
