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
 * Every reachable Well_Formed_Move_List, depth-first from the empty one: 549,946
 * of them, of which 255,168 are finished Games. Requirement 14.2 asks for the
 * complete set and names both counts, so there is no fast mode, iteration cap or
 * environment flag that shortens the walk.
 *
 * The counts are external ground truth about tic-tac-toe, so what they vouch for
 * is the oracle and the harness — that this walk stopped descending where a
 * correct notion of "finished" stops. The engine's correctness comes from the
 * per-node agreement below, against an oracle those counts have vouched for.
 *
 * The oracle decides when to stop, never the engine. If `$analysis->isTerminal()`
 * drove the recursion, a bug in the engine's notion of "won" would prune the tree
 * into the shape that bug expects, the engine would agree with itself at every
 * node it chose to visit, and Properties 3 and 4 would be tautologies. So
 * `Support\LineOracle` — sharing no code and no type with `App\Domain\TicTacToe` —
 * says when a node is terminal and which lines are complete.
 *
 * For the same reason the board handed to the oracle is the walk's own plain
 * array, mutated on the way down and restored on the way back up, never read from
 * `$analysis->board`.
 *
 * Ten checks at each of 549,946 nodes is several million assertions PHPUnit would
 * count and format for no diagnostic gain, so the hot loop compares with `!==` and
 * accumulates the first few disagreements as strings; every `expect()` runs after
 * the walk.
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
 * How many disagreements are kept verbatim; the total still counts past this.
 */
const ENUMERATION_FAILURE_SAMPLE = 10;

/**
 * A canonical, name-free key for a line: its three cells, sorted, as a string.
 *
 * Lines compare as cell sets rather than by name because a name-to-triple table
 * here would be a table shared with `WinningLine::cells()`, wrong in both
 * identically and agreeing anyway. The oracle knows nothing of `WinningLine`.
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
 * whatever order either side produced them in.
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
 * `$board` is the position as a plain `'x'`/`'o'`/`null` array, maintained by this
 * function alone. `$depth` is the number of Moves played, so the Mark that has just
 * moved is the one at sequence index `$depth - 1` — `'x'` when `$depth` is odd.
 * Inverting that points the oracle at the wrong player's lines.
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

    // Formatted inside the closure so the hot path does not build half a million
    // strings it never prints.
    $fail = static function (string $what) use ($playedCells, &$state): void {
        $state['failureCount']++;

        if (count($state['failures']) < ENUMERATION_FAILURE_SAMPLE) {
            $position = $playedCells === [] ? 'the empty move list' : 'cells '.implode(' ', $playedCells);
            $state['failures'][] = "{$position}: {$what}";
        }
    };

    // Property 2 / Req 11.7: every position this walk reaches is well formed by
    // construction, so a rejection here means the engine refuses a list that legal
    // play produces.
    if (! $analysis instanceof Analysis) {
        $fail('rejected as ill formed, but every reachable move list is well formed');

        return;
    }

    // Property 1 / Req 11.2: replay determinism, compared field by field because
    // the claim is exact repetition rather than equivalence.
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

    // Req 4.1: parity of the length, defined in terminal states too. Checked against
    // parity computed here as well as the domain's own helper, so this is not purely
    // the helper agreeing with itself.
    $expectedToMove = $depth % 2 === 0 ? Mark::X : Mark::O;

    if ($analysis->markToMove !== $expectedToMove
        || $analysis->markToMove !== Mark::forSequenceIndex($depth)) {
        $fail("reported {$analysis->markToMove->value} to move, expected {$expectedToMove->value}");
    }

    // Property 1 / Req 11.2: Board occupancy is a function of the Move_List alone,
    // compared cell by cell against the walk's own board.
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

    // Properties 3, 4 / Req 6.3: every line the mark occupies, not the first found.
    // The double-line case is pinned as a named example in `RulesEngineTest`; here it
    // is checked across the whole tree.
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

    // Non-vacuity: the player who did not just move can hold no line at a reachable
    // node, so a non-empty set here rules out the recursion having descended past a
    // finished game and weakened every check above it.
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

    // Property 2 / Req 11.3, 6.1 for the single Outcome; Property 4 / Req 11.8, 6.2
    // for the win branches; Property 3 / Req 11.7, 6.4 for the draw branch.
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

        // Classified from the oracle's judgement, so the three sub-totals asserted at
        // the foot of this file are as external as the two headline counts.
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

// One test, not 549,946: the tree is traversed once and the evidence asserted after.
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
        // Non-vacuity: a board not restored to empty rules out the oracle having
        // judged the positions the recursion thought it was in.
        ->and($board)->toBe(array_fill(0, 9, null));

    /*
     * Both counts are external ground truth, not negotiable against a run of this
     * test.
     *
     * `nodes` counts on entry to each node, the empty Move_List included: the root
     * counts, which is the convention under which Req 14.2 names 549,946. A run
     * reporting 549,945 has skipped the root — fix the accounting here.
     *
     * 255,168 is the accepted count of distinct tic-tac-toe games and has no
     * convention latitude. A mismatch is something to debug, never to adjust.
     */
    expect($state['nodes'])->toBe(549_946)
        ->and($state['terminals'])->toBe(255_168);

    /*
     * The published split of the 255,168 games: X wins more by moving first and
     * holding five cells to O's four. Asserting the split as well as the total
     * catches two errors of equal size in opposite directions.
     */
    expect($state['xWins'])->toBe(131_184)
        ->and($state['oWins'])->toBe(77_904)
        ->and($state['draws'])->toBe(46_080)
        ->and($state['xWins'] + $state['oWins'] + $state['draws'])->toBe($state['terminals']);
});
