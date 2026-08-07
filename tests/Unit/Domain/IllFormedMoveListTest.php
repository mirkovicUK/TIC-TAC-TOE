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
 * Task 3.5 — the five well-formedness violation classes.
 *
 * Every list here is built with `MoveList::fromMoves()`, which accepts its input
 * verbatim; `fromCellIndices()` and `append()` cannot express any of these
 * shapes, which is the whole reason `fromMoves()` exists (design, "Why
 * validation lives at the engine boundary rather than in constructors").
 *
 * Cells are numbered
 *
 *     0 1 2
 *     3 4 5
 *     6 7 8
 *
 * NOTHING HERE ASSERTS WHICH GUARD FIRED, AND THAT IS DELIBERATE. Requirement
 * 11.5 mandates a single uniform, detail-free rejection, so a list violating two
 * classes at once returns exactly the same value whichever guard reached it
 * first. Guard ordering is therefore unobservable from outside the engine, and
 * any assertion about it would have to reach inside the implementation. The
 * reachable property is uniformity, asserted explicitly in the last test in this
 * file — its absence here is not an oversight.
 *
 * Eris drives the three genuinely unbounded shapes (out-of-range cell indices in
 * both directions, lengths above nine, arbitrary sequence-index perturbations)
 * at its default 100 iterations, the minimum the design asks for. Each of those
 * tests asserts its own iteration count and the spread of what it was handed, so
 * a generator that silently produced nothing fails rather than passing quietly.
 * The bounded shapes — a repeat over the nine cells, a move after a win over the
 * five cells left vacant — are enumerated as datasets instead: where a domain is
 * small enough to exhaust, exhausting it beats sampling it.
 *
 * No framework boot: plain Pest functions under tests/Unit, so the generated
 * test class extends PHPUnit\Framework\TestCase. No `uses(Tests\TestCase::class)`,
 * no database, no session, no HTTP (Req 14.1).
 */

/**
 * The single assertion pair Property 5 demands, applied to one ill-formed list:
 * the result is *identically* the one rejection value, and nothing was derived.
 *
 * Returns that value so the uniformity test at the foot of this file can compare
 * representatives of the five classes against each other.
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
 * Narrows `Analysis|InvalidMoveList` for the two boundary cases that must *not*
 * be rejected, and fails loudly if they are.
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
 * Violation class 1 — a repeated Cell_Index.
 *
 * Two Moves on the same cell with contiguous sequence indices, so the repeat is
 * the only thing wrong with the list. Nine cases, one per cell: the domain is
 * the nine cells and nothing more, so it is enumerated rather than sampled.
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
 * Violation class 1, again — the repeat need not be adjacent, and the cells in
 * between are legal.
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
 * Violation class 2 — a Cell_Index outside 0..8, in both directions.
 *
 * The two boundaries are pinned by hand because they are the values an off-by-one
 * guard admits: 9 is the first index above the board, and -1 the first below it.
 * A guard written `> 8` alone passes every positive case here and fails on -1,
 * which is exactly why the negative side is not left to chance.
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
 * Violation class 2 — arbitrary out-of-range indices, generated. Unbounded in
 * both directions, so this is a property rather than a list of examples.
 *
 * The offending Move sits after a generated number of perfectly legal Moves
 * (cells 4, 0, 8 in that order: X holds 4 and 8, O holds 0, no line completes),
 * so the guard is shown to fire wherever in the list the bad Move happens to be
 * and not only at position zero.
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
        // The generators only produce out-of-range indices, so this filters
        // nothing. It constrains *shrinking*: without it a failure shrinks
        // towards zero and reports an in-range index as the counterexample,
        // which reads as nonsense. With it, a broken guard shrinks to 9 or -1.
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

    // Evidence the generator did work: a property that generated nothing would
    // pass every assertion above by never running one.
    expect($iterations)->toBeGreaterThanOrEqual(100)
        ->and(count(array_unique($cellsSeen)))->toBeGreaterThan(50)
        ->and(array_filter($cellsSeen, static fn (int $cell): bool => $cell < 0))->not->toBeEmpty()
        ->and(array_filter($cellsSeen, static fn (int $cell): bool => $cell > 8))->not->toBeEmpty();
});

/*
 * Violation class 3 — a Sequence_Index that is not its position.
 *
 * One comparison in the engine covers three shapes, and the point of writing all
 * three out is to demonstrate that rather than to take the design's word for it:
 * a gap, a repeated index, and a start other than zero. Cells are legal and
 * distinct in every case, so the sequence indices are the only fault.
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
 * Violation class 3 — arbitrary perturbations, generated. The set of wrong
 * sequence indices is unbounded, so one Move of an otherwise legal list has its
 * index displaced by a generated non-zero amount, in either direction.
 *
 * The cells are the nine-move draw of `RulesEngineTest` (0, 1, 2, 5, 3, 6, 4, 8,
 * 7), truncated to the generated length. No prefix of it completes a line, so no
 * other guard can be reached first and the displaced index is the only fault.
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
        // Filters nothing — the delta generator never yields zero — but keeps
        // shrinking honest: a displacement of zero is a *well formed* list, and
        // an unconstrained shrinker would happily offer it as the counterexample.
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
 * Violation class 4 — a length above nine.
 *
 * Ten Moves is the boundary the guard has to catch, so it is written out by hand
 * as well as generated.
 *
 * A list longer than nine must repeat a cell by pigeonhole, so this class cannot
 * be isolated from class 1 the way the others can. That is not a defect in the
 * test: Requirement 11.5 asks only that such a list be rejected uniformly, and it
 * is precisely why the uniformity test below matters more than any claim about
 * which guard fired.
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
        // Filters nothing; stops a shrinker from proposing nine or fewer Moves,
        // which is a well formed list and not a counterexample to anything.
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
 * X0 O3 X1 O4 X2 fills the top row on the fifth Move and is a well formed,
 * finished game:
 *
 *     X X X
 *     O O .
 *     . . .
 *
 * Appending anything at all is ill formed. The five cases are the five cells left
 * vacant — bounded, so enumerated. Each appended Move is otherwise impeccable:
 * legal cell, correct sequence index, no repeat.
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
 * The boundaries of the property, so that neither edge is swept into it.
 *
 * The empty list is well formed — it is the Move_List of every Game that has just
 * been created — and nine Moves is well formed too: the length guard is `> 9`,
 * not `>= 9`, and an off-by-one there would reject every completed game.
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
 * PROPERTY 5 ITSELF. Everything above shows that each violation class is
 * rejected; this shows that they are rejected *identically*, which is the claim
 * Requirement 11.5 actually makes and the one callers depend on.
 *
 * Five `toBe(InvalidMoveList::Error)` assertions would not establish it. Add
 * `InvalidMoveList::RepeatedCell` tomorrow and return it from the repeat guard,
 * and every individual test above still passes while a caller switching on the
 * value has silently acquired a second branch to handle — and a rejection that
 * now leaks which class was violated, which is what "deriving no Board, no
 * Mark_To_Move and no Outcome" is there to prevent. So the comparison here is
 * between the five results and each other, and the distinct-value count is taken
 * without naming any value at all.
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
        // The whole property, stated without naming a value: however a Move_List
        // is ill formed, there is exactly one thing the engine says about it.
        ->and($distinct)->toHaveCount(1)
        // And a second guard on the same claim from the type's side: one case
        // means there is nothing else the engine could have returned.
        ->and(InvalidMoveList::cases())->toHaveCount(1);

    foreach ($representatives as $violation => $rejection) {
        expect($rejection)->toBe(
            $representatives['a repeated cell index'],
            "{$violation} must be indistinguishable from every other violation",
        );
    }
});
