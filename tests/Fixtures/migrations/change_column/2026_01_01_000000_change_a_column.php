<?php

// FIXTURE for scripts/check-migrations.php. Never applied; not in database/migrations.
// Rejected shape: an existing column altered in place inside up(). On SQLite `change()`
// rebuilds the table, which is the least additive operation available.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->text('join_code')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        //
    }
};
