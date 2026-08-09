# ADR-007: A retention command rather than an enforced TTL

Bears on Requirements 13.1 to 13.5 and 12.12.

## Decision

Stale Games have to be cleaned up: game creation is unauthenticated, so stored data would
otherwise grow without bound.

`php artisan games:sweep` does it. A Game waiting for an opponent becomes eligible for
deletion after 24 hours; any Game becomes eligible 7 days after its most recent move or state
change.

The command runs on a schedule — the host crontab, daily at 03:17 — rather than being run by
hand. No scheduler process runs inside the application: the command is the product, the
cadence is deployment configuration. The crontab entry is in the README (Req 12.12).

Nothing deletes on a timer inside the application, so the elapsed times are lower bounds on
retention rather than times of deletion (Req 13.5).

## Alternatives considered

| Alternative | Why not |
| --- | --- |
| A queue worker or scheduler process in the container | A third runtime component for one job a day |
| Deletion on read | Puts a write on the request that polls every 2 seconds, and makes the thresholds exact rather than lower bounds |
| A database-level TTL | SQLite has none, and it would move the policy out of the code and out of the tests |
