<?php

declare(strict_types=1);

use App\Games\CorruptMoveListException;
use App\Games\GameSnapshot;
use App\Games\GameState;
use App\Models\Game;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Feature: remote-tic-tac-toe, Property 10: The persisted Move_List is always well formed
//
// Validates: Requirements 5.1, 5.2, 5.6
//
/*
 * The persisted half of Property 10, and the one place its split from the
 * application-delivered half is visible.
 *
 * Every assertion is about the database. Move rows are written with
 * `DB::table('moves')->insert(...)`, past `SubmitMove`, `GameSnapshot`, the
 * Rules_Engine and Eloquent, because the three persisted claims must hold against a
 * write that bypasses the application: asserted through the application they would
 * still pass with every constraint dropped from the migration.
 *
 * The split the DDL does not reveal. Three of Property 10's four claims are persisted:
 *
 *   - no Sequence_Index appears twice in a Game (`moves_game_sequence_unique`);
 *   - no Cell_Index appears twice in a Game (`moves_game_cell_unique`);
 *   - no Game holds more than nine Moves (either range CHECK with either unique
 *     index, by pigeonhole — Req 5.6).
 *
 * The fourth — Sequence_Indexes running 0..n-1 contiguously from zero — is not
 * persisted and cannot be inferred from the DDL. `SubmitMove` delivers it by computing
 * `sequence_index = count($observed->moveList)` over a list the Rules_Engine has
 * already declared well formed, so it holds only for Moves accepted through the
 * application.
 *
 * `RefreshDatabase` over `DatabaseMigrations`: `phpunit.xml` sets
 * `DB_DATABASE=:memory:`, so a Feature test starts with no schema and one of the two
 * is required. Its per-test transaction is safe for a file that provokes constraint
 * violations, because SQLite rolls back the offending statement and leaves the
 * enclosing transaction usable.
 */

uses(RefreshDatabase::class);

/**
 * A `games` row, saved through Eloquent because `moves.game_id` needs a parent to
 * reference. Attributes are assigned explicitly because mass assignment is closed on
 * this model.
 */
function schemaGame(?string $joinCode, ?string $rematchOf = null): Game
{
    $game = new Game;
    $game->id = Str::uuid7()->toString();
    $game->join_code = $joinCode;
    $game->state = GameState::Active;
    $game->version_counter = 0;
    $game->rematch_of_game_id = $rematchOf;
    $game->last_activity_at = now();
    $game->save();

    return $game;
}

/**
 * Attempts one row and reports whether the database took it.
 *
 * A raw query-builder INSERT, so what passes or fails is the DDL of
 * `2026_08_07_131400_create_moves_table` and nothing else. The expected
 * `QueryException` becomes `false` so each caller can name what it was attempting.
 */
function schemaAcceptsMove(string $gameId, int $cellIndex, int $sequenceIndex): bool
{
    try {
        DB::table('moves')->insert([
            'game_id' => $gameId,
            'cell_index' => $cellIndex,
            'sequence_index' => $sequenceIndex,
            'created_at' => now()->toDateTimeString(),
        ]);

        return true;
    } catch (QueryException) {
        return false;
    }
}

/*
 * Req 5.1. The second game rules out an index on `sequence_index` alone, which would
 * also reject the duplicate: only the cross-game insert distinguishes the per-game
 * index from a global one.
 */
it('rejects a repeated sequence index within a game, and allows the same index in another game', function () {
    $game = schemaGame('SEQ-A');
    $other = schemaGame('SEQ-B');

    expect(schemaAcceptsMove($game->id, 0, 0))->toBeTrue('the schema rejected an ordinary first move')
        ->and(schemaAcceptsMove($game->id, 4, 0))->toBeFalse('a second move at sequence index 0 was accepted in the same game (Req 5.1)')
        ->and(schemaAcceptsMove($other->id, 4, 0))->toBeTrue('sequence index 0 was rejected in a different game; the unique index is per game, not global');
});

/*
 * Req 5.2, and the same cross-game confirmation for the same reason.
 */
it('rejects a repeated cell index within a game, and allows the same cell in another game', function () {
    $game = schemaGame('CELL-A');
    $other = schemaGame('CELL-B');

    expect(schemaAcceptsMove($game->id, 0, 0))->toBeTrue('the schema rejected an ordinary first move')
        ->and(schemaAcceptsMove($game->id, 0, 1))->toBeFalse('a second move on cell 0 was accepted in the same game (Req 5.2)')
        ->and(schemaAcceptsMove($other->id, 0, 0))->toBeTrue('cell 0 was rejected in a different game; the unique index is per game, not global');
});

/*
 * Req 5.6, the nine-Move cap, delivered by pigeonhole with no application code
 * involved. `CHECK (sequence_index BETWEEN 0 AND 8)` plus uniqueness per game leaves a
 * tenth row nowhere to go, and `cell_index` 0..8 unique per game caps it a second
 * time. This is the one criterion of Requirement 5 that needs no service test.
 *
 * Which constraint rejected is not asserted. On a nine-row game every in-range tenth
 * row violates both unique indexes at once and SQLite reports whichever it tests
 * first. Req 5.6 promises the row does not exist: rejected, and still nine rows after.
 */
it('cannot hold a tenth move however the tenth is attempted', function () {
    $game = schemaGame('NINE');

    foreach (range(0, 8) as $index) {
        expect(schemaAcceptsMove($game->id, $index, $index))->toBeTrue("the schema rejected legitimate move {$index} of nine");
    }

    $attempts = [
        'an in-range row, which on a full game necessarily repeats both a cell and a sequence index' => [3, 5],
        'both indexes above the range' => [9, 9],
        'both indexes below the range' => [-1, -1],
        'a sequence index above the range' => [4, 9],
        'a cell index above the range' => [9, 4],
    ];

    foreach ($attempts as $attempt => [$cellIndex, $sequenceIndex]) {
        expect(schemaAcceptsMove($game->id, $cellIndex, $sequenceIndex))->toBeFalse("a tenth move was accepted: {$attempt} (Req 5.6)");
    }

    expect(DB::table('moves')->where('game_id', $game->id)->count())->toBe(9, 'a game holds more than nine moves (Req 5.6)');
});

/*
 * The fourth claim of Property 10. Rows 0, 1, 2, 4, 5 satisfy both CHECKs and both
 * unique indexes with a gap at 3, and a list starting at 1 satisfies them without
 * starting at zero, so the schema takes both.
 *
 * The consequence is asserted beside it: the Rules_Engine rejects both lists the schema
 * just accepted, surfacing as `GameSnapshot::of()` throwing `CorruptMoveListException`.
 * These two assertions together are the only place the split is shown.
 */
it('persists a gapped move list and one that does not start at zero, which the rules engine then rejects', function () {
    $gapped = schemaGame('GAP');
    $late = schemaGame('LATE');

    foreach ([0, 1, 2, 4, 5] as $index) {
        expect(schemaAcceptsMove($gapped->id, $index, $index))->toBeTrue("the schema rejected sequence index {$index} of the gapped list 0,1,2,4,5, so contiguity is persisted after all");
    }

    foreach ([1, 2, 3] as $index) {
        expect(schemaAcceptsMove($late->id, $index, $index))->toBeTrue("the schema rejected sequence index {$index} of a list starting at one, so a zero start is persisted after all");
    }

    expect(DB::table('moves')->where('game_id', $gapped->id)->count())->toBe(5)
        ->and(fn () => GameSnapshot::of($gapped))->toThrow(CorruptMoveListException::class)
        ->and(fn () => GameSnapshot::of($late))->toThrow(CorruptMoveListException::class);
});

/*
 * `games_join_code_unique` is usable only because SQLite treats NULLs in a unique index
 * as distinct, which is what lets every rematch carry `join_code = NULL` (ADR-010; the
 * CHECK `join_code IS NOT NULL OR rematch_of_game_id IS NOT NULL` keeps such a row
 * reachable). Under an engine that treated NULLs as equal, the second rematch created
 * in production would collide, so the dependency is pinned here.
 *
 * Two parents rather than two rematches of one, because `games_rematch_of_unique`
 * allows a Game at most one rematch (Req 7.8).
 */
it('treats null join codes as distinct, so every rematch may carry one', function () {
    schemaGame(null, schemaGame('PARENT-1')->id);
    schemaGame(null, schemaGame('PARENT-2')->id);

    expect(Game::query()->whereNull('join_code')->count())->toBe(2, 'a second rematch collided on games_join_code_unique, so NULLs are no longer distinct');
});
