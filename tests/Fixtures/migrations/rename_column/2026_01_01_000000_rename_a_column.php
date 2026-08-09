<?php

// FIXTURE for scripts/check-migrations.php. Never applied; not in database/migrations.
// Rejected shape: a column renamed inside up().

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->renameColumn('join_code', 'invite_code');
        });
    }

    public function down(): void
    {
        //
    }
};
