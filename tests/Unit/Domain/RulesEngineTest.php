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
 * Task 3.4 — worked examples of the rules (Req 11.4, 14.1).
 *
 * Every expectation here is a literal. `Support\LineOracle` is deliberately not
 * used, and nothing is derived from `RulesEngine` to check `RulesEngine`: the
 * value of this file is that a reviewer checks the expectations against their
 * own knowledge of tic-tac-toe. The oracle belongs to the exhaustive walk of
 * task 3.6, where half a million positions need a judge; here the reviewer is
 * the judge, and a second implementation would only be a second thing to trust.
 *
 * Cells are numbered
 *
 *     0 1 2
 *     3 4 5
 *     6 7 8
 *
 * and each set-up carries the finished grid beside it, so the expectation can be
 * checked by looking rather than by replaying the moves in your head.
 *
 * No framework boot: plain Pest functions under tests/Unit, so the generated
 * test class extends PHPUnit\Framework\TestCase. There is no
 * `uses(Tests\TestCase::class)`, no database, no session, no HTTP and no
 * framework helper anywhere in this file (Req 14.1). Task 3.7 asserts that
 * mechanically.
 */

/**
 * Narrows `Analysis|InvalidMoveList`, and fails loudly if a sequence this file
 * presents as a legal game is in fact rejected — a rejected list would derive
 * nothing, so every later assertion would be testing something other than what
 * it reads as.
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
 * Requirement 11.4 is definitional under this design — the Mark is parity, it is
 * never stored — so this is the one place it is covered. There is nothing
 * independent to derive it from, and a property test would only compare parity
 * against parity, so the nine indices are written out with their expected Marks
 * by hand.
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
 * Every sequence is a legal game: X takes the three cells of its line on moves
 * 0, 2 and 4, completing it on the last of them, and O's two cells never form a
 * line (two marks cannot). No earlier X move completes any line either, because
 * two marks cannot. The helper asserts the list was accepted before anything
 * else is read from it, so a sequence that had become illegal would fail here
 * rather than quietly assert the wrong thing.
 *
 * The grid beside each row is the finished board, rows separated by ` / `.
 */
it('reports a win by X, and exactly the line completed', function (WinningLine $line, int ...$cellsInPlayOrder) {
    $analysis = analyseAsWellFormed(MoveList::fromCellIndices(...$cellsInPlayOrder));

    // The line's name is passed as the failure message as well as being the
    // dataset key, so a break is identifiable from the failure body alone and
    // not only from the list of test names above it.
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
 * O wins too, so `won_by_o` and a winner of O are exercised and not merely
 * assumed to follow from the X cases.
 *
 * X3 O0 X4 O1 X6 O2 — cells in play order 3, 0, 4, 1, 6, 2.
 *
 *     O O O
 *     X X .
 *     X . .
 *
 * Legal: X ends on 3, 4 and 6, which is no line, so nothing completes before O's
 * third move fills the top row.
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
 * The nine-move draw. Cells in play order: 0, 1, 2, 5, 3, 6, 4, 8, 7 —
 * X0 O1 X2 O5 X3 O6 X4 O8 X7.
 *
 *     X O X
 *     X X O
 *     O X O
 *
 * X holds 0, 2, 3, 4, 7 and O holds 1, 5, 6, 8; neither set contains a line, and
 * no prefix of either can, so the board fills with nothing completed.
 *
 * `markToMove` is O, the parity of nine.
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
 * The double winning line: X0 O1 X2 O3 X6 O5 X8 O7 X4 — cells in play order
 * 0, 1, 2, 3, 6, 5, 8, 7, 4. X's ninth move at the centre completes both
 * diagonals at once.
 *
 *     X O X
 *     O X O
 *     X O X
 *
 * Pinned here on purpose. This position is the reason Requirement 6, criteria 3
 * and 5 are plural, the reason `winningLines` is a list rather than a single
 * value, and the reason the board highlights every completed line rather than
 * the first one found. The exhaustive walk of task 3.6 reaches it as one node
 * among 549,946, where no reviewer would ever see it; here it is a named case
 * with the grid drawn out.
 *
 * The two lines are asserted order-insensitively — the order
 * `completedLinesFor` happens to return them in is an implementation detail.
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
 * Requirement 4.1 is unconditional: Mark_To_Move is the parity of the Move_List
 * length in a terminal state too, where it names who *would* have moved next.
 * The top-row win above ends at five moves, so `markToMove` is O.
 *
 * The consequence lands on task 6.3. On this board the O player's `isYourTurn`
 * is true even though the game is over, so `markToMove` alone cannot disable the
 * board — which is why `Board.tsx`'s disabled condition is
 * `!isYourTurn || state !== 'active'` and needs both halves.
 */
it('still reports a mark to move after the game has been won', function () {
    $analysis = analyseAsWellFormed(MoveList::fromCellIndices(0, 3, 1, 4, 2));

    expect($analysis->outcome)->toBe(Outcome::WonByX)
        ->and($analysis->isTerminal())->toBeTrue()
        ->and($analysis->markToMove)->toBeInstanceOf(Mark::class)
        ->and($analysis->markToMove)->toBe(Mark::O);
});
