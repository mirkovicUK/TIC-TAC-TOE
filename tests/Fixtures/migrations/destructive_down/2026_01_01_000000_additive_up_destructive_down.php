<?php

// FIXTURE for scripts/check-migrations.php. Never applied; not in database/migrations.
// ACCEPTED shape, and it is here to stop the rule being too eager. Every real migration
// in this project ends with `Schema::dropIfExists()` in down(), which is exactly where
// that belongs. A checker that scanned the whole file rather than the up() body alone
// would reject all six and then be switched off.
//
// The prose below is also deliberate: it says dropColumn and dropIfExists inside a
// comment, so a grep-based checker would fail this file. Comments are not code.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Written raw for the same reason the real tables are: a CHECK constraint cannot
        // be expressed through the Blueprint, and SQLite has no ADD CONSTRAINT. Note for
        // the reader: there is no dropColumn here and no DROP TABLE, and there must not
        // be -- the reverse lives in down().
        DB::statement(<<<'SQL'
            CREATE TABLE spectators (
                id      TEXT NOT NULL PRIMARY KEY,
                game_id TEXT NOT NULL REFERENCES games (id) ON DELETE CASCADE,

                CHECK (length(id) = 36)
            )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('spectators');
    }
};
