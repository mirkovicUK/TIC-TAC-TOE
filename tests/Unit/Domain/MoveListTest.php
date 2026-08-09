<?php

declare(strict_types=1);

use App\Domain\TicTacToe\Move;
use App\Domain\TicTacToe\MoveList;

// Feature: remote-tic-tac-toe, Property 10: sequence indices are contiguous from zero
//
// Validates: Requirements 11.4, 14.1
//
/*
 * `MoveList::append()`, directly.
 *
 * It has one caller — `SubmitMove`, which re-analyses the observed list plus the
 * accepted Cell — and until this file existed it had no direct test. The rest of
 * this suite builds lists with `fromMoves()` and `fromCellIndices()`, so an
 * off-by-one in `append()` left every test here green and surfaced only as HTTP 500s
 * in the feature suite: the appended Move lands at a gap, the list stops being well
 * formed, and `SubmitMove` raises `CorruptMoveListException` rather than persisting
 * anything. Measured: 65 domain tests pass, 38 feature tests fail.
 *
 * These assertions put the failure in the layer that owns the arithmetic.
 *
 * No framework boot: plain Pest functions under tests/Unit (Req 14.1).
 */

it('numbers an appended move at the length before the append', function () {
    expect(MoveList::empty()->append(4)->moves[0]->sequenceIndex)->toBe(0, 'the first Move of an empty list is not at index 0');

    $list = MoveList::fromCellIndices(0, 3)->append(4);

    expect($list)->toHaveCount(3)
        ->and($list->moves[2]->sequenceIndex)->toBe(2, 'the appended Move is not at the length the list had before the append, which is the index `SubmitMove` persists')
        ->and($list->moves[2]->cellIndex)->toBe(4, 'the appended Move does not carry the Cell it was given');
});

it('stays contiguous from zero over nine appends, and leaves the source list untouched', function () {
    $list = MoveList::empty();
    $sources = [];

    foreach ([4, 0, 8, 2, 6, 1, 7, 3, 5] as $cellIndex) {
        $sources[] = $list;
        $list = $list->append($cellIndex);
    }

    $indices = array_map(
        static fn (Move $move): int => $move->sequenceIndex,
        $list->moves,
    );

    expect($indices)->toBe([0, 1, 2, 3, 4, 5, 6, 7, 8], 'nine appends did not produce indices 0..8, so a list built only through append() is not contiguous from zero')
        ->and($list->cellIndices())->toBe([4, 0, 8, 2, 6, 1, 7, 3, 5], 'the Cells are not in the order they were appended');

    // `append()` returns a new instance (the class is `final readonly`), so every
    // intermediate list must still hold the length it had when it was captured.
    foreach ($sources as $length => $source) {
        expect($source)->toHaveCount($length, "append() mutated the list it was called on at length {$length}");
    }
});
