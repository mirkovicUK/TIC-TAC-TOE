<?php

declare(strict_types=1);

use App\Domain\TicTacToe\Analysis;
use App\Domain\TicTacToe\InvalidMoveList;
use App\Domain\TicTacToe\Mark;
use App\Domain\TicTacToe\MoveList;
use App\Domain\TicTacToe\Outcome;
use App\Domain\TicTacToe\RulesEngine;
use App\Domain\TicTacToe\WinningLine;

/*
 * Worked examples of the rules (Req 11.4, 14.1).
 *
 * Every expectation is a literal, so a reviewer checks it against their own
 * knowledge of tic-tac-toe. `Support\LineOracle` is deliberately unused here: it
 * belongs to `EnumerationTest`, where half a million positions need a judge.
 *
 * Cells are numbered
 *
 *     0 1 2
 *     3 4 5
 *     6 7 8
 *
 * and each set-up carries the finished grid beside it.
 *
 * No framework boot: plain Pest functions under tests/Unit, so the generated test
 * class extends PHPUnit\Framework\TestCase (Req 14.1), asserted mechanically by
 * `ArchitectureTest`.
 */

/**
 * Narrows `Analysis|InvalidMoveList`, and fails loudly if a sequence this file
 * presents as a legal game is rejected: a rejected list derives nothing, so every
 * later assertion would be testing something other than what it reads as.
 */
function analyseAsWellFormed(MoveList $moveList): Analysis
{
    $analysis = RulesEngine::analyse($moveList);

    expect($analysis)->not->toBe(InvalidMoveList::Error)
        ->and($analysis)->toBeInstanceOf(Analysis::class);

    if (! $analysis instanceof Analysis) {
        throw new LogicException('Unreachable: the expectation above has already failed.');
    }

    return $analysis;
}

/*
 * Req 11.4 is definitional here — the Mark is parity, never stored — so a property
 * test would compare parity against parity. The nine indices are written out with
 * their expected Marks by hand instead.
 */
it('reads the mark of a move as X on an even sequence index and O on an odd one', function (int $sequenceIndex, Mark $expected) {
    expect(Mark::forSequenceIndex($sequenceIndex))->toBe($expected);
})->with([
    'sequence index 0' => [0, Mark::X],
    'sequence index 1' => [1, Mark::O],
    'sequence index 2' => [2, Mark::X],
    'sequence index 3' => [3, Mark::O],
    'sequence index 4' => [4, Mark::X],
    'sequence index 5' => [5, Mark::O],
    'sequence index 6' => [6, Mark::X],
    'sequence index 7' => [7, Mark::O],
    'sequence index 8' => [8, Mark::X],
]);

it('names the opponent of each mark as the other mark', function () {
    expect(Mark::X->opponent())->toBe(Mark::O)
        ->and(Mark::O->opponent())->toBe(Mark::X);
});

/*
 *     . . .
 *     . . .
 *     . . .
 */
it('reports an empty move list as in progress with X to move on an empty board', function () {
    $analysis = analyseAsWellFormed(MoveList::empty());

    expect($analysis->outcome)->toBe(Outcome::InProgress)
        ->and($analysis->markToMove)->toBe(Mark::X)
        ->and($analysis->moveCount)->toBe(0)
        ->and($analysis->winningLines)->toBe([])
        ->and($analysis->winner())->toBeNull()
        ->and($analysis->isTerminal())->toBeFalse()
        ->and($analysis->board->cells())->toBe([
            null, null, null,
            null, null, null,
            null, null, null,
        ]);
});

/*
 * X to the centre.
 *
 *     . . .
 *     . X .
 *     . . .
 */
it('reports the first move as in progress with O to move and one cell occupied', function () {
    $analysis = analyseAsWellFormed(MoveList::fromCellIndices(4));

    expect($analysis->outcome)->toBe(Outcome::InProgress)
        ->and($analysis->markToMove)->toBe(Mark::O)
        ->and($analysis->moveCount)->toBe(1)
        ->and($analysis->winningLines)->toBe([])
        ->and($analysis->isTerminal())->toBeFalse()
        ->and($analysis->board->cells())->toBe([
            null, null, null,
            null, Mark::X, null,
            null, null, null,
        ])
        ->and($analysis->board->vacantCells())->toBe([0, 1, 2, 3, 5, 6, 7, 8]);
});

/*
 * The eight lines, one case each, named so a failure reads as "the anti-diagonal
 * case broke" rather than "case 8 broke".
 *
 * Every sequence is a legal game: X takes its three cells on moves 0, 2 and 4, and
 * no earlier move completes a line because two marks cannot.
 *
 * The grid beside each row is the finished board, rows separated by ` / `.
 */
it('reports a win by X, and exactly the line completed', function (WinningLine $line, int ...$cellsInPlayOrder) {
    $analysis = analyseAsWellFormed(MoveList::fromCellIndices(...$cellsInPlayOrder));

    // Passed as the failure message as well as being the dataset key, so a break is
    // identifiable from the failure body alone.
    $case = "the {$line->name} case";

    expect($analysis->outcome)->toBe(Outcome::WonByX, $case)
        ->and($analysis->winningLines)->toBe([$line], $case)
        ->and($analysis->winner())->toBe(Mark::X, $case)
        ->and($analysis->isTerminal())->toBeTrue($case)
        ->and($analysis->moveCount)->toBe(5, $case);
})->with([
    // X X X / O O . / . . .
    'the top row' => [WinningLine::TopRow, 0, 3, 1, 4, 2],
    // O O . / X X X / . . .
    'the middle row' => [WinningLine::MiddleRow, 3, 0, 4, 1, 5],
    // O O . / . . . / X X X
    'the bottom row' => [WinningLine::BottomRow, 6, 0, 7, 1, 8],
    // X O O / X . . / X . .
    'the left column' => [WinningLine::LeftColumn, 0, 1, 3, 2, 6],
    // O X O / . X . / . X .
    'the middle column' => [WinningLine::MiddleColumn, 1, 0, 4, 2, 7],
    // O O X / . . X / . . X
    'the right column' => [WinningLine::RightColumn, 2, 0, 5, 1, 8],
    // X O O / . X . / . . X
    'the main diagonal' => [WinningLine::MainDiagonal, 0, 1, 4, 2, 8],
    // O O X / . X . / X . .
    'the anti-diagonal' => [WinningLine::AntiDiagonal, 2, 0, 4, 1, 6],
]);

/*
 * O wins too, so `won_by_o` is exercised rather than assumed to follow from the X
 * cases. X3 O0 X4 O1 X6 O2:
 *
 *     O O O
 *     X X .
 *     X . .
 *
 * X holds 3, 4 and 6, which is no line, so nothing completes before O's third move.
 */
it('reports a win by O when O completes a line', function () {
    $analysis = analyseAsWellFormed(MoveList::fromCellIndices(3, 0, 4, 1, 6, 2));

    expect($analysis->outcome)->toBe(Outcome::WonByO)
        ->and($analysis->winningLines)->toBe([WinningLine::TopRow])
        ->and($analysis->winner())->toBe(Mark::O)
        ->and($analysis->isTerminal())->toBeTrue()
        ->and($analysis->moveCount)->toBe(6)
        ->and($analysis->board->cells())->toBe([
            Mark::O, Mark::O, Mark::O,
            Mark::X, Mark::X, null,
            Mark::X, null, null,
        ]);
});

/*
 * The nine-move draw: X0 O1 X2 O5 X3 O6 X4 O8 X7.
 *
 *     X O X
 *     X X O
 *     O X O
 *
 * X holds 0, 2, 3, 4, 7 and O holds 1, 5, 6, 8; neither set contains a line and no
 * prefix of either can. `markToMove` is O, the parity of nine.
 */
it('reports a full board with no completed line as drawn', function () {
    $analysis = analyseAsWellFormed(MoveList::fromCellIndices(0, 1, 2, 5, 3, 6, 4, 8, 7));

    expect($analysis->outcome)->toBe(Outcome::Drawn)
        ->and($analysis->winningLines)->toBe([])
        ->and($analysis->winner())->toBeNull()
        ->and($analysis->isTerminal())->toBeTrue()
        ->and($analysis->moveCount)->toBe(9)
        ->and($analysis->markToMove)->toBe(Mark::O)
        ->and($analysis->board->cells())->toBe([
            Mark::X, Mark::O, Mark::X,
            Mark::X, Mark::X, Mark::O,
            Mark::O, Mark::X, Mark::O,
        ])
        ->and($analysis->board->vacantCells())->toBe([]);
});

/*
 * The double winning line: X0 O1 X2 O3 X6 O5 X8 O7 X4, where X's ninth move at the
 * centre completes both diagonals at once.
 *
 *     X O X
 *     O X O
 *     X O X
 *
 * This position is why Req 6.3 and 6.5 are plural, why `winningLines` is a list, and
 * why the board highlights every completed line. `EnumerationTest` reaches it as one
 * node among 549,946, where no reviewer would see it; here it is a named case.
 *
 * The two lines are asserted order-insensitively, since the order
 * `completedLinesFor` returns them in is an implementation detail.
 */
it('reports both lines when a single move completes two of them', function () {
    $analysis = analyseAsWellFormed(MoveList::fromCellIndices(0, 1, 2, 3, 6, 5, 8, 7, 4));

    expect($analysis->outcome)->toBe(Outcome::WonByX)
        ->and($analysis->winner())->toBe(Mark::X)
        ->and($analysis->isTerminal())->toBeTrue()
        ->and($analysis->moveCount)->toBe(9)
        ->and($analysis->winningLines)->toHaveCount(2)
        ->and($analysis->winningLines)->toContain(WinningLine::MainDiagonal)
        ->and($analysis->winningLines)->toContain(WinningLine::AntiDiagonal)
        ->and($analysis->board->cells())->toBe([
            Mark::X, Mark::O, Mark::X,
            Mark::O, Mark::X, Mark::O,
            Mark::X, Mark::O, Mark::X,
        ]);
});

/*
 * Req 4.1 is unconditional: Mark_To_Move is the parity of the Move_List length in a
 * terminal state too, naming who would have moved next.
 *
 * The consequence for the client: the O player's `isYourTurn` is true here even
 * though the game is over, which is why `Board.tsx` disables on
 * `!isYourTurn || state !== 'active'` and needs both halves.
 */
it('still reports a mark to move after the game has been won', function () {
    $analysis = analyseAsWellFormed(MoveList::fromCellIndices(0, 3, 1, 4, 2));

    expect($analysis->outcome)->toBe(Outcome::WonByX)
        ->and($analysis->isTerminal())->toBeTrue()
        ->and($analysis->markToMove)->toBeInstanceOf(Mark::class)
        ->and($analysis->markToMove)->toBe(Mark::O);
});
