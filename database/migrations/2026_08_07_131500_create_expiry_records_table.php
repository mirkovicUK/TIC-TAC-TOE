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
     * This table carries no CHECK constraints, so the raw `CREATE TABLE` is not
     * forced here as it is for `games` and `moves`. It is still written raw
     * because the design states TEXT for both columns and the Blueprint would
     * render them `varchar` and `datetime`, the latter carrying NUMERIC affinity
     * in SQLite. The index follows through the schema builder.
     */
    public function up(): void
    {
        // There is NO foreign key from `game_id` to `games (id)`, and there can never
        // be one — the single most important fact about this table. The whole point of
        // a row here is that the Game it names has been DELETED (Req 13.3), and the
        // sweep inserts the tombstone in the same transaction as the delete of that
        // game row, so a foreign key would make the insert impossible. Adding the
        // `REFERENCES` clause that looks missing would break the retention command.
        //
        // Two columns, and only two — a Game_Id and a deletion time, exactly as the
        // glossary defines an Expiry_Record. No Move_List, no Join_Code, no
        // Player_Token hash, no Game_State, no winning mark: anything more would mean
        // expiry had archived the Game rather than deleted it, and Requirement 13.3's
        // deletion would hold in name only.
        //
        // There is no `created_at`/`updated_at` pair. `deleted_at` is the timestamp,
        // and a tombstone is written once and never updated.
        //
        // `game_id` is the PRIMARY KEY, so one tombstone per Game_Id is enforced by the
        // schema rather than by the care of the sweep. There is no surrogate `id`: this
        // table has no need of insertion order and its natural key is already unique.
        DB::statement(<<<'SQL'
            CREATE TABLE expiry_records (
                game_id    TEXT NOT NULL PRIMARY KEY,  -- Game_Id of a DELETED game; NO foreign key is possible
                deleted_at TEXT NOT NULL                -- the deletion time, and the only timestamp this row has
            )
            SQL);

        Schema::table('expiry_records', function (Blueprint $table) {
            // Req 13.4: a record is kept for at least 30 days and deleted
            // thereafter, so the sweep's closing statement is
            // `DELETE FROM expiry_records WHERE deleted_at < :thirty_days_ago`,
            // and this index is what it reads. The comparison is STRICT: "at least
            // 30 days" means a record exactly 30 days old is still within its
            // retention and survives. That is the opposite polarity from the two
            // game thresholds, which are inclusive because Requirements 13.1 and
            // 13.2 fire WHEN the elapsed time is reached. The sweep performs two
            // deletions in the one transaction: games too old to keep (Req 13.1,
            // 13.2), and tombstones too old to still be useful (read through this
            // one). The games half does NOT read `games_expiry_index` — see
            // `SweepExpiredGames::eligible()`.
            $table->index('deleted_at', 'expiry_records_deleted_at_index');
        });

        // A tombstone is safe to keep only because of who may see it: Req 13.6 offers
        // the game-expired outcome ONLY to a Player_Session presenting a valid
        // Player_Token for that Game_Id, and every other caller gets `not_recognised`
        // (Req 13.8), so the table cannot be used to enumerate which Game_Ids existed.
        // That is a constraint on `GameResolver` and not on this table — nothing in the
        // DDL above enforces it and nothing here could, since a bare SELECT reveals
        // every row.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expiry_records');
    }
};
