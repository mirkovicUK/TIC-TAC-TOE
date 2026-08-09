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

Foreign keys are enabled explicitly because SQLite disables them per connection by default.
