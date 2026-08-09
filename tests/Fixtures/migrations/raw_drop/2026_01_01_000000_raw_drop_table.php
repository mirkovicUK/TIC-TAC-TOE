<?php

// FIXTURE for scripts/check-migrations.php. Never applied; not in database/migrations.
// Rejected shape: destruction expressed as raw SQL rather than through the Blueprint.
// This project writes its real tables with DB::statement, so the rule has to read there.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            DROP TABLE expiry_records
            SQL);
    }

    public function down(): void
    {
        //
    }
};
