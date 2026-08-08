# ADR-007: A retention command rather than an enforced TTL

Bears on Requirements 13.1 to 13.5 and 12.12.

## Decision

`php artisan games:sweep` deletes eligible Games and writes Expiry_Records. The schedule
lives in the host crontab and is documented in the README (Req 12.12). No scheduler process
runs inside the application: the command is the product, the cadence is deployment
configuration.

## Alternatives considered

| Alternative | Why not |
| --- | --- |
| A queue worker or scheduler process in the container | A third runtime component for one job a day |
| Deletion on read | Makes the thresholds exact rather than lower bounds, and puts a write on the polling path |
| A database-level TTL | SQLite has none, and it would move the policy out of the code and out of the tests |

## Reason

The requirement is that the command exists, is tested, and can be scheduled (Req 13.3, 12.12).

Deletion on read is the alternative worth naming precisely, because it looks cheaper. It
would put a write on the request that polls every 2 seconds, and it would turn Requirement
13.5's lower bounds on retention into exact deletion times, which is a stronger promise than
anything asks for.

## In practice: the sweep as first specified could not be implemented

The design's retention section, and the task that implemented it, both began the transaction
by clearing `rematch_of_game_id` on any Rematch pointing at a Game in the delete set. Two
constraints on that column make it impossible, and both are correct.

- `rematch_of_game_id` is `ON DELETE RESTRICT`, so a parent cannot be deleted while a
  Rematch points at it.
- `CHECK (join_code IS NOT NULL OR rematch_of_game_id IS NOT NULL)` is the sole carrier of
  "every Game is reachable either by its Join_Code or as the Rematch of a known Game".

A Rematch has `join_code = NULL` — it is reached by navigation, not by a code — so
`rematch_of_game_id` is the only thing satisfying that CHECK. Clearing the column violates
the CHECK; leaving it violates the foreign key. Both failures are `SQLSTATE[23000]` inside
the sweep's transaction, so the whole run rolls back and **nothing is deleted at all**, not
merely the affected pair.

It is not a corner case. A Rematch's `last_activity_at` is set at creation and bumped by
every Move, so it is always at or after its parent's: the parent always becomes eligible
first, and any scheduled run landing in that window fails wholesale.

**The ruling was to defer rather than clear.** A Game whose Rematch is not itself in the
delete set is excluded from the run and collected on a later one, once the Rematch is
eligible too. Each run orders its deletes children before parents, since the reference is
not `DEFERRABLE`. Requirement 13.5 licenses the deferral directly — the thresholds are lower
bounds on retention rather than exact times of deletion — and it needs no migration.

Two alternatives were rejected with reasons: dropping the reachability CHECK means a full
SQLite table rebuild to weaken an invariant in order to make a cleanup path work, and giving
the orphaned Rematch a fresh Join_Code writes a join-shaped value onto an active Game for no
user-facing reason.

The cost is that Property 17 is no longer literally true as written. The surviving Games are
those that are not Eligible_For_Expiry *together with those whose Rematch survives*, and the
property records why. `SweepReport::$gamesDeferred` exists so that a deferral is visible: the
Game is eligible, is still there after the run, and nothing in its row records why.
