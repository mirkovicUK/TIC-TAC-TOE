<?php

declare(strict_types=1);

namespace App\Games;

use App\Models\ExpiryRecord;
use App\Models\Game;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Deletes every Game that is Eligible_For_Expiry, leaves one Expiry_Record behind
 * for each, and purges Expiry_Records past their retention (Req 13.1–13.4).
 *
 * **A Game whose Rematch is not itself in the delete set is deferred, and its
 * `rematch_of_game_id` is never cleared.** A Rematch carries `join_code = NULL`,
 * so `CHECK (join_code IS NOT NULL OR rematch_of_game_id IS NOT NULL)` in
 * `database/migrations/2026_08_07_131347_create_games_table.php` is satisfied by
 * that column alone: setting it to NULL to release the parent fails the CHECK, and
 * leaving it fails the `ON DELETE RESTRICT` on the same column. A deferred parent
 * is collected on the first run after its Rematch is eligible too, which Req 13.5
 * licenses — the thresholds are lower bounds on retention rather than exact times
 * of deletion. `docs/ai-direction.md` records the decision.
 *
 * Nothing outside this class consults eligibility, and nothing may be added that
 * does: an eligible Game the command has not reached yet is an ordinary playable
 * Game (Req 13.5), so there is no global scope and no query scope here for a read
 * path to acquire.
 *
 * No `GameEventLogger` record. Requirement 10.3 enumerates six Game lifecycle
 * events and expiry is not among them; the command reports its own counts on its
 * own output instead.
 *
 * Nothing here writes `last_activity_at`, and `CreateRematch` deliberately does not
 * either — a Rematch bumping the preceding Game's column would keep a finished Game
 * alive past the 7-day threshold (Req 13.2).
 *
 * Absent by design: the scheduling. `games:sweep` runs from the host crontab
 * (ADR-007, Req 12.12), so there is no scheduler process and no `Schedule` entry.
 */
final class SweepExpiredGames
{
    /** Req 13.1: a never-joined Game is eligible 24 hours after it was created. */
    private const int UNJOINED_HOURS = 24;

    /** Req 13.2: a Game is eligible 7 days after its last accepted Move or Game_State change. */
    private const int INACTIVE_DAYS = 7;

    /** Req 13.4: an Expiry_Record is retained for at least 30 days. */
    private const int RECORD_RETENTION_DAYS = 30;

    /**
     * Tombstone rows per INSERT. Each row binds two parameters, so this is 200 of
     * SQLite's `SQLITE_LIMIT_VARIABLE_NUMBER`, which is 999 on builds predating
     * 3.32 and 32766 after it — the low figure is the one that has to fit.
     */
    private const int INSERT_CHUNK = 100;

    /**
     * Runs one sweep and reports what it did.
     *
     * Everything is inside one transaction, reads included, so the delete set is
     * computed against the same snapshot it is applied to. A Rematch inserted by a
     * concurrent request after the read cannot make a parent's deletion silently
     * wrong: the `RESTRICT` refuses it and the whole run rolls back.
     *
     * Nothing is caught. A `RESTRICT` violation means the deferral or the ordering
     * below is wrong, and the schema comment cited above chose that constraint
     * precisely so it would surface loudly rather than as silent data loss — so the
     * exception must be left to reach the command, roll the transaction back and
     * exit non-zero.
     *
     * `now()` once for the whole run: three cutoffs derived from one clock reading
     * cannot disagree with each other, and the framework's clock is travellable, so
     * a test can put a population either side of a threshold.
     */
    public function handle(): SweepReport
    {
        $now = now();

        return DB::transaction(function () use ($now): SweepReport {
            // Both directions of the Rematch pointer for every Game that has one,
            // in one query with no bound parameters — the `whereIn` alternative
            // would have to be chunked against the variable limit. The flip is
            // exact because `games_rematch_of_unique` makes `rematch_of_game_id`
            // unique, so no two Games are the Rematch of the same Game.
            $rematchOf = $this->rematchPointers();
            $precedingOf = array_flip($rematchOf);

            $eligible = $this->eligible($now);

            $delete = $this->withoutSurvivingRematches(
                array_fill_keys($eligible, true),
                $rematchOf,
                $precedingOf,
            );

            $ordered = $this->childrenBeforeParents($delete, $rematchOf, $precedingOf);

            $this->recordExpiry($ordered, $now);
            $this->deleteGames($ordered);

            return new SweepReport(
                gamesDeleted: count($ordered),
                gamesDeferred: count($eligible) - count($delete),
                recordsPurged: $this->purgeRecords($now),
            );
        });
    }

    /**
     * The Game_Id of every Game that is Eligible_For_Expiry, in one query
     * (Req 13.1, 13.2).
     *
     * Both comparisons are inclusive, because Requirements 13.1 and 13.2 make a
     * Game eligible *when* the elapsed time is reached. That is the opposite
     * polarity from `purgeRecords()`, deliberately.
     *
     * `games_expiry_index` on `(state, last_activity_at)` covers less of this than
     * its name suggests, and the shape here is the design's rather than an
     * optimisation. The first branch filters `state`, the index's leading column,
     * but ranges on `created_at` and tests `o_token_hash`, neither of which the
     * index carries; the second ranges on `last_activity_at`, the index's *second*
     * column, with the first unconstrained. `EXPLAIN QUERY PLAN` against this
     * schema reports `SEARCH games USING INDEX games_expiry_index (state=?)` for the
     * first branch alone, `SCAN games` for the second alone, and `SCAN games` for
     * the two combined as written — the index is not used by the query in this
     * method. No `ANALYZE` runs anywhere in this project, so no `sqlite_stat1`
     * exists and the plan is not a function of how many rows are present.
     *
     * @return list<string>
     */
    private function eligible(Carbon $now): array
    {
        $unjoinedSince = $now->copy()->subHours(self::UNJOINED_HOURS);
        $inactiveSince = $now->copy()->subDays(self::INACTIVE_DAYS);

        $rows = Game::query()
            ->where(function (Builder $branch) use ($unjoinedSince): void {
                // Req 13.1. `o_token_hash IS NULL` *is* "no Joiner has been
                // assigned" (see `Game`), and the clock runs from `created_at`
                // rather than `last_activity_at`: this branch is about a Game
                // nothing has happened to.
                $branch->where('state', GameState::WaitingForOpponent->value)
                    ->whereNull('o_token_hash')
                    ->where('created_at', '<=', $unjoinedSince);
            })
            // Req 13.2, and unqualified by state on purpose: a Game abandoned
            // mid-play and a Game finished a week ago are both eligible.
            ->orWhere('last_activity_at', '<=', $inactiveSince)
            ->get(['id']);

        $ids = [];

        foreach ($rows as $row) {
            $ids[] = $row->id;
        }

        return $ids;
    }

    /**
     * The Game_Id of each Rematch, keyed by the Game_Id it is the Rematch of.
     *
     * @return array<string, string>
     */
    private function rematchPointers(): array
    {
        $pointers = [];

        foreach (Game::query()->whereNotNull('rematch_of_game_id')->get(['id', 'rematch_of_game_id']) as $row) {
            $pointers[(string) $row->rematch_of_game_id] = $row->id;
        }

        return $pointers;
    }

    /**
     * `$delete` less every Game whose Rematch is not itself being deleted.
     *
     * Removal propagates *up* the chain, which is what makes this correct for a
     * chain longer than two: deferring a Game leaves its own Rematch pointer
     * dangling from the Game above, so that Game is deferred too. Three Games
     * A ← B ← C with only A eligible therefore delete nothing — A is kept because B
     * survives — rather than deleting A and failing on the reference from B.
     *
     * A `foreach` re-scan to a fixed point would give the same answer; the queue is
     * here because it visits each Game once.
     *
     * @param  array<string, true>  $delete
     * @param  array<string, string>  $rematchOf  Rematch keyed by preceding Game_Id
     * @param  array<string, string>  $precedingOf  Preceding Game keyed by Rematch Game_Id
     * @return array<string, true>
     */
    private function withoutSurvivingRematches(array $delete, array $rematchOf, array $precedingOf): array
    {
        $queue = [];

        foreach (array_keys($delete) as $id) {
            $rematch = $rematchOf[$id] ?? null;

            if ($rematch !== null && ! isset($delete[$rematch])) {
                $queue[] = $id;
            }
        }

        while (($id = array_pop($queue)) !== null) {
            unset($delete[$id]);

            $preceding = $precedingOf[$id] ?? null;

            if ($preceding !== null && isset($delete[$preceding])) {
                $queue[] = $preceding;
            }
        }

        return $delete;
    }

    /**
     * `$delete` as a list ordered so that every Game appears before the Game it is
     * the Rematch of.
     *
     * The order is load-bearing: `rematch_of_game_id` is not declared `DEFERRABLE`,
     * so SQLite enforces the reference as each row is deleted rather than at commit.
     *
     * The peel starts from the Games in the set with no Rematch at all, which after
     * `withoutSurvivingRematches()` is the same thing as "no Rematch outside the
     * set", and walks up one preceding Game at a time. Each Game has at most one
     * Rematch (`games_rematch_of_unique`), so a Game becomes ready the moment its
     * one Rematch has been emitted.
     *
     * A Game left unemitted would mean a cycle in the pointers, which
     * `CreateRematch` cannot produce — it points a freshly generated Game_Id at an
     * existing row. The remainder is appended rather than dropped so that such a
     * state fails on the `RESTRICT` instead of being retained in silence.
     *
     * @param  array<string, true>  $delete
     * @param  array<string, string>  $rematchOf  Rematch keyed by preceding Game_Id
     * @param  array<string, string>  $precedingOf  Preceding Game keyed by Rematch Game_Id
     * @return list<string>
     */
    private function childrenBeforeParents(array $delete, array $rematchOf, array $precedingOf): array
    {
        $ready = [];

        foreach (array_keys($delete) as $id) {
            if (! isset($rematchOf[$id])) {
                $ready[] = $id;
            }
        }

        $ordered = [];

        while (($id = array_shift($ready)) !== null) {
            $ordered[] = $id;

            $preceding = $precedingOf[$id] ?? null;

            if ($preceding !== null && isset($delete[$preceding])) {
                $ready[] = $preceding;
            }
        }

        foreach (array_keys(array_diff_key($delete, array_flip($ordered))) as $unreachable) {
            $ordered[] = $unreachable;
        }

        return $ordered;
    }

    /**
     * Writes one Expiry_Record per Game about to be deleted (Req 13.3).
     *
     * Before the deletes, and in the same transaction as them, which is the only
     * order available: `expiry_records` carries no foreign key to `games` — see the
     * comment in `database/migrations/2026_08_07_131500_create_expiry_records_table.php`
     * — so nothing forces the sequence, and a tombstone written in a separate
     * transaction could survive a rolled-back deletion.
     *
     * `insert()` rather than a model per row: it is a query-builder write that never
     * consults `$fillable`, so mass assignment being closed on `ExpiryRecord` is not
     * bypassed, and both columns of the row are named here. The two columns are the
     * whole record — a Game_Id and a deletion time, and nothing else.
     *
     * @param  list<string>  $gameIds
     */
    private function recordExpiry(array $gameIds, Carbon $now): void
    {
        foreach (array_chunk($gameIds, self::INSERT_CHUNK) as $chunk) {
            $rows = [];

            foreach ($chunk as $id) {
                $rows[] = ['game_id' => $id, 'deleted_at' => $now];
            }

            ExpiryRecord::query()->insert($rows);
        }
    }

    /**
     * Deletes the Games, in the order given.
     *
     * One statement per row, and NOT a single `whereIn`: SQLite chooses the order in
     * which a multi-row DELETE visits its rows, so a statement covering both a
     * Rematch and the Game it descends from could reach the parent first and be
     * refused by the `RESTRICT`. The ordering computed above only means anything if
     * each row is its own statement.
     *
     * The Move_List of each Game goes with it by `ON DELETE CASCADE` on
     * `moves.game_id`, so `moves` is not named here — and must not be. An explicit
     * delete would be a second mechanism for one guarantee (Req 13.3).
     *
     * @param  list<string>  $ordered
     */
    private function deleteGames(array $ordered): void
    {
        foreach ($ordered as $id) {
            Game::query()->whereKey($id)->delete();
        }
    }

    /**
     * Deletes the Expiry_Records past their retention, and returns how many
     * (Req 13.4).
     *
     * The comparison is STRICT. Requirement 13.4 retains a record "for at least 30
     * days", so one exactly 30 days old is still within its retention and survives.
     * `<=` here would delete it, and would be the wrong reading of the criterion.
     *
     * Runs after the deletions, in the same transaction. The tombstones just written
     * carry `deleted_at` equal to this run's clock reading, so they cannot fall
     * inside the boundary whatever order the two statements take.
     */
    private function purgeRecords(Carbon $now): int
    {
        return ExpiryRecord::query()
            ->where('deleted_at', '<', $now->copy()->subDays(self::RECORD_RETENTION_DAYS))
            ->delete();
    }
}
