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
     * The table is one raw `CREATE TABLE` because the fluent Blueprint cannot
     * express a CHECK constraint and SQLite has no
     * `ALTER TABLE ... ADD CONSTRAINT`, so the CHECKs must be present in the
     * original statement. The indexes follow through the schema builder.
     */
    public function up(): void
    {
        // No CHECK requires `x_token_hash IS NOT NULL`, or requires either token slot
        // to be populated in any state, and none may be added. Tokens
        // are minted per request, so a rematch is inserted with BOTH slots NULL, and
        // the mark swap of Requirement 7.3 lets the first requester populate
        // `o_token_hash` while `x_token_hash` is still NULL. Such a constraint would
        // reject the `CreateRematch` insert outright. Reachability of every Game is
        // carried instead by `join_code IS NOT NULL OR rematch_of_game_id IS NOT NULL`.
        //
        // `state <> 'waiting_for_opponent' OR o_token_hash IS NULL` is one-directional
        // on purpose: it forbids an occupied O slot while a Game waits for an opponent,
        // and must never be rewritten to require an occupied O slot in any other state.
        // A rematch is inserted directly in `active` with `o_token_hash` NULL, which
        // makes the antecedent false.
        //
        // The self-reference is `ON DELETE RESTRICT`, not CASCADE and not SET NULL, so
        // that deletion order is explicit in the sweep command: a live rematch can
        // never be destroyed as a side effect of expiring its parent, and a missed step
        // surfaces as a constraint failure instead of silent data loss.
        //
        // It interacts with the reachability CHECK above, and the sweep is built around
        // that interaction. A rematch has `join_code = NULL`, so its
        // `rematch_of_game_id` is the only thing satisfying that CHECK: the column
        // cannot be set to NULL to release the parent for deletion. The sweep therefore
        // DEFERS a parent whose rematch survives rather than clearing the
        // back-reference, and orders its deletes children before parents — this
        // reference is not `DEFERRABLE`, so SQLite enforces it per row. Requirement
        // 13.5's thresholds are lower bounds on retention, which is what permits the
        // deferral.
        DB::statement(<<<'SQL'
            CREATE TABLE games (
                id                 TEXT    NOT NULL PRIMARY KEY,   -- UUIDv7; derives from no database sequence
                join_code          TEXT        NULL,               -- NULL for a rematch
                state              TEXT    NOT NULL,
                winning_mark       TEXT        NULL,
                version_counter    INTEGER NOT NULL DEFAULT 0,
                x_token_hash       TEXT        NULL,               -- sha256 of the Player_Token
                o_token_hash       TEXT        NULL,
                rematch_of_game_id TEXT        NULL REFERENCES games (id) ON DELETE RESTRICT,
                created_at         TEXT    NOT NULL,
                updated_at         TEXT    NOT NULL,
                last_activity_at   TEXT    NOT NULL,

                CHECK (state IN ('waiting_for_opponent', 'active', 'won', 'drawn')),
                CHECK (winning_mark IS NULL OR winning_mark IN ('x', 'o')),
                CHECK ((state = 'won' AND winning_mark IS NOT NULL)
                    OR (state <> 'won' AND winning_mark IS NULL)),
                CHECK (version_counter >= 0),
                CHECK (join_code IS NOT NULL OR rematch_of_game_id IS NOT NULL),
                CHECK (rematch_of_game_id IS NULL OR rematch_of_game_id <> id),
                CHECK (state <> 'waiting_for_opponent' OR o_token_hash IS NULL)
            )
            SQL);

        Schema::table('games', function (Blueprint $table) {
            // SQLite treats NULLs as distinct in a unique index, so every
            // rematch may carry `join_code = NULL`.
            $table->unique('join_code', 'games_join_code_unique');

            // Req 7.8: at most one rematch per Game. Also the concurrency
            // control for two simultaneous rematch requests.
            $table->unique('rematch_of_game_id', 'games_rematch_of_unique');

            // Req 13.1, 13.2: the sweep's eligibility query.
            $table->index(['state', 'last_activity_at'], 'games_expiry_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
