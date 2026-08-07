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
     * As with `games`, the table body is one raw `CREATE TABLE`: Laravel's
     * fluent Blueprint cannot express a CHECK constraint and SQLite has no
     * `ALTER TABLE ... ADD CONSTRAINT`, so the CHECKs have to be present in the
     * original statement. The indexes follow through the schema builder, which
     * expresses them faithfully.
     */
    public function up(): void
    {
        // Note on a column that is deliberately ABSENT, and must stay absent:
        //
        // There is NO `mark` column, and NO `mark` CHECK. A Move is a Cell_Index
        // and a Sequence_Index; the Mark is the parity of the Sequence_Index —
        // X on even, O on odd (Req 11.4, ADR-003). The unique
        // `(game_id, sequence_index)` index below already fixes the parity of
        // every row, so a stored mark could only ever agree with it or
        // contradict it. Deriving costs one modulo and removes a whole class of
        // inconsistency. This is not an oversight to be corrected later.
        //
        // Note on the primary key, which is deliberately unlike `games.id`:
        //
        // `games.id` is a UUIDv7 supplied by the application and derived from no
        // database sequence (Req 1.2), because a Game has an external identity:
        // it is addressed by URL and must not be enumerable. A Move has neither
        // property — it is never addressed on its own, it is only ever read as
        // part of its Game's Move_List, and all it needs from its key is
        // insertion order. `INTEGER PRIMARY KEY AUTOINCREMENT` is therefore the
        // right key here, and the two tables answering the same question
        // differently is a considered choice rather than an inconsistency.
        //
        // Note on the foreign key, which is also deliberately unlike `games`:
        //
        // `ON DELETE CASCADE`, where the self-reference on `games` uses
        // RESTRICT. A Move has no life of its own once its Game is gone, so
        // cascading is what reduces the sweep command's work to a single delete
        // of the game rows (Req 13.3). RESTRICT on the `games` self-reference
        // buys explicit deletion ordering for a row that *does* have a life of
        // its own — a live rematch — and that argument does not apply here.
        //
        // Note on append-only:
        //
        // There is no `updated_at`, and no update path exists in the
        // application. Moves are immutable: the only write is the INSERT that
        // records one.
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
            // sequence index. **The redundancy is deliberate:** either index
            // alone is sufficient for Req 5.6 and neither is relied upon by the
            // other, so neither may be dropped as a simplification.
            $table->unique(['game_id', 'cell_index'], 'moves_game_cell_unique');
        });

        // Note on what is deliberately NOT enforced here:
        //
        // Nothing above requires the Sequence_Indexes of a Game to be
        // contiguous from zero. Rows carrying 0, 1, 2, 4, 5 satisfy both CHECKs
        // and both unique indexes with a gap at 3, and rows carrying 1, 2, 3
        // satisfy them without starting at zero. "Strictly increasing from zero
        // with no gaps" is an *application* guarantee, delivered by SubmitMove
        // computing `sequence_index = count($observed->moveList)` over a
        // Move_List the Rules_Engine has already declared well formed. That is
        // the persisted-versus-application split asserted by Property 10.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('moves');
    }
};
