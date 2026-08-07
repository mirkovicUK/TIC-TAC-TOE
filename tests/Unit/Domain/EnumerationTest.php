<?php

declare(strict_types=1);

use App\Domain\TicTacToe\Analysis;
use App\Domain\TicTacToe\Mark;
use App\Domain\TicTacToe\MoveList;
use App\Domain\TicTacToe\Outcome;
use App\Domain\TicTacToe\RulesEngine;
use App\Domain\TicTacToe\WinningLine;
use Tests\Unit\Domain\Support\LineOracle;

// Feature: remote-tic-tac-toe, Property 1: Replay determinism
// Feature: remote-tic-tac-toe, Property 2: Outcome exclusivity and totality
// Feature: remote-tic-tac-toe, Property 3: Draw characterisation
// Feature: remote-tic-tac-toe, Property 4: Win detection completeness
//
// Validates: Requirements 14.2, 11.2, 11.3, 11.7, 11.8, 6.1, 6.2, 6.3, 6.4
//
/*
 * Task 3.6 — the exhaustive walk.
 *
 * Every reachable Well_Formed_Move_List, depth-first from the empty one: 549,946
 * of them, of which 255,168 are the Move_List of a finished Game. Not a sample.
 * Requirement 14.2 asks for enumeration of the *complete* set and names both
 * counts, so there is no fast mode, no iteration cap and no environment flag
 * here that shortens the walk.
 *
 * WHAT THE TWO COUNTS PROVE, AND WHAT THEY DO NOT. They are external ground
 * truth about tic-tac-toe, so they check the ORACLE and the HARNESS: 255,168 is
 * the accepted number of distinct games, and reaching it means this walk stopped
 * descending in exactly the places a correct notion of "finished" stops. It says
 * nothing directly about `RulesEngine`. The engine's correctness comes from the
 * per-node agreement below — with an oracle whose judgement the counts have
 * independently vouched for. Counts without per-node checks would be a headline
 * with no story; per-node checks without the counts would leave open that both
 * implementations agreed while walking the wrong tree. Together they close.
 *
 * THE ORACLE DECIDES WHEN TO STOP, NEVER THE ENGINE. This is the one structural
 * constraint that matters. If `$analysis->isTerminal()` drove the recursion, a
 * bug in the engine's notion of "won" would prune the tree into precisely the
 * shape that bug expects; the engine would then agree with itself at every node
 * it chose to visit, and Properties 3 and 4 would be tautologies with half a
 * million passing assertions behind them. So `Support\LineOracle` — an
 * independent, hand-written implementation sharing no code and no type with
 * `App\Domain\TicTacToe` — says when a node is terminal and which lines are
 * complete, and the engine is compared against it.
 *
 * The board handed to the oracle is the walk's own plain array, mutated on the
 * way down and restored on the way back up. It is never read from
 * `$analysis->board`: that would make the board check circular, and rebuilding
 * the board from the Move_List at each node would cost more than carrying it
 * (mitigation 2 of the design's runtime budget).
 *
 * ASSERTION ACCOUNTING. Roughly ten checks at each of 549,946 nodes is several
 * million assertions, which PHPUnit counts, formats and slows to a crawl for no
 * diagnostic gain. The hot loop therefore compares with plain `!==` and
 * accumulates the first few disagreements as strings; the `expect()` calls all
 * live after the walk, where the failure count, the collected counterexamples and
 * the aggregate counters are asserted. A break is diagnosable from the failure
 * message, which names the Move_List that broke.
 *
 * Cells are numbered
 *
 *     0 1 2
 *     3 4 5
 *     6 7 8
 *
 * No framework boot: plain Pest functions under tests/Unit, so the generated test
 * class extends PHPUnit\Framework\TestCase (Req 14.1).
 */

/**
 * How many disagreements are kept verbatim. Past this the total still counts, but
 * a walk this size producing thousands of them is diagnosed from the first few.
 */
const ENUMERATION_FAILURE_SAMPLE = 10;

/**
 * A canonical, name-free key for a line: its three cells, sorted, as a string.
 *
 * Comparing lines as CELL SETS rather than by name is deliberate. A
 * name-to-triple table in this file would be a table shared with
 * `WinningLine::cells()` — wrong in both identically and agreeing anyway — which
 * is exactly what the oracle's independence exists to prevent. The oracle knows
 * nothing of `WinningLine`; cells are the only vocabulary both sides speak.
 *
 * @param  array{int, int, int}  $cells
 */
function enumerationLineKey(array $cells): string
{
    $sorted = $cells;
    sort($sorted);

    return implode(',', $sorted);
}

/**
 * A set of lines as a sorted list of keys, so two sets compare with `!==`
 * irrespective of the order either side happened to produce them in.
 *
 * @param  list<array{int, int, int}>  $lines
 * @return list<string>
 */
function enumerationLineKeys(array $lines): array
{
    $keys = array_map(
        static fn (array $cells): string => enumerationLineKey($cells),
        $lines,
    );

    sort($keys);

    return $keys;
}

/**
 * One depth-first node, then its children.
 *
 * `$playedCells` is the Move_List as cell indices; `$board` is the same position
 * as a plain `'x'`/`'o'`/`null` array, maintained by this function alone.
 * `$depth` is the number of Moves played, so the Mark that has just moved is the
 * one at sequence index `$depth - 1` — `'x'` when `$depth` is ODD. Inverting that
 * would point the oracle at the wrong player's lines and is the easiest mistake
 * in the whole file to make quietly.
 *
 * @param  list<int>  $playedCells
 * @param  array<int, string|null>  $board  Exactly nine entries keyed 0..8.
 * @param  array{nodes: int, terminals: int, xWins: int, oWins: int, draws: int, failures: list<string>, failureCount: int}  $state
 */
function enumerationWalk(array $playedCells, array &$board, int $depth, LineOracle $oracle, array &$state): void
{
    $state['nodes']++;

    $moveList = MoveList::fromCellIndices(...$playedCells);
    $analysis = RulesEngine::analyse($moveList);

    // The position is formatted inside the closure rather than before it, so the
    // hot path does not build half a million strings it will never print.
    $fail = static function (string $what) use ($playedCells, &$state): void {
        $state['failureCount']++;

        if (count($state['failures']) < ENUMERATION_FAILURE_SAMPLE) {
            $position = $playedCells === [] ? 'the empty move list' : 'cells '.implode(' ', $playedCells);
            $state['failures'][] = "{$position}: {$what}";
        }
    };

    // Property 2 / Req 11.7: every position this walk can reach is well formed by
    // construction — contiguous sequence indices, legal distinct cells, at most
    // nine Moves, and no Move after the oracle called a halt. A rejection here
    // would mean the engine refuses a list that legal play produces.
    if (! $analysis instanceof Analysis) {
        $fail('rejected as ill formed, but every reachable move list is well formed');

        return;
    }

    // Property 1 / Req 11.2: replay determinism. The same list analysed twice
    // yields an identical Board, Mark_To_Move, Outcome and Winning_Line set —
    // compared field by field, in order, because determinism is a claim about the
    // implementation repeating itself exactly, not merely about equivalence.
    $replay = RulesEngine::analyse($moveList);

    if (! $replay instanceof Analysis
        || $replay->outcome !== $analysis->outcome
        || $replay->markToMove !== $analysis->markToMove
        || $replay->moveCount !== $analysis->moveCount
        || $replay->board->cells() !== $analysis->board->cells()
        || $replay->winningLines !== $analysis->winningLines) {
        $fail('a second analysis of the same move list differed from the first');
    }

    if ($analysis->moveCount !== $depth) {
        $fail("reported a move count of {$analysis->moveCount}, expected {$depth}");
    }

    // Req 4.1, unconditional: parity of the LENGTH, defined in terminal states
    // too. Checked against parity computed here as well as against the domain's
    // own helper, so this is not purely the helper agreeing with itself.
    $expectedToMove = $depth % 2 === 0 ? Mark::X : Mark::O;

    if ($analysis->markToMove !== $expectedToMove
        || $analysis->markToMove !== Mark::forSequenceIndex($depth)) {
        $fail("reported {$analysis->markToMove->value} to move, expected {$expectedToMove->value}");
    }

    // Property 1 / Req 11.2: Board occupancy is a function of the Move_List
    // alone. The comparison is against the walk's independently maintained
    // board, cell by cell.
    foreach ($board as $cellIndex => $expectedOccupant) {
        $derived = $analysis->board->occupantOf($cellIndex)?->value;

        if ($derived !== $expectedOccupant) {
            $fail(sprintf(
                'cell %d derived as %s, expected %s',
                $cellIndex,
                $derived ?? 'vacant',
                $expectedOccupant ?? 'vacant',
            ));
        }
    }

    $justMoved = $depth > 0 ? ($depth % 2 === 1 ? 'x' : 'o') : null;
    $oracleLines = $justMoved !== null ? $oracle->completedLines($board, $justMoved) : [];
    $oracleTerminal = $justMoved !== null && $oracle->isTerminal($board, $depth, $justMoved);

    // Properties 3, 4 / Req 6.3: the reported set is EVERY line the mark
    // occupies, not the first found. This is the only place in the suite where
    // the double-line case is checked across the whole tree rather than at one
    // pinned position.
    $engineKeys = enumerationLineKeys(array_map(
        static fn (WinningLine $line): array => $line->cells(),
        $analysis->winningLines,
    ));
    $oracleKeys = enumerationLineKeys($oracleLines);

    if ($engineKeys !== $oracleKeys) {
        $fail(sprintf(
            'reported winning lines [%s], oracle says [%s]',
            implode('] [', $engineKeys),
            implode('] [', $oracleKeys),
        ));
    }

    // Property 3's "by either Mark": the player who did NOT just move can hold no
    // line at any reachable node, because the walk halts the moment a line
    // appears. A non-empty set here would mean the recursion descended past a
    // finished game — a harness fault, and one that would silently weaken every
    // check above it.
    if ($justMoved !== null) {
        $opponentLines = $oracle->completedLines($board, $justMoved === 'x' ? 'o' : 'x');

        if ($opponentLines !== []) {
            $fail('the player who did not just move holds a line, so the walk descended past a finished game');
        }
    }

    if ($analysis->isTerminal() !== $oracleTerminal) {
        $fail(sprintf(
            'reported terminal=%s, oracle says terminal=%s',
            $analysis->isTerminal() ? 'true' : 'false',
            $oracleTerminal ? 'true' : 'false',
        ));
    }

    // Property 2 / Req 11.3, 6.1: exactly one Outcome, and it agrees with the
    // oracle's independent classification. Property 4 / Req 11.8, 6.2 for the two
    // win branches; Property 3 / Req 11.7, 6.4 for the draw branch — drawn if and
    // only if nine Moves and no line.
    $expectedOutcome = match (true) {
        $oracleLines !== [] => $justMoved === 'x' ? Outcome::WonByX : Outcome::WonByO,
        $depth === 9 => Outcome::Drawn,
        default => Outcome::InProgress,
    };

    if ($analysis->outcome !== $expectedOutcome) {
        $fail("reported outcome {$analysis->outcome->value}, expected {$expectedOutcome->value}");
    }

    $expectedWinner = $oracleLines !== [] ? $justMoved : null;
    $reportedWinner = $analysis->winner()?->value;

    if ($reportedWinner !== $expectedWinner) {
        $fail(sprintf(
            'reported winner %s, expected %s',
            $reportedWinner ?? 'none',
            $expectedWinner ?? 'none',
        ));
    }

    if ($oracleTerminal) {
        $state['terminals']++;

        // Classified from the ORACLE's judgement, not the engine's, so the three
        // sub-totals reported at the foot of this file are as external as the two
        // headline counts.
        if ($oracleLines === []) {
            $state['draws']++;
        } elseif ($justMoved === 'x') {
            $state['xWins']++;
        } else {
            $state['oWins']++;
        }

        return;
    }

    $mark = $depth % 2 === 0 ? 'x' : 'o';

    for ($cell = 0; $cell < 9; $cell++) {
        if ($board[$cell] !== null) {
            continue;
        }

        $board[$cell] = $mark;
        $playedCells[] = $cell;

        enumerationWalk($playedCells, $board, $depth + 1, $oracle, $state);

        array_pop($playedCells);
        $board[$cell] = null;
    }
}

/*
 * The walk itself. One test, not 549,946: the tree is traversed once and the
 * evidence is asserted afterwards.
 */
it('agrees with an independent oracle at every reachable position in the game tree', function () {
    /** @var array<int, string|null> $board */
    $board = array_fill(0, 9, null);

    /** @var array{nodes: int, terminals: int, xWins: int, oWins: int, draws: int, failures: list<string>, failureCount: int} $state */
    $state = [
        'nodes' => 0,
        'terminals' => 0,
        'xWins' => 0,
        'oWins' => 0,
        'draws' => 0,
        'failures' => [],
        'failureCount' => 0,
    ];

    enumerationWalk([], $board, 0, new LineOracle, $state);

    $counterexamples = $state['failures'] === []
        ? ''
        : sprintf(
            "%d disagreements; first %d:\n  %s",
            $state['failureCount'],
            count($state['failures']),
            implode("\n  ", $state['failures']),
        );

    expect($state['failureCount'])->toBe(0, $counterexamples)
        // The board is restored on the way back up, so the walk must leave it as
        // it found it. If it does not, the positions the oracle judged were not
        // the positions the recursion thought it was in.
        ->and($board)->toBe(array_fill(0, 9, null));

    /*
     * THE TWO COUNTS. Both are external ground truth, and neither is negotiable
     * against a run of this test.
     *
     * `nodes` counts on ENTRY to each node, the empty Move_List included: the
     * root counts. Requirement 14.2 names 549,946 reachable Move_Lists under
     * exactly that convention. A run reporting 549,945 has skipped the root —
     * fix the accounting here, not the Rules_Engine, and not this number.
     *
     * `terminals` has no convention latitude at all. 255,168 is the accepted
     * count of distinct tic-tac-toe games; a Move_List either finished or it did
     * not. A mismatch means this walk and the engine disagree with the
     * combinatorics, and the answer is to debug, never to adjust.
     */
    expect($state['nodes'])->toBe(549_946)
        ->and($state['terminals'])->toBe(255_168);

    /*
     * Positive evidence, and three further external checks. The published split
     * of the 255,168 games is 131,184 won by X, 77,904 won by O and 46,080 drawn.
     * X wins far more often simply by moving first and holding five cells to O's
     * four. Asserting the split as well as the total catches a class of fault the
     * total cannot: two errors of equal size in opposite directions.
     */
    expect($state['xWins'])->toBe(131_184)
        ->and($state['oWins'])->toBe(77_904)
        ->and($state['draws'])->toBe(46_080)
        ->and($state['xWins'] + $state['oWins'] + $state['draws'])->toBe($state['terminals']);
});
