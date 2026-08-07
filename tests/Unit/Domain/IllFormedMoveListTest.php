<?php

declare(strict_types=1);

use App\Domain\TicTacToe\Analysis;
use App\Domain\TicTacToe\InvalidMoveList;
use App\Domain\TicTacToe\Move;
use App\Domain\TicTacToe\MoveList;
use App\Domain\TicTacToe\RulesEngine;
use Eris\Facade;
use Eris\Generators;

// Feature: remote-tic-tac-toe, Property 5: Ill-formed Move_Lists are rejected uniformly
//
// Validates: Requirements 11.5, 14.8
//
/*
 * The five well-formedness violation classes.
 *
 * Every list is built with `MoveList::fromMoves()`, which accepts its input
 * verbatim; `fromCellIndices()` and `append()` cannot express any of these shapes.
 *
 * Cells are numbered
 *
 *     0 1 2
 *     3 4 5
 *     6 7 8
 *
 * Nothing asserts which guard fired. Req 11.5 mandates a single detail-free
 * rejection, so guard ordering is unobservable from outside the engine and any
 * assertion about it would reach inside the implementation. Uniformity is the
 * reachable property, and it is the last test in this file.
 *
 * Eris drives the three unbounded shapes at its default 100 iterations. Each of
 * those tests asserts its iteration count and the spread it was handed, so a
 * generator that produced nothing fails rather than passing quietly. The bounded
 * shapes are enumerated as datasets instead.
 *
 * No framework boot: plain Pest functions under tests/Unit, so the generated test
 * class extends PHPUnit\Framework\TestCase (Req 14.1).
 */

/**
 * Property 5 for one ill-formed list: identically the one rejection value, and
 * nothing derived. Returns it so the uniformity test at the foot of this file can
 * compare representatives of the five classes against each other.
 */
function rejectionFor(MoveList $moveList, string $case): InvalidMoveList
{
    $result = RulesEngine::analyse($moveList);

    expect($result)->toBe(InvalidMoveList::Error, $case)
        ->and($result)->not->toBeInstanceOf(Analysis::class, $case);

    if (! $result instanceof InvalidMoveList) {
        throw new LogicException('Unreachable: the expectation above has already failed.');
    }

    return $result;
}

/**
 * Narrows `Analysis|InvalidMoveList` for the two boundary cases that must not be
 * rejected, and fails loudly if they are.
 */
function analysisForWellFormed(MoveList $moveList, string $case): Analysis
{
    $result = RulesEngine::analyse($moveList);

    expect($result)->not->toBe(InvalidMoveList::Error, $case)
        ->and($result)->toBeInstanceOf(Analysis::class, $case);

    if (! $result instanceof Analysis) {
        throw new LogicException('Unreachable: the expectation above has already failed.');
    }

    return $result;
}

/**
 * `[$cellIndex, $sequenceIndex]` pairs, verbatim, however ill-formed.
 *
 * @param  list<array{int, int}>  $pairs
 */
function moveListFromPairs(array $pairs): MoveList
{
    return MoveList::fromMoves(array_map(
        static fn (array $pair): Move => new Move($pair[0], $pair[1]),
        $pairs,
    ));
}

/*
 * Violation class 1 — a repeated Cell_Index. Contiguous sequence indices, so the
 * repeat is the only fault. Nine cases: the domain is the nine cells, so it is
 * enumerated rather than sampled.
 */
it('rejects a move list that plays the same cell twice', function (int $cell) {
    rejectionFor(
        moveListFromPairs([[$cell, 0], [$cell, 1]]),
        "cell {$cell} played twice",
    );
})->with([
    'cell 0' => [0], 'cell 1' => [1], 'cell 2' => [2],
    'cell 3' => [3], 'cell 4' => [4], 'cell 5' => [5],
    'cell 6' => [6], 'cell 7' => [7], 'cell 8' => [8],
]);

/*
 * Violation class 1 — the repeat need not be adjacent, and the cells between are
 * legal.
 *
 *     X . .        X4 O0 X8 O1 X4  — the fifth Move returns to cell 4
 *     . X .
 *     . . X
 */
it('rejects a move list that returns to a cell played earlier', function () {
    rejectionFor(
        moveListFromPairs([[4, 0], [0, 1], [8, 2], [1, 3], [4, 4]]),
        'cell 4 replayed four moves later',
    );
});

/*
 * Violation class 2 — a Cell_Index outside 0..8, in both directions. The two
 * boundaries are pinned by hand: a guard written `> 8` alone passes every positive
 * case and fails on -1.
 */
it('rejects a move list holding a cell index just outside the board', function (int $cell) {
    rejectionFor(
        moveListFromPairs([[$cell, 0]]),
        "cell index {$cell}",
    );
})->with([
    'one above the board' => [9],
    'one below the board' => [-1],
]);

/*
 * Violation class 2 — arbitrary out-of-range indices, generated.
 *
 * The offending Move sits after a generated number of legal Moves (cells 4, 0, 8:
 * X holds 4 and 8, O holds 0, no line completes), so the guard is shown to fire
 * wherever in the list the bad Move falls and not only at position zero.
 */
it('rejects a move list holding any cell index outside the board', function () {
    $eris = new Facade;

    $iterations = 0;
    /** @var list<int> $cellsSeen */
    $cellsSeen = [];

    $eris->forAll(
        Generators::oneOf(
            Generators::choose(9, 100_000),
            Generators::choose(-100_000, -1),
        ),
        Generators::choose(0, 3),
    )
        // Filters nothing; it constrains shrinking. Without it a failure shrinks
        // towards zero and reports an in-range index as the counterexample.
        ->when(static fn (int $cell, int $prefixLength): bool => $cell < 0 || $cell > 8)
        ->then(function (int $cell, int $prefixLength) use (&$iterations, &$cellsSeen): void {
            $iterations++;
            $cellsSeen[] = $cell;

            $pairs = [];

            foreach (array_slice([4, 0, 8], 0, $prefixLength) as $position => $legalCell) {
                $pairs[] = [$legalCell, $position];
            }

            $pairs[] = [$cell, $prefixLength];

            rejectionFor(moveListFromPairs($pairs), "cell index {$cell} at position {$prefixLength}");
        });

    // Non-vacuity: rules out a generator that produced nothing and so passed every
    // assertion above by never running one.
    expect($iterations)->toBeGreaterThanOrEqual(100)
        ->and(count(array_unique($cellsSeen)))->toBeGreaterThan(50)
        ->and(array_filter($cellsSeen, static fn (int $cell): bool => $cell < 0))->not->toBeEmpty()
        ->and(array_filter($cellsSeen, static fn (int $cell): bool => $cell > 8))->not->toBeEmpty();
});

/*
 * Violation class 3 — a Sequence_Index that is not its position. One comparison in
 * the engine covers all three shapes: a gap, a repeated index, and a start other
 * than zero. Cells are legal and distinct, so the indices are the only fault.
 */
it('rejects a move list whose sequence indices are not its positions', function (array $pairs, string $shape) {
    /** @var list<array{int, int}> $pairs */
    rejectionFor(moveListFromPairs($pairs), $shape);
})->with([
    'a gap: 0, 1, 3' => [[[4, 0], [0, 1], [8, 3]], 'sequence indices 0, 1, 3'],
    'a duplicate: 0, 1, 1' => [[[4, 0], [0, 1], [8, 1]], 'sequence indices 0, 1, 1'],
    'a start above zero: 1, 2, 3' => [[[4, 1], [0, 2], [8, 3]], 'sequence indices 1, 2, 3'],
]);

/*
 * Violation class 3 — arbitrary perturbations, generated: one Move of an otherwise
 * legal list has its index displaced by a non-zero amount in either direction.
 *
 * The cells are the nine-move draw of `RulesEngineTest` (0, 1, 2, 5, 3, 6, 4, 8, 7)
 * truncated to the generated length. No prefix of it completes a line, so no other
 * guard can be reached first.
 */
it('rejects a move list whose sequence index is displaced by any amount', function () {
    $eris = new Facade;

    $drawOrder = [0, 1, 2, 5, 3, 6, 4, 8, 7];

    $iterations = 0;
    /** @var list<int> $deltasSeen */
    $deltasSeen = [];

    $eris->forAll(
        Generators::choose(1, 9),
        Generators::choose(0, 8),
        Generators::oneOf(
            Generators::choose(-100_000, -1),
            Generators::choose(1, 100_000),
        ),
    )
        // Filters nothing; keeps shrinking honest, since a displacement of zero is a
        // well formed list that an unconstrained shrinker would offer as the
        // counterexample.
        ->when(static fn (int $length, int $offset, int $delta): bool => $delta !== 0)
        ->then(function (int $length, int $offset, int $delta) use ($drawOrder, &$iterations, &$deltasSeen): void {
            $iterations++;
            $deltasSeen[] = $delta;

            $position = $offset % $length;

            $pairs = [];

            foreach (array_slice($drawOrder, 0, $length) as $index => $cell) {
                $pairs[] = [$cell, $index === $position ? $index + $delta : $index];
            }

            rejectionFor(
                moveListFromPairs($pairs),
                "sequence index at position {$position} of {$length} displaced by {$delta}",
            );
        });

    expect($iterations)->toBeGreaterThanOrEqual(100)
        ->and(count(array_unique($deltasSeen)))->toBeGreaterThan(1)
        ->and(array_filter($deltasSeen, static fn (int $delta): bool => $delta < 0))->not->toBeEmpty()
        ->and(array_filter($deltasSeen, static fn (int $delta): bool => $delta > 0))->not->toBeEmpty();
});

/*
 * Violation class 4 — a length above nine, the boundary the guard has to catch.
 *
 * A list longer than nine must repeat a cell by pigeonhole, so this class cannot be
 * isolated from class 1 the way the others can. Req 11.5 asks only that such a list
 * be rejected uniformly.
 */
it('rejects a move list of ten moves', function () {
    $pairs = [];

    foreach (range(0, 9) as $position) {
        $pairs[] = [$position % 9, $position];
    }

    rejectionFor(moveListFromPairs($pairs), 'ten moves');
});

/*
 * Violation class 4 — arbitrary lengths above nine, generated.
 */
it('rejects a move list of any length above nine', function () {
    $eris = new Facade;

    $iterations = 0;
    /** @var list<int> $lengthsSeen */
    $lengthsSeen = [];

    $eris->forAll(Generators::choose(10, 500))
        // Filters nothing; stops a shrinker proposing nine or fewer Moves, which is a
        // well formed list.
        ->when(static fn (int $length): bool => $length > 9)
        ->then(function (int $length) use (&$iterations, &$lengthsSeen): void {
            $iterations++;
            $lengthsSeen[] = $length;

            $pairs = [];

            foreach (range(0, $length - 1) as $position) {
                $pairs[] = [$position % 9, $position];
            }

            rejectionFor(moveListFromPairs($pairs), "{$length} moves");
        });

    expect($iterations)->toBeGreaterThanOrEqual(100)
        ->and(count(array_unique($lengthsSeen)))->toBeGreaterThan(1)
        ->and(array_filter($lengthsSeen, static fn (int $length): bool => $length <= 9))->toBeEmpty();
});

/*
 * Violation class 5 — a Move following a Move that completes a Winning_Line.
 *
 * X0 O3 X1 O4 X2 fills the top row on the fifth Move:
 *
 *     X X X
 *     O O .
 *     . . .
 *
 * The cases are the cells left vacant, and each appended Move is otherwise
 * impeccable: legal cell, correct sequence index, no repeat.
 */
it('rejects a move list carrying a move after the game was won', function (int $cell) {
    $wonGame = MoveList::fromCellIndices(0, 3, 1, 4, 2);

    expect(RulesEngine::analyse($wonGame))->toBeInstanceOf(Analysis::class);

    rejectionFor(
        MoveList::fromMoves([...$wonGame->moves, new Move($cell, 5)]),
        "cell {$cell} played after the top row was completed",
    );
})->with([
    'then cell 5' => [5],
    'then cell 6' => [6],
    'then cell 7' => [7],
    'then cell 8' => [8],
]);

/*
 * The boundaries, so neither edge is swept into the property. The empty list is the
 * Move_List of every newly created Game, and the length guard is `> 9` rather than
 * `>= 9`, where an off-by-one would reject every completed game.
 */
it('accepts an empty move list', function () {
    $analysis = analysisForWellFormed(MoveList::empty(), 'the empty list');

    expect($analysis->moveCount)->toBe(0);
});

it('accepts a move list of nine moves', function () {
    $analysis = analysisForWellFormed(
        MoveList::fromCellIndices(0, 1, 2, 5, 3, 6, 4, 8, 7),
        'the nine-move draw',
    );

    expect($analysis->moveCount)->toBe(9);
});

/*
 * Property 5 itself: the classes are rejected identically, which is the claim of
 * Req 11.5.
 *
 * Five `toBe(InvalidMoveList::Error)` assertions would not establish it. Add
 * `InvalidMoveList::RepeatedCell` and return it from the repeat guard, and every
 * test above still passes while a caller switching on the value has acquired a
 * second branch and a rejection that leaks which class was violated. So the five
 * results are compared against each other, without naming any value.
 */
it('rejects every violation class with one indistinguishable value', function () {
    $representatives = [
        'a repeated cell index' => rejectionFor(
            moveListFromPairs([[4, 0], [4, 1]]),
            'a repeated cell index',
        ),
        'a cell index outside 0..8' => rejectionFor(
            moveListFromPairs([[9, 0]]),
            'a cell index outside 0..8',
        ),
        'a sequence index gap' => rejectionFor(
            moveListFromPairs([[4, 0], [0, 1], [8, 3]]),
            'a sequence index gap',
        ),
        'a length above nine' => rejectionFor(
            moveListFromPairs(array_map(
                static fn (int $position): array => [$position % 9, $position],
                range(0, 9),
            )),
            'a length above nine',
        ),
        'a move after a win' => rejectionFor(
            MoveList::fromMoves([
                ...MoveList::fromCellIndices(0, 3, 1, 4, 2)->moves,
                new Move(8, 5),
            ]),
            'a move after a win',
        ),
    ];

    $distinct = array_unique(array_map(
        static fn (InvalidMoveList $rejection): string => spl_object_hash($rejection),
        $representatives,
    ));

    expect($representatives)->toHaveCount(5)
        // However a Move_List is ill formed, there is one thing the engine says
        // about it.
        ->and($distinct)->toHaveCount(1)
        // The same claim from the type's side: one case means there is nothing else
        // the engine could have returned.
        ->and(InvalidMoveList::cases())->toHaveCount(1);

    foreach ($representatives as $violation => $rejection) {
        expect($rejection)->toBe(
            $representatives['a repeated cell index'],
            "{$violation} must be indistinguishable from every other violation",
        );
    }
});
