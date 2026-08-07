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
 * The mechanism half of `SubmitMove`: the four guards and their order, the absence
 * of any `SELECT`, the two statements of the accepted path, both unique indexes
 * mapping to `conflict`, and the corruption seam rolling its insert back.
 *
 * `RefreshDatabase` supplies the schema that `DB_DATABASE=:memory:` otherwise
 * leaves absent.
 *
 * Excluded, and where that ground lives instead: the win sweep across every line
 * and the rejection sweep through the HTTP surface, including a payload `mark`
 * field being ignored (Req 3.6), are `SubmitMoveTest`; two calls sharing ONE
 * snapshot with the Move_List asserted n → n+1 (Property 14) is `ConcurrencyTest`.
 * The conflict test below reaches the same `catch` by another route and does not
 * replace it.
 */

uses(RefreshDatabase::class);

/**
 * A saved Game in `$state`, with `last_activity_at` backdated so that an accepted
 * Move visibly moves it.
 *
 * The token hashes are arbitrary digests rather than issued credentials because
 * `SubmitMove` never reads them; the acting Mark arrives as a parameter. Attributes
 * are assigned one by one because mass assignment is closed on this model.
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
 * table rather than through the model so a stale in-memory instance cannot make an
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
 * `BEGIN`/`COMMIT` are issued through PDO and do not appear in the query log, so
 * this is exactly the statements the subject composed.
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
 * The accepted Move (Req 4.2, 4.7).
 *
 * The Version_Counter is asserted as `3 → 4` against the persisted column rather
 * than as "greater than before" because Req 4.7 is an increment of exactly one.
 */
it('appends the move at the observed sequence index and increments the version counter by exactly one', function () {
    $game = submittingGame();
    submittingMoves($game, 0, 3);

    $observed = GameSnapshot::of($game);
    $before = submittingRowOf($game->id);

    $result = (new SubmitMove)->handle($observed, Mark::X, 4);

    expect($result)->toBeInstanceOf(MoveAccepted::class, 'a legal Move by the Player to move was not accepted');

    // Narrows the type for the analyser; the expectation above is what actually
    // fails if the Move was refused.
    if (! $result instanceof MoveAccepted) {
        throw new RuntimeException('the Move was refused, so the assertions below would say nothing');
    }

    $after = submittingRowOf($game->id);

    expect($result->sequenceIndex)->toBe(2, 'the Move was not recorded at the length of the Move_List before acceptance (Req 4.2)')
        ->and($result->cellIndex)->toBe(4, 'the reported Cell is not the Cell that was attempted')
        // This does not show the Mark came from the acting parameter: the turn
        // guard has already established `$actingMark === markToMove`, so `mark:
        // $actingMark` and `mark: Mark::forSequenceIndex($sequenceIndex)` are equal
        // here and no assertion in this file separates them. Provenance is
        // observable only where a payload can carry a competing `mark` (Req 3.6) —
        // `SubmitMoveTest`, through the HTTP surface.
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
 * The win transition (Req 6.2). X holds cells 0 and 1 and takes 2. The sweep across
 * all eight lines and the double diagonal is `SubmitMoveTest`.
 *
 * Limit of the two column assertions: the CHECK on `games` pairs them — `(state =
 * 'won' AND winning_mark IS NOT NULL) OR (state <> 'won' AND winning_mark IS
 * NULL)` — so writing NULL to `winning_mark`, or `active` alongside a winner, dies
 * on a `QueryException` before either expectation is evaluated. The claim this test
 * owns is the value: writing the *losing* Mark satisfies the CHECK and reaches the
 * expectation, which is why it is `toBe(Mark::X->value)` rather than "not null".
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
 * The draw (Req 6.4). X on 0, 2, 3, 7, 8 and O on 1, 4, 5, 6 — a full board
 * completing no line. As above, the CHECK on `games` reaches a non-NULL
 * `winning_mark` before the expectation does.
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
 * A Game still waiting for an opponent (Req 4.5).
 *
 * `game_not_started` cannot be folded into the turn guard: the Mark_To_Move on an
 * empty Move_List is `X`, so the Creator moving into their own waiting Game passes
 * every other guard and would reach the insert. The `markToMove` expectation guards
 * that — without it a Mark_To_Move of `O` would make this pass on the turn guard.
 *
 * Asserting no statement at all is stronger than asserting no `moves` row: a
 * refusal that wrote and rolled back would satisfy the row claim.
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
 * A Game in a Terminal_State (Req 4.6).
 *
 * The fixtures deliberately carry an unrealistic row — terminal `state`, empty
 * Move_List — to isolate what the guard consults. A guard reading
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
 * The Player who is not to move (Req 3.5): `O` attempting the first Move of a Game.
 * Req 3.6's "a payload `mark` is ignored" needs a payload and lives in
 * `SubmitMoveTest`.
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
 * A Cell that is not an integer or is out of range (Req 4.4).
 *
 * `'4'` must be refused as surely as `'banana'`, which is what makes the
 * controller's obligation not to cast the payload load-bearing — a cast would turn
 * `'banana'` into `0`, a legal Cell.
 *
 * Limit of the diagnosis, not of the coverage: loosening `is_int` to `is_numeric`
 * fails the `4.0` and `4.5` cases as a `TypeError` from the private `commit(int
 * $cellIndex)` rather than as the expectation below, so the first line of the
 * failure points at the wrong place. Widening `commit()` to `mixed` would tidy the
 * message by removing a real type boundary in production code.
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
 * An occupied Cell (Req 4.3). The Cell is one the *opponent* holds, so the refusal
 * cannot be explained by a Player's own Mark being in the way.
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
 * Turn ownership is checked before Cell validity, so a Player who cannot act does
 * not learn whether the Cell they picked was occupied. Swapping the two guards
 * leaves every other test in this file passing, which is why this one exists.
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
 * The purity invariant, asserted from the query log: no `SELECT`, ever.
 *
 * Written against the log rather than against behaviour because a `$game->refresh()`
 * or a fresh `GameSnapshot::of()` at the top of `handle()` changes NO outcome in any
 * single-request test — the re-read returns the state the snapshot already holds —
 * while retiring the `conflict` path. `ConcurrencyTest` sees the consequence.
 *
 * Only one SQL-text assertion. Everything else the statements write is asserted
 * behaviourally on the rows above; matching identifiers in SQL text would only
 * couple the suite to SQLite's quoting. The exception is `version_counter + 1`:
 * replacing `DB::raw('version_counter + 1')` with `$game->version_counter + 1`
 * yields 4 from an observed 3 and leaves every row assertion in this file passing,
 * so this line is the only thing in the suite that catches it (Req 4.7).
 *
 * `toContain()` is avoided for the statement shapes: it is variadic over needles and
 * takes no message, so a message passed to it becomes a second needle and the
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

    // Req 4.7. Separates "the database incremented the column" from "PHP computed
    // the successor of a value it had already read".
    expect(str_contains($updates[0], 'version_counter + 1'))->toBeTrue(
        "the Version_Counter was not incremented by an expression the database evaluates (Req 4.7): {$trace}",
    );
});

/*
 * A unique violation on either index is `conflict` (Req 5.4). The mechanism is a
 * caught violation rather than a check beforehand, since a check would be a read and
 * would race.
 *
 * The competing row is written after the snapshot is taken, which is what makes each
 * index reachable on its own: the sequence-index case takes the very Sequence_Index
 * this request will derive, and the cell-index case sits at a Sequence_Index this
 * request will not touch — the schema permits the gap, per `SchemaConstraintTest` —
 * leaving the Cell as the only collision.
 *
 * `ConcurrencyTest` reaches the first of these the way production does. This is the
 * narrower claim that each index maps to the outcome and that the rollback leaves
 * the Game row as it found it.
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
 * A corrupt Move_List raises rather than answering an outcome, and the transaction
 * is what makes it leave nothing behind.
 *
 * The fixture is a row no path through `SubmitMove` can produce: five Moves in which
 * X has already completed the top row, on a Game still marked `active`. All four
 * guards pass, so the insert happens and the re-analysis then rejects the appended
 * list. The two expectations before the `toThrow` guard that: a non-terminal
 * observed list or a Mark_To_Move of X would refuse earlier and never reach the
 * corruption.
 *
 * Answering `invalid_move` instead would report a corrupt row to the Player as "that
 * Cell is not available". The row assertions afterwards are the transaction's claim:
 * the insert has already run when the exception is thrown, so only the rollback can
 * make them true (Property 12).
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
 * The outcome vocabulary is spelled as the design spells it. These five strings are
 * what the controller flashes and what `lib/outcomes.ts` keys its messages on, so a
 * rename here is a silent loss of the client's message rather than a compile
 * failure. Pairwise distinctness across all eleven rejection outcomes of the
 * application (Property 16) lives elsewhere.
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
