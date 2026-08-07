<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * This table carries no CHECK constraints, so unlike `games` and `moves` the
     * raw `CREATE TABLE` is not forced — the fluent Blueprint could express it.
     * It is still written raw, for two reasons. The design states TEXT for both
     * columns, and the Blueprint would render them `varchar` and `datetime`,
     * the latter carrying NUMERIC affinity in SQLite rather than TEXT. And the
     * three tables of this schema then read as one statement each, in the same
     * shape, with the deliberate differences between them visible side by side
     * rather than obscured by a difference in construction style. The index
     * follows through the schema builder, which expresses it faithfully.
     */
    public function up(): void
    {
        // Note on the constraint that is deliberately ABSENT, and that CANNOT
        // exist — the single most important fact about this table:
        //
        // There is NO FOREIGN KEY from `game_id` to `games (id)`, and there can
        // never be one. The whole point of a row here is that the Game it names
        // has been DELETED (Req 13.3). The sweep inserts the tombstone in the
        // same transaction as the delete of the game row it refers to, so a
        // foreign key would make the insert impossible: the referenced row is on
        // its way out. A reader meeting a `game_id` with no `REFERENCES` clause
        // will be tempted to "correct" it. That correction would break the
        // retention command outright. Do not add it.
        //
        // Note on the columns that are deliberately ABSENT:
        //
        // Two columns, and only two — a Game_Id and a deletion time, exactly as
        // the glossary defines an Expiry_Record. NO Move_List, NO Join_Code, NO
        // Player_Token hash, NO Game_State, NO winning mark. Anything more would
        // mean expiry had *archived* the Game rather than deleted it, and
        // Requirement 13.3's deletion would hold in name only.
        //
        // Note on the absent timestamp pair:
        //
        // There is no `created_at`/`updated_at`. `deleted_at` IS the timestamp,
        // and a tombstone is written once and never updated — contrast `games`,
        // which carries `created_at`, `updated_at` and `last_activity_at`
        // because a Game is mutated throughout its life.
        //
        // Note on the primary key:
        //
        // `game_id` is the PRIMARY KEY rather than a plain column, so one
        // tombstone per Game_Id is enforced by the schema and not by the care of
        // the sweep. There is no surrogate `id`: this table, unlike `moves`, has
        // no need of insertion order, and its natural key is already unique.
        DB::statement(<<<'SQL'
            CREATE TABLE expiry_records (
                game_id    TEXT NOT NULL PRIMARY KEY,  -- Game_Id of a DELETED game; NO foreign key is possible
                deleted_at TEXT NOT NULL                -- the deletion time, and the only timestamp this row has
            )
            SQL);

        Schema::table('expiry_records', function (Blueprint $table) {
            // Req 13.4: a record is kept for at least 30 days and deleted
            // thereafter, so the sweep's closing statement is
            // `DELETE FROM expiry_records WHERE deleted_at <= :thirty_days_ago`,
            // and this index is what it reads. Note that the sweep performs two
            // deletions of opposite polarity in the one transaction: games too
            // old to keep (Req 13.1, 13.2, read through `games_expiry_index`),
            // and tombstones too old to still be useful (read through this one).
            $table->index('deleted_at', 'expiry_records_deleted_at_index');
        });

        // Note on the security property, which two columns do not show:
        //
        // A tombstone is safe to keep only because of who is allowed to see it.
        // Req 13.6 offers the game-expired outcome ONLY to a Player_Session
        // presenting a valid Player_Token for that Game_Id; every other caller
        // gets `not_recognised` (Req 13.8). So this table cannot be used to
        // enumerate which Game_Ids ever existed. **That is a constraint on the
        // SERVICE — `GameResolver`, task 5.3 — and not on this table.** Nothing
        // in the DDL above enforces it and nothing here could: a bare SELECT
        // reveals every row. Do not assume the table is doing that work.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expiry_records');
    }
};
