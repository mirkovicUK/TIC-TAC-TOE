<?php

declare(strict_types=1);

use App\Domain\TicTacToe\Mark;
use App\Games\GameState;
use App\Games\JoinCode;
use App\Games\MintedToken;
use App\Games\PlayerTokens;
use App\Games\SweepExpiredGames;
use App\Games\SweepReport;
use App\Models\ExpiryRecord;
use App\Models\Game;
use App\Models\Move;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\artisan;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

// Feature: remote-tic-tac-toe, Property 17: The sweep deletes exactly the eligible
// Games
//
// Validates: Requirements 13.1, 13.2, 13.3, 13.4, 13.5, 14.7
//
/*
 * Task 11.2 — `SweepExpiredGames`, and the `games:sweep` command Requirement 14.7
 * names.
 *
 * The clock is travelled rather than the fixtures aged, because every threshold here
 * is a comparison against `now()` taken once per run inside
 * `SweepExpiredGames::handle()`. Each test puts the clock at a fixed instant, seeds a
 * population whose timestamps are expressed as offsets from the instant the sweep
 * will run at, and moves the clock to that instant. Both eligibility thresholds are
 * INCLUSIVE (Req 13.1 and 13.2 fire *when* the elapsed time is reached) and the
 * 30-day purge is STRICT (Req 13.4 retains "at least 30 days"), so each of the three
 * has a fixture on the boundary itself.
 *
 * The survivor set is asserted as a whole sorted list of fixture labels rather than
 * one membership at a time, so a Game deleted that should not have been fails as
 * loudly as one missed. Labels rather than Game_Ids because a UUIDv7 in a failure
 * message says nothing about which fixture it was.
 *
 * Timestamps are assigned to the rows directly rather than played out through
 * `CreateGame` and `JoinGame`: `created_at` and `last_activity_at` are the whole of
 * eligibility, and no sequence of real requests produces a Game a week idle.
 *
 * The deferral of a parent whose Rematch survives is two tests of its own, over two
 * and three runs, because it is the one behaviour that needs the clock moved *between*
 * runs to show that a deferred Game is collected later rather than kept forever.
 *
 * `RefreshDatabase` over `DatabaseMigrations`, as everywhere in this directory:
 * `phpunit.xml` sets `DB_DATABASE=:memory:`, so a Feature test starts with no schema.
 * Its per-test transaction wraps the sweep's own — SQLite takes that as a savepoint —
 * and survives the deliberate constraint violation in `sweepDirectDeleteRefused()`,
 * which SQLite rolls back statement-wise.
 */

uses(RefreshDatabase::class);

/*
 * Every test here travels the clock. `Illuminate\Foundation\Testing\TestCase::tearDown()`
 * clears it already and this is a second clearing, for the reason `RateLimitTest`
 * carries one: a leaked test clock moves every later test in the suite and the failure
 * then appears somewhere else entirely.
 */
afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Puts the application clock at `$moment` and returns it.
 *
 * Whole seconds, because the three cutoffs are compared against `Y-m-d H:i:s` strings
 * in TEXT columns — sub-second precision is dropped on the way in, and a fixture meant
 * to sit exactly on a threshold would land on whichever side the truncation chose.
 *
 * `Carbon` is mutable here, so this returns the instance callers do arithmetic on;
 * every offset below is taken from a `copy()`.
 */
function sweepClockAt(Carbon|string $moment): Carbon
{
    $at = $moment instanceof Carbon ? $moment->copy() : Carbon::parse($moment)->startOfSecond();

    Carbon::setTestNow($at);

    return $at;
}

/**
 * A saved `games` row carrying exactly the two timestamps eligibility is computed
 * from, with an X Player_Token bound and an O Player_Token bound unless the Game is
 * still waiting for one.
 *
 * Assigning `created_at` is what keeps it: `Model::updateTimestamps()` writes that
 * column only when it is not already dirty, so a hand-assigned value survives the
 * insert and `updated_at` has to be assigned alongside it.
 *
 * `join_code` and `rematch_of_game_id` are decided together because
 * `CHECK (join_code IS NOT NULL OR rematch_of_game_id IS NOT NULL)` in
 * `database/migrations/2026_08_07_131347_create_games_table.php` requires one of them,
 * and a real Rematch carries the second and never the first.
 *
 * The tokens are parameters so a test that needs to PRESENT one gets the raw secret,
 * and every other test gets a row with populated slots and no secret to keep track of.
 * A `waiting_for_opponent` fixture leaves `o_token_hash` NULL, which is both what
 * Requirement 13.1 reads as "no Joiner has been assigned" and what the one-directional
 * CHECK on `games` permits in that state.
 */
function sweepGame(
    GameState $state,
    Carbon $createdAt,
    ?Carbon $lastActivityAt = null,
    ?string $rematchOf = null,
    ?MintedToken $x = null,
    ?MintedToken $o = null,
): Game {
    $tokens = new PlayerTokens;

    $game = new Game;
    $game->id = Str::uuid7()->toString();
    $game->join_code = $rematchOf === null ? JoinCode::generate()->stored : null;
    $game->state = $state;
    // The CHECK on `games` pairs a non-null `winning_mark` with `state = 'won'`.
    $game->winning_mark = $state === GameState::Won ? Mark::X : null;
    $game->version_counter = $state === GameState::WaitingForOpponent ? 0 : 1;
    $game->x_token_hash = ($x ?? $tokens->mint())->hash;
    $game->o_token_hash = $state === GameState::WaitingForOpponent ? null : ($o ?? $tokens->mint())->hash;
    $game->rematch_of_game_id = $rematchOf;
    $game->created_at = $createdAt;
    $game->updated_at = $createdAt;
    $game->last_activity_at = $lastActivityAt ?? $createdAt;
    $game->save();

    return $game;
}

/**
 * Records `$cells` as a contiguous Move_List from zero, so the `ON DELETE CASCADE` on
 * `moves.game_id` has rows to carry away.
 */
function sweepMoves(Game $game, int ...$cells): void
{
    foreach (array_values($cells) as $position => $cell) {
        $move = new Move;
        $move->game_id = $game->id;
        $move->cell_index = $cell;
        $move->sequence_index = $position;
        $move->save();
    }
}

/**
 * The labels of the Games still present, sorted — the survivor set as one value.
 *
 * A row belonging to no fixture is reported as its Game_Id rather than skipped, so a
 * population larger than the test seeded fails here.
 *
 * @param  array<string, Game>  $population
 * @return list<string>
 */
function sweepSurvivingLabels(array $population): array
{
    $labels = [];

    foreach ($population as $label => $game) {
        $labels[$game->id] = $label;
    }

    $surviving = [];

    foreach (DB::table('games')->get(['id']) as $row) {
        $id = (string) $row->id;
        $surviving[] = $labels[$id] ?? sprintf('(a game no fixture created: %s)', $id);
    }

    sort($surviving);

    return $surviving;
}

/**
 * The labels of `$population`, sorted — what `sweepSurvivingLabels()` returns before
 * anything has been deleted, and the non-vacuity guard every test takes before its
 * sweep.
 *
 * @param  array<string, Game>  $population
 * @return list<string>
 */
function sweepAllLabels(array $population): array
{
    $labels = array_keys($population);
    sort($labels);

    return $labels;
}

/**
 * Every `expiry_records` row as an array, keyed by Game_Id.
 *
 * `SELECT *` with no column list, so the keys of each row are the table's columns and
 * nothing else — which is what makes "the record holds the Game_Id and the deletion
 * time and nothing else" an assertion about the schema rather than about two fields
 * happening to be present.
 *
 * @return array<string, array<string, mixed>>
 */
function sweepExpiryRows(): array
{
    $rows = [];

    foreach (DB::table('expiry_records')->get() as $row) {
        $columns = (array) $row;
        $rows[(string) ($columns['game_id'] ?? '')] = $columns;
    }

    return $rows;
}

/**
 * The Game_Ids holding an Expiry_Record, sorted.
 *
 * @return list<string>
 */
function sweepExpiryIds(): array
{
    $ids = array_keys(sweepExpiryRows());
    sort($ids);

    return $ids;
}

/**
 * A tombstone written directly.
 *
 * The only way to hold a record older than the run under test: the sweep stamps every
 * record it writes with that run's own clock reading, so a 30-day-old record cannot be
 * produced by a sweep at the same instant as the one being asserted.
 *
 * `$gameId` is a legible string rather than a UUIDv7 because a record names a DELETED
 * Game and the table carries no foreign key — see the comment in
 * `database/migrations/2026_08_07_131500_create_expiry_records_table.php` — so the
 * value is free, and a failure message naming `purged-31-days` says what a UUID cannot.
 */
function sweepRecord(string $gameId, Carbon $deletedAt): void
{
    DB::table('expiry_records')->insert([
        'game_id' => $gameId,
        'deleted_at' => $deletedAt->toDateTimeString(),
    ]);
}

/**
 * How many `moves` rows the Game holds, read from the table rather than a relationship
 * so a cached collection cannot answer for it.
 */
function sweepMoveCount(string $gameId): int
{
    return DB::table('moves')->where('game_id', $gameId)->count();
}

/**
 * Whether the database refuses a bare delete of `$gameId`.
 *
 * The `ON DELETE RESTRICT` on `games.rematch_of_game_id` is what makes the deferral
 * load-bearing rather than decorative, and it is not otherwise visible from a passing
 * sweep: a test asserting only that a deferred parent survives would also pass against
 * a schema that allowed the parent to be deleted from under its Rematch.
 */
function sweepDirectDeleteRefused(string $gameId): bool
{
    try {
        DB::table('games')->where('id', $gameId)->delete();

        return false;
    } catch (QueryException) {
        return true;
    }
}

/**
 * One sweep, through the service.
 *
 * Resolved from the container rather than mocked or subclassed: the subject of every
 * assertion in this file is this class and the schema underneath it.
 */
function sweepRun(): SweepReport
{
    return app(SweepExpiredGames::class)->handle();
}

/**
 * `php artisan games:sweep`, as a pending command.
 *
 * `Pest\Laravel\artisan()` is declared `PendingCommand|int`: it returns the exit code
 * directly for a test that has turned the console output mock off, and there is then
 * no surface on which to assert the reported counts. The guard is what says so, rather
 * than leaving the callers below to fail on a call to a method of an integer.
 */
function sweepCommand(): PendingCommand
{
    $command = artisan('games:sweep');

    if (! $command instanceof PendingCommand) {
        throw new RuntimeException(sprintf(
            'games:sweep returned an exit code of %d rather than a pending command, so its reported counts cannot be asserted',
            $command,
        ));
    }

    return $command;
}

/**
 * Starts a fresh Player_Session holding exactly `$token` for `$game`, so the next
 * request presents that credential and no other.
 */
function sweepActAs(Game $game, MintedToken $token): void
{
    Session::flush();

    // 40 alphanumeric characters is what `Store::isValidId()` accepts; anything else
    // is silently replaced by a generated id, and a failed switch would then be
    // indistinguishable from a successful one.
    Session::setId(Str::random(40));
    Session::start();

    (new PlayerTokens)->remember($game->id, $token);
}

/**
 * The `game` prop of `GET /games/{game}` in the session in effect, or an empty array
 * when the response carries none — which the caller asserts against, so a missing
 * representation fails rather than making every claim about it vacuous.
 *
 * @return array<string, mixed>
 */
function sweepRepresentationOf(Game $game): array
{
    $page = AssertableInertia::fromTestResponse(get('/games/'.$game->id))->toArray();

    $props = is_array($page['props'] ?? null) ? $page['props'] : [];
    $representation = $props['game'] ?? null;

    return is_array($representation) ? $representation : [];
}

/**
 * The Cells of the `moves` prop, in the order the client receives them.
 *
 * `-1` stands in for anything that is not an integer, so a malformed entry fails a
 * comparison rather than being quietly skipped out of one.
 *
 * @return list<int>
 */
function sweepReportedCells(mixed $moves): array
{
    if (! is_array($moves)) {
        return [-1];
    }

    $cells = [];

    foreach ($moves as $move) {
        $cell = is_array($move) ? ($move['cell'] ?? null) : null;
        $cells[] = is_int($cell) ? $cell : -1;
    }

    return $cells;
}

/*
 * ─────────────────────────────────────────────────────────────────────────────
 * 1. THE SURVIVOR SET, THE TOMBSTONES AND THE CASCADE (Req 13.1, 13.2, 13.3).
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Seven Games spanning both thresholds, each with a fixture just under, exactly on,
 * and past it. Both thresholds are inclusive, so exactly-24-hours and exactly-7-days
 * are eligible and the minute short of either is not.
 *
 * The last fixture is what says the 24-hour branch is about a Game nothing has
 * happened to rather than about age: it was created 30 days ago and is still being
 * played, and it survives.
 */
it('deletes exactly the games past either threshold and leaves one expiry record for each', function () {
    $seededAt = sweepClockAt('2026-03-01 09:00:00');
    $sweepAt = $seededAt->copy()->addHour();
    $dayAgo = $sweepAt->copy()->subHours(24);
    $weekAgo = $sweepAt->copy()->subDays(7);

    $population = [
        'waiting, one minute short of 24 hours old' => sweepGame(GameState::WaitingForOpponent, $dayAgo->copy()->addMinute()),
        'waiting, exactly 24 hours old' => sweepGame(GameState::WaitingForOpponent, $dayAgo->copy()),
        'waiting, 25 hours old' => sweepGame(GameState::WaitingForOpponent, $dayAgo->copy()->subHour()),
        'active, one minute short of 7 days idle' => sweepGame(GameState::Active, $weekAgo->copy()->subDay(), $weekAgo->copy()->addMinute()),
        'active, exactly 7 days idle' => sweepGame(GameState::Active, $weekAgo->copy()->subDay(), $weekAgo->copy()),
        'won, 8 days idle' => sweepGame(GameState::Won, $weekAgo->copy()->subDays(2), $weekAgo->copy()->subDay()),
        'active, created 30 days ago and played an hour before the run' => sweepGame(GameState::Active, $sweepAt->copy()->subDays(30), $sweepAt->copy()->subHour()),
    ];

    $keptShortOfAWeek = $population['active, one minute short of 7 days idle'];
    $stillPlayed = $population['active, created 30 days ago and played an hour before the run'];
    $idleAWeek = $population['active, exactly 7 days idle'];
    $finished = $population['won, 8 days idle'];

    sweepMoves($keptShortOfAWeek, 0, 3, 1);
    sweepMoves($stillPlayed, 4, 8);
    sweepMoves($idleAWeek, 0, 1, 2, 3, 4);
    sweepMoves($finished, 0, 3, 1, 4, 2);

    // Without these three the survivor set below would be a claim about an empty
    // table, and the cascade a claim about rows that were never written.
    expect(sweepSurvivingLabels($population))->toBe(sweepAllLabels($population), 'the population was not seeded, so nothing below is a claim about a deletion')
        ->and(sweepMoveCount($idleAWeek->id))->toBe(5, 'the Game whose Move_List must be carried away by the cascade has no Moves')
        ->and(sweepMoveCount($finished->id))->toBe(5, 'the finished Game has no Moves, so its cascade cannot be observed')
        ->and(sweepExpiryIds())->toBe([], 'a tombstone existed before the run, so the records below are not this run\'s');

    sweepClockAt($sweepAt);

    $report = sweepRun();

    $expectedSurvivors = [
        'waiting, one minute short of 24 hours old',
        'active, one minute short of 7 days idle',
        'active, created 30 days ago and played an hour before the run',
    ];
    sort($expectedSurvivors);

    $expectedRecords = [
        $population['waiting, exactly 24 hours old']->id,
        $population['waiting, 25 hours old']->id,
        $idleAWeek->id,
        $finished->id,
    ];
    sort($expectedRecords);

    expect(sweepSurvivingLabels($population))->toBe($expectedSurvivors, 'the surviving Games are not exactly those short of both thresholds (Req 13.1, 13.2)')
        ->and($report->gamesDeleted)->toBe(4, 'the run did not report the four deletions it made')
        ->and($report->gamesDeferred)->toBe(0, 'a Game was deferred, but no Game in this population has a Rematch')
        ->and($report->recordsPurged)->toBe(0, 'a record was purged, but the only records here were written by this run')
        ->and(sweepExpiryIds())->toBe($expectedRecords, 'the Expiry_Records are not one per deleted Game and no more (Req 13.3)')
        // The cascade, both directions: gone for a deleted Game, untouched for a
        // survivor. Without the survivor half this would also pass against a sweep
        // that emptied the whole table.
        ->and(sweepMoveCount($idleAWeek->id))->toBe(0, 'Move rows remain for a deleted Game (Req 13.3)')
        ->and(sweepMoveCount($finished->id))->toBe(0, 'Move rows remain for a deleted Game (Req 13.3)')
        ->and(sweepMoveCount($keptShortOfAWeek->id))->toBe(3, 'the sweep took the Move_List of a Game it did not delete')
        ->and(sweepMoveCount($stillPlayed->id))->toBe(2, 'the sweep took the Move_List of a Game it did not delete')
        ->and(DB::table('moves')->count())->toBe(5, 'the `moves` table holds rows for no surviving Game');

    foreach (sweepExpiryRows() as $gameId => $row) {
        expect(array_keys($row))->toBe(
            ['game_id', 'deleted_at'],
            sprintf('the Expiry_Record for %s holds more than a Game_Id and a deletion time (Req 13.3, and the glossary entry for Expiry_Record)', $gameId),
        )
            ->and($row['deleted_at'])->toBe($sweepAt->toDateTimeString(), sprintf('the Expiry_Record for %s does not carry the time the Game was deleted', $gameId));
    }
});

/*
 * ─────────────────────────────────────────────────────────────────────────────
 * 2. THE PURGE BOUNDARY, WHICH IS STRICT (Req 13.4).
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * "At least 30 days" means a record exactly 30 days old is still within its retention.
 * The strict comparison is the whole of the distinction, so both sides are asserted: a
 * record on the boundary survives, and one a second past it does not.
 *
 * The Game deleted by this same run is here because its tombstone carries this run's
 * clock reading, and a purge that ran with the wrong sign would take it back out again.
 */
it('keeps an expiry record exactly 30 days old and purges everything older', function () {
    $sweepAt = sweepClockAt('2026-04-01 00:00:00');
    $boundary = $sweepAt->copy()->subDays(30);

    $expiring = sweepGame(GameState::Active, $sweepAt->copy()->subDays(20), $sweepAt->copy()->subDays(8));

    sweepRecord('kept-29-days', $boundary->copy()->addDay());
    sweepRecord('kept-exactly-30-days', $boundary->copy());
    sweepRecord('purged-one-second-past-30-days', $boundary->copy()->subSecond());
    sweepRecord('purged-31-days', $boundary->copy()->subDay());

    expect(sweepExpiryIds())->toBe(
        ['kept-29-days', 'kept-exactly-30-days', 'purged-31-days', 'purged-one-second-past-30-days'],
        'the four records were not seeded, so the boundary below is not being exercised',
    );

    $report = sweepRun();

    $expectedRecords = ['kept-29-days', 'kept-exactly-30-days', $expiring->id];
    sort($expectedRecords);

    expect(sweepExpiryIds())->toBe(
        $expectedRecords,
        'the 30-day purge boundary is not strict: a record exactly 30 days old is still within its retention and one a second older is not (Req 13.4)',
    )
        ->and($report->recordsPurged)->toBe(2, 'the run did not report the two records it purged')
        ->and($report->gamesDeleted)->toBe(1, 'the eligible Game was not deleted, so the tombstone asserted above is not one this run wrote');
});

/*
 * ─────────────────────────────────────────────────────────────────────────────
 * 3. AN ELIGIBLE GAME THE SWEEP HAS NOT REACHED IS AN ORDINARY GAME (Req 13.5).
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * The thresholds are lower bounds on retention, not deletion times, so nothing in the
 * read or write path may consult eligibility. A Move is driven through HTTP and the
 * representation read back, rather than the row merely asserted to still exist.
 *
 * The twin is the non-vacuity guard, and it is what makes this a claim about
 * eligibility rather than about a fresh Game: it carries the same two timestamps as
 * the subject and nothing else happens to it, so the sweep at the end deleting the
 * twin is the implementation's own answer that the subject was Eligible_For_Expiry at
 * the moment the Move was accepted. The subject survives that sweep because the
 * accepted Move moved `last_activity_at` to the clock reading the sweep runs at.
 */
it('lets a game that is eligible but not yet swept accept a move and return its ordinary representation', function () {
    $sweepAt = sweepClockAt('2026-05-01 12:00:00');
    $idleSince = $sweepAt->copy()->subDays(8);

    $tokens = new PlayerTokens;
    $x = $tokens->mint();
    $o = $tokens->mint();

    $subject = sweepGame(GameState::Active, $idleSince->copy()->subDay(), $idleSince->copy(), null, $x, $o);
    $twin = sweepGame(GameState::Active, $idleSince->copy()->subDay(), $idleSince->copy());

    sweepMoves($subject, 0, 4);
    sweepMoves($twin, 0, 4);

    $population = ['the game a move was submitted to' => $subject, 'its untouched twin' => $twin];

    sweepActAs($subject, $x);

    post('/games/'.$subject->id.'/moves', ['cell_index' => 8])
        ->assertStatus(303)
        ->assertRedirect(url('/games/'.$subject->id))
        ->assertSessionMissing('outcome')
        ->assertSessionHasNoErrors();

    $representation = sweepRepresentationOf($subject);
    $board = is_array($representation['board'] ?? null) ? $representation['board'] : [];

    expect($representation)->not->toBe([], 'a Game past the 7-day threshold returned no representation at all (Req 13.5)')
        ->and(sweepMoveCount($subject->id))->toBe(3, 'the Move was refused because the Game was eligible for expiry (Req 13.5)')
        ->and($representation['state'] ?? null)->toBe('active', 'a Game past the threshold is not reported in its current Game_State (Req 13.5)')
        ->and($representation['version'] ?? null)->toBe(2, 'the accepted Move did not increment the Version_Counter')
        ->and(sweepReportedCells($representation['moves'] ?? null))->toBe([0, 4, 8], 'the Move_List a Player receives is not the one the Game holds (Req 13.5)')
        ->and($board[8] ?? null)->toBe('x', 'the Cell the Move took is not held by the Player who took it')
        ->and($representation['markToMove'] ?? null)->toBe('o', 'the turn did not pass after the accepted Move')
        ->and($representation['yourMark'] ?? null)->toBe('x', 'the requesting session is not the X Player, so the claims above are about the wrong Mark')
        ->and($representation['winningMark'] ?? null)->toBeNull('an unfinished Game reported a winning Mark');

    // And the sweep's own verdict on the population the Move was accepted into.
    $report = sweepRun();

    expect(sweepSurvivingLabels($population))->toBe(
        ['the game a move was submitted to'],
        'the untouched twin was not deleted, so the Game the Move was accepted into was not Eligible_For_Expiry and the claims above say nothing',
    )
        ->and($report->gamesDeleted)->toBe(1, 'the run did not delete exactly the twin')
        ->and(sweepMoveCount($twin->id))->toBe(0, 'Move rows remain for the deleted twin (Req 13.3)')
        ->and(sweepMoveCount($subject->id))->toBe(3, 'the sweep took the Move_List of the Game it did not delete');
});

/*
 * ─────────────────────────────────────────────────────────────────────────────
 * 4. THE DEFERRAL, OVER TWO RUNS (Req 13.5, and the `games` schema paragraph on
 *    `ON DELETE RESTRICT`).
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * A Rematch carries `join_code = NULL`, so `rematch_of_game_id` is the only column
 * satisfying the reachability CHECK on its row: the back-reference cannot be cleared to
 * release the parent, and the parent cannot be deleted while it stands. An eligible
 * parent whose Rematch survives is therefore kept and collected on a later run.
 *
 * `gamesDeferred` is asserted because it is the only thing distinguishing a deferral
 * from a Game the sweep never found eligible — the row itself records neither.
 */
it('retains an eligible game whose rematch survives, then deletes both once the rematch is eligible too', function () {
    $firstRun = sweepClockAt('2026-06-01 12:00:00');

    $parent = sweepGame(GameState::Won, $firstRun->copy()->subDays(20), $firstRun->copy()->subDays(8));
    $rematch = sweepGame(GameState::Active, $firstRun->copy()->subDays(8), $firstRun->copy()->subHour(), $parent->id);

    sweepMoves($parent, 0, 3, 1, 4, 2);
    sweepMoves($rematch, 6);

    $population = ['the eligible parent' => $parent, 'its surviving rematch' => $rematch];

    expect(sweepSurvivingLabels($population))->toBe(sweepAllLabels($population), 'the parent and its Rematch were not seeded')
        // The reason the deferral exists. Without this the test would also pass
        // against a schema that let the parent be deleted from under its Rematch.
        ->and(sweepDirectDeleteRefused($parent->id))->toBeTrue('the database allowed the parent to be deleted while its Rematch referenced it, so the deferral below is not load-bearing')
        ->and(sweepSurvivingLabels($population))->toBe(sweepAllLabels($population), 'the refused delete removed the parent anyway');

    // ---- First run: the parent is eligible, its Rematch is not. ----
    $first = sweepRun();

    expect(sweepSurvivingLabels($population))->toBe(sweepAllLabels($population), 'an eligible parent was deleted while its Rematch survived, which the ON DELETE RESTRICT forbids')
        ->and($first->gamesDeleted)->toBe(0, 'the first run deleted something')
        ->and($first->gamesDeferred)->toBe(1, 'the parent was not reported as deferred, so it was never found eligible and the retention above says nothing')
        ->and(sweepExpiryIds())->toBe([], 'a deferred Game left a tombstone behind')
        ->and(sweepMoveCount($parent->id))->toBe(5, 'the deferred parent lost its Move_List');

    // ---- Second run, once the Rematch is past the threshold too. ----
    $secondRun = sweepClockAt($firstRun->copy()->addDays(7));

    $second = sweepRun();

    $expectedRecords = [$parent->id, $rematch->id];
    sort($expectedRecords);

    // That this run completes at all is the ordering claim: the reference is not
    // DEFERRABLE, so SQLite enforces it per row and a parent deleted before its
    // Rematch raises a constraint violation rather than failing an assertion.
    expect(sweepSurvivingLabels($population))->toBe([], 'the deferred parent was not collected once its Rematch became eligible, so a deferral is indefinite (Req 13.5)')
        ->and($second->gamesDeleted)->toBe(2, 'the second run did not delete both Games')
        ->and($second->gamesDeferred)->toBe(0, 'a Game was still deferred once every Game in the chain was eligible')
        ->and(sweepExpiryIds())->toBe($expectedRecords, 'the second run did not leave one Expiry_Record per deleted Game (Req 13.3)')
        ->and(sweepMoveCount($parent->id))->toBe(0, 'Move rows remain for the deleted parent (Req 13.3)')
        ->and(sweepMoveCount($rematch->id))->toBe(0, 'Move rows remain for the deleted Rematch (Req 13.3)')
        ->and(sweepExpiryRows()[$parent->id]['deleted_at'] ?? null)->toBe($secondRun->toDateTimeString(), 'the tombstone does not carry the time of the run that deleted the parent');
});

/*
 * A chain of three, which is what makes the deferral propagate *up* rather than apply
 * to one pair. Deferring a Game leaves its own Rematch pointer standing, so the Game
 * above it must be deferred too — three Games with only the oldest eligible therefore
 * delete nothing at all, rather than deleting the oldest and failing on the reference
 * from the middle.
 *
 * Three runs, so both intermediate states are pinned: only the oldest eligible, then
 * the oldest and the middle, then all three.
 */
it('defers a whole chain of three while any link survives, and clears it once every link is eligible', function () {
    $base = sweepClockAt('2026-07-01 12:00:00');

    $oldest = sweepGame(GameState::Won, $base->copy()->subDays(30), $base->copy()->subDays(8));
    $middle = sweepGame(GameState::Won, $base->copy()->subDays(8), $base->copy()->subDays(4), $oldest->id);
    $newest = sweepGame(GameState::Active, $base->copy()->subDays(4), $base->copy()->subHour(), $middle->id);

    $population = [
        'the oldest game of the chain' => $oldest,
        'its rematch' => $middle,
        'the rematch of that rematch' => $newest,
    ];

    expect(sweepSurvivingLabels($population))->toBe(sweepAllLabels($population), 'the chain of three was not seeded');

    // ---- Only the oldest is eligible. ----
    $first = sweepRun();

    expect(sweepSurvivingLabels($population))->toBe(sweepAllLabels($population), 'a Game of the chain was deleted while a later link survived')
        ->and($first->gamesDeleted)->toBe(0, 'the first run deleted part of the chain')
        ->and($first->gamesDeferred)->toBe(1, 'the oldest Game was not reported as deferred, so it was never found eligible');

    // ---- The middle is eligible too, and the newest still is not. ----
    sweepClockAt($base->copy()->addDays(4));

    $second = sweepRun();

    expect(sweepSurvivingLabels($population))->toBe(sweepAllLabels($population), 'the chain was collected while its last link was still being played')
        ->and($second->gamesDeleted)->toBe(0, 'the second run deleted part of the chain')
        ->and($second->gamesDeferred)->toBe(2, 'deferring the middle Game did not defer the Game above it as well');

    // ---- Every link is eligible. ----
    sweepClockAt($base->copy()->addDays(8));

    $third = sweepRun();

    $expectedRecords = [$oldest->id, $middle->id, $newest->id];
    sort($expectedRecords);

    expect(sweepSurvivingLabels($population))->toBe([], 'the chain was not collected once every link was eligible (Req 13.5)')
        ->and($third->gamesDeleted)->toBe(3, 'the third run did not delete all three Games')
        ->and($third->gamesDeferred)->toBe(0, 'a Game was still deferred with nothing left to defer for')
        ->and(sweepExpiryIds())->toBe($expectedRecords, 'the run did not leave one Expiry_Record per deleted Game (Req 13.3)');
});

/*
 * ─────────────────────────────────────────────────────────────────────────────
 * 5. THE COMMAND (Req 14.7, 13.3).
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Requirement 14.7 asks for a test of the COMMAND, not only of the service behind it,
 * so `games:sweep` is invoked through the console kernel and its three reported counts
 * and exit status are asserted. The population carries one of each: two Games deleted,
 * one deferred behind a surviving Rematch, one record past its retention.
 */
it('reports the counts and exits zero when games:sweep runs', function () {
    $sweepAt = sweepClockAt('2026-08-01 12:00:00');

    $population = [
        'a game nobody joined for 25 hours' => sweepGame(GameState::WaitingForOpponent, $sweepAt->copy()->subHours(25)),
        'a game finished 9 days ago' => sweepGame(GameState::Won, $sweepAt->copy()->subDays(20), $sweepAt->copy()->subDays(9)),
        'a deferred parent' => sweepGame(GameState::Won, $sweepAt->copy()->subDays(20), $sweepAt->copy()->subDays(8)),
    ];

    $population['its surviving rematch'] = sweepGame(
        GameState::Active,
        $sweepAt->copy()->subDays(8),
        $sweepAt->copy()->subHour(),
        $population['a deferred parent']->id,
    );

    sweepRecord('kept-10-days', $sweepAt->copy()->subDays(10));
    sweepRecord('purged-31-days', $sweepAt->copy()->subDays(31));

    expect(sweepSurvivingLabels($population))->toBe(sweepAllLabels($population), 'the population was not seeded, so the counts below would all be zero for the wrong reason')
        ->and(sweepExpiryIds())->toBe(['kept-10-days', 'purged-31-days'], 'the two records were not seeded');

    sweepCommand()
        ->expectsOutput('Games deleted: 2')
        ->expectsOutput('Games deferred (a rematch survives): 1')
        ->expectsOutput('Expiry records purged: 1')
        ->assertExitCode(0);

    $expectedRecords = [
        'kept-10-days',
        $population['a game nobody joined for 25 hours']->id,
        $population['a game finished 9 days ago']->id,
    ];
    sort($expectedRecords);

    $expectedSurvivors = ['a deferred parent', 'its surviving rematch'];
    sort($expectedSurvivors);

    expect(sweepSurvivingLabels($population))->toBe($expectedSurvivors, 'the command did not delete exactly the Games its own report claimed')
        ->and(sweepExpiryIds())->toBe($expectedRecords, 'the command did not leave one Expiry_Record per deleted Game and purge the one past its retention (Req 13.3, 13.4)');
});

/*
 * A quiet day is a success, not a failure: the thresholds are lower bounds on
 * retention, so a run finding nothing eligible is the ordinary case (Req 13.5). The
 * fresh Game is here so this is a run over a population rather than over an empty
 * table.
 */
it('exits zero and reports three zeroes when nothing is eligible', function () {
    $sweepAt = sweepClockAt('2026-09-01 12:00:00');

    $game = sweepGame(GameState::Active, $sweepAt->copy()->subDays(3), $sweepAt->copy()->subMinutes(5));

    sweepMoves($game, 0, 4);

    expect(sweepSurvivingLabels(['a game played five minutes ago' => $game]))->toBe(['a game played five minutes ago'], 'the Game was not seeded');

    sweepCommand()
        ->expectsOutput('Games deleted: 0')
        ->expectsOutput('Games deferred (a rematch survives): 0')
        ->expectsOutput('Expiry records purged: 0')
        ->assertExitCode(0);

    expect(sweepSurvivingLabels(['a game played five minutes ago' => $game]))->toBe(['a game played five minutes ago'], 'a run that reported no deletions deleted a Game')
        ->and(sweepMoveCount($game->id))->toBe(2, 'a run that reported no deletions took a Move_List')
        ->and(sweepExpiryIds())->toBe([], 'a run that deleted nothing wrote a tombstone');
});

/*
 * ─────────────────────────────────────────────────────────────────────────────
 * A TOMBSTONE SAVES THROUGH THE MODEL, so `$timestamps = false` holds.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * `expiry_records` has two columns, `game_id` and `deleted_at`, and no
 * `created_at`/`updated_at`. `ExpiryRecord` therefore sets `$timestamps = false`, and
 * with it on, an Eloquent save names columns the table does not have and the insert
 * fails.
 *
 * That setting was pinned nowhere in this file. `recordExpiry()` writes through
 * `ExpiryRecord::query()->insert()` and every fixture here through `DB::table()` —
 * both query-builder writes, neither of which consults `$timestamps`. Turning it on
 * left all seven tests above green. It failed in `GameResolverTest` and
 * `EntryRoutesTest` instead, which build tombstones with `new ExpiryRecord` for
 * convenience while testing routing and visibility.
 *
 * So the claim is asserted here, through the one write path that can observe it, in
 * the file that owns tombstones rather than two files that do not mention them in
 * their names.
 */
it('saves a tombstone through the model, which has no timestamp columns to write', function () {
    $record = new ExpiryRecord;
    $record->game_id = 'saved-through-eloquent';
    $record->deleted_at = sweepClockAt('2026-09-01 12:00:00');

    // The save is the assertion: with `$timestamps` on, Eloquent adds `created_at` and
    // `updated_at` to the INSERT and SQLite rejects the statement.
    $record->save();

    expect(ExpiryRecord::query()->whereKey('saved-through-eloquent')->exists())->toBeTrue('the tombstone was not persisted through the model')
        ->and($record->timestamps)->toBeFalse('ExpiryRecord has timestamps enabled, and the table has no columns for them')
        ->and(array_keys(DB::table('expiry_records')->where('game_id', 'saved-through-eloquent')->get()->map(fn (object $row): array => (array) $row)->first() ?? []))
        ->toBe(['game_id', 'deleted_at'], 'the persisted tombstone carries columns other than the two the table has');
});
