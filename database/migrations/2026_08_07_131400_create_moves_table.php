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
     * As with `games`, the table body is one raw `CREATE TABLE`: the fluent
     * Blueprint cannot express a CHECK constraint and SQLite has no
     * `ALTER TABLE ... ADD CONSTRAINT`, so the CHECKs must be present in the
     * original statement. The indexes follow through the schema builder.
     */
    public function up(): void
    {
        // There is no `mark` column and no `mark` CHECK, and neither is an oversight to
        // be corrected later. A Move is a Cell_Index and a Sequence_Index; the Mark is
        // the parity of the Sequence_Index — X on even, O on odd (Req 11.4, ADR-003) —
        // and the unique `(game_id, sequence_index)` index below already fixes the
        // parity of every row, so a stored mark could only agree with it or contradict
        // it.
        //
        // The foreign key is `ON DELETE CASCADE` where the `games` self-reference uses
        // RESTRICT: a Move has no life of its own once its Game is gone, so the sweep
        // deletes game rows only and never names this table (Req 13.3). There is no
        // `updated_at` and no update path in the application — the only write to a Move
        // is the INSERT that records it.
        DB::statement(<<<'SQL'
            CREATE TABLE moves (
                id             INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,  -- insertion order; no external identity
                game_id        TEXT    NOT NULL REFERENCES games (id) ON DELETE CASCADE,
                cell_index     INTEGER NOT NULL,
                sequence_index INTEGER NOT NULL,                            -- Mark is its parity; never stored
                created_at     TEXT    NOT NULL,

                CHECK (cell_index     BETWEEN 0 AND 8),
                CHECK (sequence_index BETWEEN 0 AND 8)
            )
            SQL);

        Schema::table('moves', function (Blueprint $table) {
            // Req 5.1 as a persisted invariant, which is what makes the
            // `conflict` outcome a database answer rather than a hopeful SELECT
            // beforehand (ADR-006). With the range CHECK above it also caps a
            // Game at nine Moves by pigeonhole (Req 5.6), and it is the index
            // `ORDER BY sequence_index` reads.
            $table->unique(['game_id', 'sequence_index'], 'moves_game_sequence_unique');

            // Req 5.2 as a persisted invariant. With its own range CHECK this
            // caps the row count at nine a second time, independently of the
            // sequence index. The redundancy is deliberate: either index alone
            // is sufficient for Req 5.6 and neither is relied upon by the other,
            // so neither may be dropped as a simplification.
            $table->unique(['game_id', 'cell_index'], 'moves_game_cell_unique');
        });

        // Nothing above requires the Sequence_Indexes of a Game to be contiguous from
        // zero: rows carrying 0, 1, 2, 4, 5 satisfy both CHECKs and both unique indexes
        // with a gap at 3, and 1, 2, 3 satisfy them without starting at zero. "Strictly
        // increasing from zero with no gaps" is an application guarantee, delivered by
        // SubmitMove computing `sequence_index = count($observed->moveList)` over a
        // Move_List the Rules_Engine has already declared well formed. Property 10
        // asserts that split.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('moves');
    }
};
