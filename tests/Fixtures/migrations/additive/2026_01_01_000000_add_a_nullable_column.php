<?php

// FIXTURE for scripts/check-migrations.php. Never applied; not in database/migrations.
// ACCEPTED shape, and the one the expand-and-contract sequence starts with: one table,
// one nullable column added. This fixture is the important one -- a check that cannot
// pass is as useless as one that cannot fail.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->text('spectator_code')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('spectator_code');
        });
    }
};
