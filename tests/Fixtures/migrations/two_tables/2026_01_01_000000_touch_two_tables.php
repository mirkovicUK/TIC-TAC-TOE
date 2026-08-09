<?php

// FIXTURE for scripts/check-migrations.php. Never applied; not in database/migrations.
// Rejected shape: two tables in one up(). Both operations are additive; the objection
// is that a failure between them cannot be rolled back and cannot be re-run.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->text('note')->nullable();
        });

        Schema::table('moves', function (Blueprint $table) {
            $table->text('note')->nullable();
        });
    }

    public function down(): void
    {
        //
    }
};
