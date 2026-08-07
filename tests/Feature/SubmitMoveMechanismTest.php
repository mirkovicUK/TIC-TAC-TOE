<?php

declare(strict_types=1);

use App\Domain\TicTacToe\Mark;
use App\Domain\TicTacToe\Outcome;
use App\Games\CorruptMoveListException;
use App\Games\GameSnapshot;
use App\Games\GameState;
use App\Games\JoinCode;
use App\Games\MoveAccepted;
use App\Games\MoveOutcome;
use App\Games\SubmitMove;
use App\Models\Game;
use App\Models\Move;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Feature: remote-tic-tac-toe, Property 9: Rejected requests change nothing
// Feature: remote-tic-tac-toe, Property 12: The Version_Counter increments exactly
// once per committed state-changing operation
//
// Validates: Requirements 3.5, 4.2, 4.3, 4.4, 4.5, 4.6, 4.7, 5.4, 6.2, 6.4
//
/*
 * Task 6.1 — `SubmitMove`, the guards and the mechanism.
 *
 * A Feature test necessarily: the subject inserts a row and updates a row, and the
 * claims worth making are about what reached the database. `RefreshDatabase`
 * supplies the schema that `DB_DATABASE=:memory:` otherwise leaves absent, as in
 * `JoinGameTest`.
 *
 * WHAT THIS FILE COVERS AND WHAT IT DELIBERATELY LEAVES TO 6.6 AND 6.8. This is
 * the *mechanism* half: the four guards and their order, the absence of any
 * `SELECT`, the two statements of the accepted path, both unique indexes mapping to
 * `conflict`, and the corruption seam rolling its insert back. It is named
 * `SubmitMoveMechanismTest` so it does not collide with two files the plan has not
 * written yet:
 *
 *   - Task 6.6's `SubmitMoveTest` owes the behavioural sweep — the win transition
 *     across *every* completed line including the double diagonal, and the
 *     rejection sweep asserted through the HTTP surface once task 6.2 exists,
 *     which is the only place a payload carrying a `mark` field can be shown to be
 *     ignored (Req 3.6). There is no payload in this file to ignore.
 *   - Task 6.8's `ConcurrencyTest` owes the one assertion this file cannot make:
 *     two calls sharing ONE snapshot, with the Move_List asserted to have gone
 *     from n to n+1. That is Property 14 and the only mechanical guard on the
 *     no-re-query invariant. The conflict test below reaches the same `catch` by
 *     a different route and does not replace it.
 */

uses(RefreshDatabase::class);

/**
 * A saved Game in `$state`, with both token slots occupied and `last_activity_at`
 * backdated so that an accepted Move visibly moves it.
 *
 * The token hashes are arbitrary digests rather than issued credentials, because
 * `SubmitMove` never reads them: the acting Mark arrives as a parameter, and that
 * is the whole point of the signature. They are set so the row looks like one a
 * real join produced.
 *
 * Attributes are assigned one by one because mass assignment is closed on this
 * model.
 */
function submittingGame(GameState $state = GameState::Active, ?Mark $winningMark = null): Game
{
    $game = new Game;
    $game->id = Str::uuid7()->toString();
    $game->join_code = JoinCode::generate()->stored;
    $game->state = $state;
    $game->winning_mark = $winningMark;
    $game->version_counter = 3;
    $game->x_token_hash = hash('sha256', 'x');
    $game->o_token_hash = $state === GameState::WaitingForOpponent ? null : hash('sha256', 'o');
    $game->last_activity_at = now()->subMinutes(5);
    $game->save();

    return $game;
}

/**
 * Records `$cellIndices` as a contiguous Move_List from zero, the way a sequence of
 * accepted Moves would leave the table.
 */
function submittingMoves(Game $game, int ...$cellIndices): void
{
    foreach (array_values($cellIndices) as $position => $cellIndex) {
        $move = new Move;
        $move->game_id = $game->id;
        $move->cell_index = $cellIndex;
        $move->sequence_index = $position;
        $move->save();
    }
}

/**
 * The four columns an accepted Move is allowed to move, read straight from the
 * table rather than through the model, so a stale in-memory instance cannot make an
 * assertion pass.
 *
 * @return array{state: string, winning_mark: string|null, version_counter: int, last_activity_at: string}
 */
function submittingRowOf(string $gameId): array
{
    $row = (array) DB::table('games')->where('id', $gameId)->first();

    return [
        'state' => (string) $row['state'],
        'winning_mark' => is_string($row['winning_mark']) ? $row['winning_mark'] : null,
        'version_counter' => (int) $row['version_counter'],
        'last_activity_at' => (string) $row['last_activity_at'],
    ];
}

/**
 * The persisted Move_List as `[sequence_index => cell_index]`, ordered.
 *
 * @return array<int, int>
 */
function submittingMoveRowsOf(string $gameId): array
{
    $rows = DB::table('moves')->where('game_id', $gameId)->orderBy('sequence_index')->get();

    $list = [];

    foreach ($rows as $row) {
        $list[(int) $row->sequence_index] = (int) $row->cell_index;
    }

    return $list;
}

/**
 * Every SQL statement issued while `$work` runs, lower-cased and in order.
 *
 * The query log is the only way to assert an ABSENCE of queries, which is what the
 * purity invariant is: no `SELECT` anywhere between the first guard and the insert.
 * `BEGIN`/`COMMIT` are issued through PDO and do not appear here, so the log is
 * exactly the statements the subject composed.
 *
 * @param  callable(): mixed  $work
 * @return list<string>
 */
function submittingStatementsDuring(callable $work): array
{
    DB::enableQueryLog();
    DB::flushQueryLog();

    $work();

    $statements = array_map(
        static fn (array $entry): string => strtolower((string) $entry['query']),
        DB::getQueryLog(),
    );

    DB::disableQueryLog();

    return array_values($statements);
}

/*
 * THE ACCEPTED MOVE (Req 4.2, 4.7).
 *
 * The Sequence_Index is asserted as the length of the Move_List *before*
 * acceptance, in both places it appears: on the returned `MoveAccepted` and in the
 * `moves` row. The Version_Counter is asserted as `3 → 4` against the persisted
 * column rather than as "greater than before", since Requirement 4.7 is an
 * increment of one and `version_counter + 1` is evaluated by the database.
 *
 * `winning_mark` is asserted to still be NULL, because the CHECK pairing it with
 * `state = 'won'` means a stray write there would be a constraint failure on some
 * *other* path rather than here.
 */
it('appends the move at the observed sequence index and increments the version counter by exactly one', function () {
    $game = submittingGame();
    submittingMoves($game, 0, 3);

    $observed = GameSnapshot::of($game);
    $before = submittingRowOf($game->id);

    $result = (new SubmitMove)->handle($observed, Mark::X, 4);

    expect($result)->toBeInstanceOf(MoveAccepted::class, 'a legal Move by the Player to move was not accepted');

    // Narrowed for the analyser as well as the reader; the expectation above is
    // what actually fails if the Move was refused.
    if (! $result instanceof MoveAccepted) {
        throw new RuntimeException('the Move was refused, so the assertions below would say nothing');
    }

    $after = submittingRowOf($game->id);

    expect($result->sequenceIndex)->toBe(2, 'the Move was not recorded at the length of the Move_List before acceptance (Req 4.2)')
        ->and($result->cellIndex)->toBe(4, 'the reported Cell is not the Cell that was attempted')
        // Deliberately NOT claiming this shows the Mark came from the acting
        // parameter. The turn guard has already established `$actingMark ===
        // markToMove`, so `mark: $actingMark` and `mark:
        // Mark::forSequenceIndex($sequenceIndex)` are provably equal here and no
        // assertion in this file can separate them — substituting one for the
        // other leaves the whole file green, which was verified. Provenance is
        // observable only where a payload can carry a competing `mark` (Req 3.6),
        // and that is task 6.6 through the HTTP surface. What this asserts is the
        // weaker, still worth having claim: the reported Mark is the Mark that was
        // to move.
        ->and($result->mark)->toBe(Mark::X, 'the reported Mark is not the Mark that was to move')
        ->and($result->outcome)->toBe(Outcome::InProgress)
        ->and(submittingMoveRowsOf($game->id))->toBe([0 => 0, 1 => 3, 2 => 4], 'the persisted Move_List is not the observed list with the Move appended at n (Req 4.2)')
        ->and($before['version_counter'])->toBe(3)
        ->and($after['version_counter'])->toBe(4, 'the Version_Counter was not incremented by exactly one (Req 4.7)')
        ->and($after['state'])->toBe(GameState::Active->value, 'an in-progress Game did not stay active')
        ->and($after['winning_mark'])->toBeNull('a Move that completed no line recorded a winning Mark')
        ->and($after['last_activity_at'])->toBeGreaterThan($before['last_activity_at'], 'last_activity_at was not moved by the accepted Move');
});

/*
 * THE WIN TRANSITION (Req 6.2).
 *
 * X holds cells 0 and 1 and takes 2, completing the top row. The state and the
 * winning Mark are asserted on the ROW, because they are the two derived facts this
 * class persists rather than leaves to be re-derived — Property 11's "the persisted
 * `winning_mark` equals the derived winner" is a claim about the UPDATE here.
 *
 * Task 6.6 owes the sweep across all eight lines and the double diagonal; this
 * asserts the transition happens at all and writes both columns.
 *
 * KNOWN LIMIT OF THE TWO COLUMN ASSERTIONS, so nobody mistakes them for more than
 * they are. The CHECK on `games` pairs the two — `(state = 'won' AND winning_mark
 * IS NOT NULL) OR (state <> 'won' AND winning_mark IS NULL)` — so a mutation that
 * writes NULL to `winning_mark`, or writes `active` while a winner exists, dies on
 * a `QueryException` before either expectation below is evaluated, and the message
 * written for it never prints. Both were tried; both do fail this test, but by the
 * schema's hand rather than by this test's assertions. The claim that is genuinely
 * this test's own is the VALUE: writing the *losing* Mark satisfies the CHECK,
 * reaches the expectation, and fails with the message beside it. That is why the
 * value assertion is specific — `toBe(Mark::X->value)` rather than "not null".
 */
it('sets the state to won and records the winning mark when the move completes a line', function () {
    $game = submittingGame();
    submittingMoves($game, 0, 3, 1, 4);

    $result = (new SubmitMove)->handle(GameSnapshot::of($game), Mark::X, 2);

    $after = submittingRowOf($game->id);

    expect($result instanceof MoveAccepted ? $result->outcome : null)->toBe(Outcome::WonByX, 'the accepted Move did not report the win it completed')
        ->and($after['state'])->toBe(GameState::Won->value, 'a Move completing a Winning_Line did not set the Game_State to won (Req 6.2)')
        ->and($after['winning_mark'])->toBe(Mark::X->value, 'the winning Mark was not recorded (Req 6.2)')
        ->and($after['version_counter'])->toBe(4, 'the winning Move did not increment the Version_Counter (Req 4.7)');
});

/*
 * THE DRAW (Req 6.4).
 *
 * Eight Moves that complete no line, and a ninth that fills the board without
 * completing one either: X on 0, 2, 3, 7, 8 and O on 1, 4, 5, 6. Asserted with
 * `winning_mark` still NULL, which the CHECK on `games` would reject alongside
 * `state = 'drawn'` anyway — so this pins the mapping rather than the schema.
 */
it('sets the state to drawn on a ninth move that completes no line', function () {
    $game = submittingGame();
    submittingMoves($game, 0, 1, 2, 4, 3, 5, 7, 6);

    $result = (new SubmitMove)->handle(GameSnapshot::of($game), Mark::X, 8);

    $after = submittingRowOf($game->id);

    expect($result instanceof MoveAccepted ? $result->outcome : null)->toBe(Outcome::Drawn, 'the ninth Move did not report a draw')
        ->and($result instanceof MoveAccepted ? $result->sequenceIndex : null)->toBe(8, 'the ninth Move was not recorded at Sequence_Index 8')
        ->and($after['state'])->toBe(GameState::Drawn->value, 'a full board completing no line did not set the Game_State to drawn (Req 6.4)')
        ->and($after['winning_mark'])->toBeNull('a drawn Game recorded a winning Mark');
});

/*
 * A GAME STILL WAITING FOR AN OPPONENT (Req 4.5).
 *
 * This is also the first of the two guard-ordering assertions, and the reason
 * `game_not_started` cannot be folded into the turn guard: the Mark_To_Move on an
 * empty Move_List is `X`, so the Creator moving into their own waiting Game passes
 * every other guard and would reach the insert.
 *
 * "Leaves the Move_List unchanged" is asserted as no `moves` row existing AND as no
 * statement being issued at all, which is the stronger claim: a refusal that wrote
 * and rolled back would satisfy the first and fail the second.
 */
it('refuses a move into a game that is still waiting for an opponent, and issues no statement', function () {
    $game = submittingGame(GameState::WaitingForOpponent);

    $observed = GameSnapshot::of($game);
    $before = submittingRowOf($game->id);

    $outcome = null;
    $statements = submittingStatementsDuring(function () use ($observed, &$outcome) {
        $outcome = (new SubmitMove)->handle($observed, Mark::X, 4);
    });

    expect($observed->analysis->markToMove)->toBe(Mark::X, 'the Mark_To_Move is not X, so this does not test that the state guard precedes the turn guard')
        ->and($outcome)->toBe(MoveOutcome::GameNotStarted, 'a Move into a waiting Game was not refused as game_not_started (Req 4.5)')
        ->and($statements)->toBe([], 'the refusal issued a statement: '.implode(' | ', $statements))
        ->and(submittingMoveRowsOf($game->id))->toBe([], 'the refused Move was recorded (Req 4.5)')
        ->and(submittingRowOf($game->id))->toBe($before, 'the refused Move changed the Game row (Property 9)');
});

/*
 * A GAME IN A TERMINAL_STATE (Req 4.6).
 *
 * Both Terminal_States, and the guard reads the persisted Game_State as the design
 * specifies — so the fixtures carry a row whose `state` is terminal while its
 * Move_List is empty. That is not a realistic row, and it is the right fixture
 * precisely because it isolates what the guard consults: a guard reading
 * `$observed->analysis->isTerminal()` instead would see an in-progress empty list
 * and let the Move through.
 */
it('refuses a move into a terminal game, and issues no statement', function (GameState $state, ?Mark $winningMark) {
    $game = submittingGame($state, $winningMark);

    $observed = GameSnapshot::of($game);
    $before = submittingRowOf($game->id);

    $outcome = null;
    $statements = submittingStatementsDuring(function () use ($observed, &$outcome) {
        $outcome = (new SubmitMove)->handle($observed, Mark::X, 4);
    });

    expect($outcome)->toBe(MoveOutcome::GameEnded, 'a Move into a Terminal_State was not refused as game_ended (Req 4.6)')
        ->and($statements)->toBe([], 'the refusal issued a statement: '.implode(' | ', $statements))
        ->and(submittingMoveRowsOf($game->id))->toBe([], 'the refused Move was recorded (Req 4.6)')
        ->and(submittingRowOf($game->id))->toBe($before, 'the refused Move changed the Game row (Property 9)');
})->with([
    'won' => [GameState::Won, Mark::X],
    'drawn' => [GameState::Drawn, null],
]);

/*
 * THE PLAYER WHO IS NOT TO MOVE (Req 3.5).
 *
 * `O` attempting the first Move of a Game. The acting Mark is compared against the
 * derived Mark_To_Move and against nothing else, which is also the whole of
 * Requirement 3.6 as far as this class can express it: there is no payload in scope
 * for a `mark` field to be read from, so "ignored outright" is structural here and
 * is asserted through the HTTP surface at task 6.6.
 */
it('refuses a move from the player who is not to move, and issues no statement', function () {
    $game = submittingGame();

    $observed = GameSnapshot::of($game);
    $before = submittingRowOf($game->id);

    $outcome = null;
    $statements = submittingStatementsDuring(function () use ($observed, &$outcome) {
        $outcome = (new SubmitMove)->handle($observed, Mark::O, 4);
    });

    expect($outcome)->toBe(MoveOutcome::NotYourTurn, 'a Move by the Player who is not to move was not refused as not_your_turn (Req 3.5)')
        ->and($statements)->toBe([], 'the refusal issued a statement: '.implode(' | ', $statements))
        ->and(submittingMoveRowsOf($game->id))->toBe([], 'the refused Move was recorded')
        ->and(submittingRowOf($game->id))->toBe($before, 'the refused Move changed the Game row (Property 9)');
});

/*
 * A CELL THAT IS NOT AN INTEGER OR IS OUT OF RANGE (Req 4.4).
 *
 * One outcome for every shape, because the design keeps one vocabulary for one
 * condition: `'4'` is refused as surely as `'banana'`, which is what makes task
 * 6.2's obligation not to cast the payload load-bearing — a cast would turn
 * `'banana'` into `0`, a legal Cell, and record a Move in the top-left corner.
 *
 * The boundaries either side of the range are here (`-1` and `9`) rather than only
 * a distant value, since an off-by-one in the comparison is the mistake worth
 * catching.
 *
 * KNOWN LIMIT OF THE DIAGNOSIS, not of the coverage. Loosening the guard from
 * `is_int` to `is_numeric` does fail this test for the `4.0` and `4.5` cases, but it
 * fails as a `TypeError` raised deeper in — the value passes the guard and reaches
 * the private `commit(int $cellIndex)`, whose declared type rejects it — rather than
 * as the `invalid_move` expectation below with the message written for it. So the
 * failure is loud but the first line of it points at the wrong place. Left as it is
 * on purpose: the alternative is widening `commit()`'s parameter to `mixed` to get a
 * tidier failure message, which would remove a real type boundary in production code
 * to improve a diagnostic in a test.
 */
it('refuses a cell that is not an integer or is outside 0..8', function (mixed $cellIndex) {
    $game = submittingGame();

    $observed = GameSnapshot::of($game);
    $before = submittingRowOf($game->id);

    $outcome = null;
    $statements = submittingStatementsDuring(function () use ($observed, $cellIndex, &$outcome) {
        $outcome = (new SubmitMove)->handle($observed, Mark::X, $cellIndex);
    });

    expect($outcome)->toBe(MoveOutcome::InvalidMove, 'the Cell was not refused as invalid_move (Req 4.4)')
        ->and($statements)->toBe([], 'the refusal issued a statement: '.implode(' | ', $statements))
        ->and(submittingMoveRowsOf($game->id))->toBe([], 'the refused Move was recorded (Req 4.4)')
        ->and(submittingRowOf($game->id))->toBe($before, 'the refused Move changed the Game row (Property 9)');
})->with([
    'numeric string' => ['4'],
    'float' => [4.0],
    'float that is not whole' => [4.5],
    'boolean' => [true],
    'null' => [null],
    'array' => [[4]],
    'below the range' => [-1],
    'above the range' => [9],
    'far above the range' => [PHP_INT_MAX],
]);

/*
 * AN OCCUPIED CELL (Req 4.3).
 *
 * Occupancy is read from the observed Board and from nothing else. The Cell is one
 * the *opponent* holds, so the refusal cannot be explained by a Player's own Mark
 * being in the way.
 */
it('refuses a cell that is already occupied in the observed board', function () {
    $game = submittingGame();
    submittingMoves($game, 0);

    $observed = GameSnapshot::of($game);
    $before = submittingRowOf($game->id);

    $outcome = null;
    $statements = submittingStatementsDuring(function () use ($observed, &$outcome) {
        $outcome = (new SubmitMove)->handle($observed, Mark::O, 0);
    });

    expect($observed->analysis->board->occupantOf(0))->toBe(Mark::X, 'cell 0 is not occupied by the opponent, so this asserts nothing')
        ->and($outcome)->toBe(MoveOutcome::InvalidMove, 'an occupied Cell was not refused as invalid_move (Req 4.3)')
        ->and($statements)->toBe([], 'the refusal issued a statement: '.implode(' | ', $statements))
        ->and(submittingMoveRowsOf($game->id))->toBe([0 => 0], 'the refused Move changed the Move_List (Req 4.3)')
        ->and(submittingRowOf($game->id))->toBe($before, 'the refused Move changed the Game row (Property 9)');
});

/*
 * TURN OWNERSHIP IS CHECKED BEFORE CELL VALIDITY — the ordering the design records
 * as a deliberate choice.
 *
 * `X` attempts a Move when `O` is to move, targeting a Cell that is *also* invalid
 * — occupied, out of range, and not an integer in turn. Every case must answer
 * `not_your_turn`, because a Player who cannot act must not learn from the outcome
 * whether the Cell they picked was occupied.
 *
 * Swapping the two guards leaves every other test in this file passing, which is
 * why this one exists.
 */
it('reports not_your_turn rather than invalid_move when the waiting player targets a bad cell', function (mixed $cellIndex) {
    $game = submittingGame();
    submittingMoves($game, 0);

    $observed = GameSnapshot::of($game);

    expect($observed->analysis->markToMove)->toBe(Mark::O, 'O is not the Mark_To_Move, so this does not test the guard order')
        ->and((new SubmitMove)->handle($observed, Mark::X, $cellIndex))->toBe(
            MoveOutcome::NotYourTurn,
            'cell validity was evaluated before turn ownership, which leaks occupancy to the Player who cannot act',
        );
})->with([
    'occupied cell' => [0],
    'out of range' => [99],
    'not an integer' => ['banana'],
]);

/*
 * THE PURITY INVARIANT, ASSERTED FROM THE QUERY LOG — no `SELECT`, ever.
 *
 * This is the mechanical statement of task 6.1's invariant that a single call can
 * make. `SubmitMove` issues NO read at all, so the absence is total rather than
 * merely well placed: there is no ordering to reason about, only two statements on
 * the accepted path and none on any refusal.
 *
 * The absence is what matters and it is why this test is written against the log
 * rather than against behaviour. A `$game->refresh()` or a fresh
 * `GameSnapshot::of()` at the top of `handle()` changes NO outcome in any
 * single-request test — the re-read returns the state the snapshot already holds —
 * and it is exactly the edit that would retire the `conflict` path. This test sees
 * it; task 6.8 sees its consequence.
 *
 * ONE SQL-TEXT ASSERTION, NOT SIX. An earlier draft also matched `"state" = ?`,
 * `"winning_mark" = ?`, `"last_activity_at" = ?`, `cell_index` and `sequence_index`
 * in the two statements, and asserted the INSERT contained no `mark`. All of those
 * were removed. Each was already asserted BEHAVIOURALLY on the rows above — a
 * mutation dropping any of those writes fails an assertion about what the table
 * holds, which is the claim worth making — while the SQL-text form adds only a
 * coupling to SQLite's identifier quoting, so a driver change would redden the
 * suite without any behaviour changing. The `mark` one was worse than redundant: it
 * cannot fail unless the schema regains a column, which is `SchemaConstraintTest`'s
 * business and the migration's, not this file's.
 *
 * `version_counter + 1` is the exception and is why the query log is read for text
 * at all. Replacing `DB::raw('version_counter + 1')` with `$game->version_counter +
 * 1` yields 4 from an observed 3 and leaves EVERY row assertion in this file
 * passing — it was tried, and this one line is the only thing in the suite that
 * catches it. Requirement 4.7 is about the increment being the database's
 * arithmetic rather than a read-modify-write in PHP, and no assertion about the
 * resulting value can distinguish the two from a single request.
 *
 * `toContain()` is not used for the statement shapes: it is variadic over needles
 * and takes no message, so a message passed to it becomes a second needle and the
 * assertion passes unconditionally. A named boolean instead.
 */
it('issues no select at any point, and exactly one insert and one update for an accepted move', function () {
    $game = submittingGame();
    submittingMoves($game, 0, 3);

    $observed = GameSnapshot::of($game);

    $statements = submittingStatementsDuring(
        fn () => (new SubmitMove)->handle($observed, Mark::X, 4),
    );

    $trace = implode(' | ', $statements);

    $selects = array_values(array_filter($statements, static fn (string $sql): bool => str_starts_with($sql, 'select')));
    $inserts = array_values(array_filter($statements, static fn (string $sql): bool => str_starts_with($sql, 'insert')));
    $updates = array_values(array_filter($statements, static fn (string $sql): bool => str_starts_with($sql, 'update')));

    expect($statements)->toHaveCount(2, "an accepted Move issued something other than one INSERT and one UPDATE: {$trace}")
        ->and($selects)->toBe([], "SubmitMove re-read Game state, which is the invariant task 6.1 exists to keep: {$trace}")
        ->and($inserts)->toHaveCount(1, "the Move was not recorded by exactly one INSERT: {$trace}")
        ->and($updates)->toHaveCount(1, "the Game row was not transitioned by exactly one UPDATE: {$trace}")
        ->and($statements[0])->toStartWith('insert', "the UPDATE of the Game row preceded the INSERT of the Move: {$trace}");

    // Req 4.7. The one SQL-text claim in the file, and the only assertion anywhere
    // that separates "the database incremented the column" from "PHP computed the
    // successor of a value it had already read". See the note above for why the five
    // sibling fragment assertions were removed and this one was not.
    expect(str_contains($updates[0], 'version_counter + 1'))->toBeTrue(
        "the Version_Counter was not incremented by an expression the database evaluates (Req 4.7): {$trace}",
    );
});

/*
 * A UNIQUE VIOLATION ON EITHER INDEX IS `conflict` (Req 5.4).
 *
 * Both indexes mean the same thing — another Move landed after this request read
 * its snapshot — so both map to one outcome, and the mechanism is a caught
 * violation rather than a check beforehand. A check would be a read, and it would
 * race.
 *
 * The competing row is written directly, AFTER the snapshot is taken, which is what
 * makes each index reachable on its own:
 *
 *   - the sequence-index case puts the other Move at the very Sequence_Index this
 *     request will derive;
 *   - the cell-index case puts it at a Sequence_Index this request will not touch —
 *     the schema permits a gap, as `SchemaConstraintTest` establishes — leaving the
 *     Cell as the only thing that collides.
 *
 * Task 6.8 reaches the first of these the way production does, by calling the
 * subject twice from one snapshot, and asserts the Move_List went from n to n+1.
 * This test is the narrower claim that each index maps to the outcome, and that a
 * losing request leaves the Game row exactly as it found it — so the
 * Version_Counter did not move and neither did the state, which is the rollback
 * doing its work.
 */
it('maps a unique violation on either index to conflict and leaves the game row untouched', function (int $competingSequence, int $competingCell, int $attempt) {
    $game = submittingGame();
    submittingMoves($game, 0, 3);

    $observed = GameSnapshot::of($game);

    $competing = new Move;
    $competing->game_id = $game->id;
    $competing->cell_index = $competingCell;
    $competing->sequence_index = $competingSequence;
    $competing->save();

    $before = submittingRowOf($game->id);
    $rowsBefore = submittingMoveRowsOf($game->id);

    $outcome = (new SubmitMove)->handle($observed, Mark::X, $attempt);

    expect($outcome)->toBe(MoveOutcome::Conflict, 'a unique violation on `moves` was not reported as conflict (Req 5.4)')
        ->and(submittingMoveRowsOf($game->id))->toBe($rowsBefore, 'the losing request recorded a Move')
        ->and(submittingRowOf($game->id))->toBe($before, 'the losing request changed the Game row, so the transaction did not roll back (Property 9, Property 12)');
})->with([
    // Derived Sequence_Index is 2 either way; the observed list holds cells 0 and 3.
    'sequence index taken' => [2, 6, 4],
    'cell index taken at an untouched sequence index' => [7, 4, 4],
]);

/*
 * A CORRUPT MOVE_LIST IS AN EXCEPTION, NOT AN OUTCOME — and the transaction is what
 * makes it leave nothing behind.
 *
 * The fixture is a row that no path through `SubmitMove` can produce: five Moves in
 * which X has already completed the top row, on a Game still marked `active`. The
 * guards pass — the persisted state is not terminal, `O` is to move at length five,
 * and cell 5 is free — so the insert happens and the re-analysis then rejects the
 * appended list as a Move after a completed Winning_Line.
 *
 * TWO CLAIMS, AND THE SECOND IS THE ONE THAT PINS THE TRANSACTION. The first is
 * that this raises `CorruptMoveListException` rather than answering `invalid_move`,
 * which would report a corrupt row to the Player as "that Cell is not available"
 * and leave the corruption to be met again on the next request. The second is that
 * the `moves` table is unchanged afterwards: the insert has already run when the
 * exception is thrown, so only the rollback can make that true. Take the
 * transaction away and the row survives — the design's "no state change" for this
 * failure would be false, and Property 12's atomicity claim would have no guard.
 */
it('raises rather than answering invalid_move when the appended move list is corrupt, and rolls the insert back', function () {
    $game = submittingGame();
    submittingMoves($game, 0, 3, 1, 4, 2);

    $observed = GameSnapshot::of($game);
    $before = submittingRowOf($game->id);
    $rowsBefore = submittingMoveRowsOf($game->id);

    expect($observed->analysis->isTerminal())->toBeTrue('the observed Move_List is not already won, so the appended list would be well formed and this asserts nothing')
        ->and($observed->analysis->markToMove)->toBe(Mark::O, 'O is not the Mark_To_Move, so the turn guard would refuse before the corruption is reached')
        ->and(fn () => (new SubmitMove)->handle($observed, Mark::O, 5))
        ->toThrow(CorruptMoveListException::class);

    expect(submittingMoveRowsOf($game->id))->toBe($rowsBefore, 'the corrupt Move survived, so the insert and the transition are not one transaction')
        ->and(submittingMoveRowsOf($game->id))->toHaveCount(5)
        ->and(submittingRowOf($game->id))->toBe($before, 'the corruption path changed the Game row');
});

/*
 * THE OUTCOME VOCABULARY IS SPELLED AS THE DESIGN SPELLS IT.
 *
 * These five strings are what the design's outcome table names, what task 6.2
 * flashes, and what `lib/outcomes.ts` will key its messages on — so a rename here
 * is a silent loss of the client's message rather than a compile failure. Task
 * 12.1 asserts all eleven rejection outcomes of the application are pairwise
 * distinct (Property 16); these five are distinct from one another by being cases
 * of one enum.
 */
it('spells the five move rejection outcomes as the design does', function () {
    expect(array_map(static fn (MoveOutcome $case): string => $case->value, MoveOutcome::cases()))->toBe([
        'game_not_started',
        'game_ended',
        'not_your_turn',
        'invalid_move',
        'conflict',
    ], 'the move rejection vocabulary no longer matches the design outcome table');
});
