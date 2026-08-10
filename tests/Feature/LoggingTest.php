<?php

declare(strict_types=1);

use App\Domain\TicTacToe\Mark;
use App\Games\GameEventLogger;
use App\Games\GameState;
use App\Games\JoinCode;
use App\Games\MintedToken;
use App\Games\MoveOutcome;
use App\Games\PlayerTokens;
use App\Models\Game;
use App\Models\Move;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Logger as LaravelLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Monolog\Handler\StreamHandler;
use Monolog\Logger as MonologLogger;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

// Feature: remote-tic-tac-toe, Property 19: Log records carry the required fields
// and no secrets
//
// Validates: Requirements 10.3, 10.4, 10.5
//
/*
 * `GameEventLogger`, the `game_events` channel and `RedactSecrets`, asserted against
 * the bytes the channel actually wrote.
 *
 * How the records are read back. `phpunit.xml` points the channel at `php://memory`,
 * and such a stream is private to the `fopen` that created it, so it cannot be opened
 * a second time from here. `Monolog\Handler\StreamHandler::getStream()` hands back the
 * handler's own resource, which is rewound and read below. Nothing is substituted for
 * the channel: the lines were formatted by the configured `JsonFormatter` and passed
 * through the configured `RedactSecrets` processor on the way in, and both are part of
 * what Property 19 is about. `Log::shouldReceive()` or a fake channel would retire
 * them.
 *
 * A consequence of that shape: `JsonFormatter` writes one JSON object per line whose
 * `context` holds the fields the design tabulates, so every field assertion below is
 * against `context` and not against the top level.
 *
 * Fixtures are seeded as rows rather than played out through HTTP wherever a count is
 * being asserted, because a seeded row emits nothing — leaving the count of records a
 * statement about the one action under test.
 *
 * `game.invariant_violation` is asserted separately from the six and is deliberately
 * not folded into their count: no requirement asks for it, only the design's failure
 * table, and it reports corruption rather than a Game lifecycle event.
 */

uses(RefreshDatabase::class);

/**
 * The channel's stream handler.
 *
 * Reached through the `Log` facade so it is the same channel instance
 * `GameEventLogger` writes to; `LogManager` memoises channels, and the application is
 * rebuilt per test, so each test reads its own records and nobody else's.
 */
function loggingHandler(): ?StreamHandler
{
    $channel = Log::channel(GameEventLogger::CHANNEL);

    if (! $channel instanceof LaravelLogger) {
        return null;
    }

    $monolog = $channel->getLogger();

    if (! $monolog instanceof MonologLogger) {
        return null;
    }

    foreach ($monolog->getHandlers() as $handler) {
        if ($handler instanceof StreamHandler) {
            return $handler;
        }
    }

    return null;
}

/**
 * Every byte the channel has written, read off the handler's own stream.
 *
 * The stream is null until the first write, which is why an absence of records reads
 * as an empty string rather than an error: several tests below assert exactly that.
 */
function loggingOutput(): string
{
    $stream = loggingHandler()?->getStream();

    if (! is_resource($stream)) {
        return '';
    }

    // Reading from offset 0 leaves the pointer at the end, so later writes still
    // append and the output may be read repeatedly within one test.
    return (string) stream_get_contents($stream, -1, 0);
}

/**
 * The emitted records, decoded, in the order they were written.
 *
 * A line that is not a JSON object is kept as a record naming itself, so a formatter
 * change that breaks the output fails an assertion instead of shrinking the count.
 *
 * @return list<array<string, mixed>>
 */
function loggingRecords(): array
{
    $records = [];

    foreach (preg_split('/\R/', loggingOutput()) ?: [] as $line) {
        if (trim($line) === '') {
            continue;
        }

        $decoded = json_decode($line, true);

        $records[] = is_array($decoded)
            ? $decoded
            : ['context' => ['event' => '(a line that is not a JSON object)', 'line' => $line]];
    }

    return $records;
}

/**
 * The `context` of every emitted record — where `GameEventLogger` puts the fields the
 * design's table names.
 *
 * @return list<array<string, mixed>>
 */
function loggingContexts(): array
{
    $contexts = [];

    foreach (loggingRecords() as $record) {
        $context = $record['context'] ?? null;
        $contexts[] = is_array($context) ? $context : ['event' => '(a record with no context)'];
    }

    return $contexts;
}

/**
 * The event name of every emitted record, in order — the value every count below is
 * taken over, and what a failure message reports.
 *
 * @return list<string>
 */
function loggingEventNames(): array
{
    return array_map(
        static function (array $context): string {
            $event = $context['event'] ?? null;

            return is_string($event) ? $event : '(a record with no event field)';
        },
        loggingContexts(),
    );
}

/**
 * The contexts of the records naming `$event`.
 *
 * @return list<array<string, mixed>>
 */
function loggingRecordsOf(string $event): array
{
    return array_values(array_filter(
        loggingContexts(),
        static fn (array $context): bool => ($context['event'] ?? null) === $event,
    ));
}

/**
 * The one record for `$event`, or a failure naming every event that was emitted.
 *
 * Exactly one, never at-least-one: two records for one occurrence is the failure
 * Requirement 10.3 rules out, and it is invisible to a filter that takes the first
 * match.
 *
 * @return array<string, mixed>
 */
function loggingSoleRecordOf(string $event): array
{
    $records = loggingRecordsOf($event);

    expect($records)->toHaveCount(1, sprintf(
        '%d records name %s; Requirement 10.3 asks for exactly one per event. The events emitted were: %s',
        count($records),
        $event,
        implode(', ', loggingEventNames()) ?: '(none)',
    ));

    return $records[0] ?? [];
}

/**
 * The three fields Requirement 10.4 demands of every record.
 *
 * The timestamp is asserted by shape rather than by value, since the criterion asks
 * for a timestamp and the format is `GameEventLogger`'s own.
 *
 * @param  array<string, mixed>  $context
 */
function loggingAssertCommonFields(array $context, string $event, string $gameId): void
{
    $timestamp = $context['timestamp'] ?? null;

    expect($context['event'] ?? null)->toBe($event, 'the record does not carry the event name (Req 10.4)')
        ->and($context['game_id'] ?? null)->toBe($gameId, 'the record does not carry the Game_Id of the Game it describes (Req 10.4)')
        ->and(is_string($timestamp) ? $timestamp : '')->toMatch(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            'the record does not carry a timestamp (Req 10.4)',
        );
}

/**
 * Suspends the Player_Session in effect and resumes another, so the callers below are
 * distinct Players rather than one Player acting twice.
 *
 * The outgoing payload is written through the handler first because `Store::start()`
 * MERGES what the handler holds for the incoming id into the attributes already in
 * memory rather than replacing them, so a switch that only changed the id would carry
 * the outgoing session's `player_tokens.*` key into the incoming one. Same mechanism
 * as `rematchSwitchSession()`.
 *
 * `StartSession` re-reads the id from a session cookie the test client does not send,
 * so every request replaces it with a fresh one and the id to resume by is the id in
 * effect AFTER that session's last request.
 *
 * @param  string|null  $id  An existing session id to resume, or null for a new one.
 * @return string The id now in effect.
 */
function loggingSwitchSession(?string $id = null): string
{
    Session::save();
    Session::flush();

    // 40 alphanumeric characters is what `Store::isValidId()` accepts; anything else
    // is silently replaced by a generated id, and a failed switch would then look
    // like a successful one.
    Session::setId($id ?? Str::random(40));
    Session::start();

    return Session::getId();
}

/**
 * A saved Game in `$state` with `$cells` recorded contiguously from zero, both
 * Player_Tokens bound to it, and nothing in any session.
 *
 * Nothing here goes through `CreateGame` or `JoinGame`, so the fixture emits no
 * records and every count below belongs to the action under test. `version_counter`
 * is the join plus one per Move, as a real Game would carry.
 *
 * @param  list<int>  $cells
 * @return array{game: Game, tokens: array{x: MintedToken, o: MintedToken}}
 */
function loggingFixture(GameState $state = GameState::Active, array $cells = []): array
{
    $tokens = new PlayerTokens;
    $x = $tokens->mint();
    $o = $tokens->mint();

    $game = new Game;
    $game->id = Str::uuid7()->toString();
    $game->join_code = JoinCode::generate()->stored;
    $game->state = $state;
    // The CHECK on `games` pairs a non-null `winning_mark` with `state = 'won'`.
    $game->winning_mark = $state === GameState::Won ? Mark::X : null;
    $game->version_counter = $state === GameState::WaitingForOpponent ? 0 : 1 + count($cells);
    $game->x_token_hash = $x->hash;
    // And the one-directional CHECK forbids an occupied O slot while a Game waits for
    // an opponent, which is also what that state means (Req 2.1) — so a fixture in
    // that state leaves the O token unbound and `$o` unused.
    $game->o_token_hash = $state === GameState::WaitingForOpponent ? null : $o->hash;
    $game->last_activity_at = now()->subMinutes(5);
    $game->save();

    foreach ($cells as $position => $cell) {
        $move = new Move;
        $move->game_id = $game->id;
        $move->cell_index = $cell;
        $move->sequence_index = $position;
        $move->save();
    }

    return ['game' => $game, 'tokens' => ['x' => $x, 'o' => $o]];
}

/**
 * Starts a fresh Player_Session holding exactly the Player_Token bound to `$mark` on
 * the fixture's Game.
 *
 * @param  array{game: Game, tokens: array{x: MintedToken, o: MintedToken}}  $fixture
 */
function loggingActAs(array $fixture, Mark $mark): void
{
    loggingSwitchSession();

    (new PlayerTokens)->remember($fixture['game']->id, $fixture['tokens'][$mark->value]);
}

/*
 * The precondition every assertion in this file rests on: the channel writes to a
 * stream these tests can read, and it is the configured channel rather than a
 * substitute.
 *
 * Without this, a channel pointed at `php://stderr` would make every "no secret
 * appears in the output" assertion pass against an empty string.
 */
beforeEach(function () {
    $handler = loggingHandler();

    expect($handler)->not->toBeNull('the game_events channel has no StreamHandler, so no record written below can be read back')
        ->and($handler?->getUrl())->toBe(
            'php://memory',
            'the game_events channel is not pointed at a readable in-process stream (LOG_GAME_EVENTS_STREAM in phpunit.xml), so every assertion in this file would run against no output at all',
        )
        ->and(loggingOutput())->toBe('', 'the channel had already written before the test began, so the counts below are not this test\'s');
});

/*
 * ─────────────────────────────────────────────────────────────────────────────
 * 1. ONE RECORD PER EVENT, FOR EACH OF REQUIREMENT 10.3's SIX EVENTS.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Every case asserts the whole list of events emitted, not only the count of the one
 * under test, so a second record for some other event fails here rather than being
 * filtered away.
 */
it('emits exactly one game.created record when a game is created', function () {
    post('/games')->assertStatus(303);

    $game = Game::query()->sole();

    loggingAssertCommonFields(loggingSoleRecordOf('game.created'), 'game.created', $game->id);

    expect(loggingEventNames())->toBe(['game.created'], 'creating a Game emitted records for events that did not happen');
});

it('emits exactly one game.joined record when a player claims the second slot', function () {
    $game = loggingFixture(GameState::WaitingForOpponent)['game'];

    loggingSwitchSession();

    post('/join', ['join_code' => $game->join_code])
        ->assertStatus(303)
        ->assertRedirect(url('/games/'.$game->id))
        ->assertSessionMissing('outcome');

    loggingAssertCommonFields(loggingSoleRecordOf('game.joined'), 'game.joined', $game->id);

    expect(loggingEventNames())->toBe(['game.joined'], 'the join emitted records for events that did not happen');
});

/*
 * Req 10.4's move fields, on an acceptance. The Sequence_Index is the position the
 * Move took, so it must be the observed length rather than a count taken after the
 * insert.
 *
 * `game.finished` is asserted absent: the Game is still in progress, so a record of a
 * Terminal_State transition here would be a second event reported for one.
 */
it('emits exactly one move.accepted record carrying the mark, cell, sequence index and outcome', function () {
    $fixture = loggingFixture(GameState::Active, [0, 4]);
    $game = $fixture['game'];

    loggingActAs($fixture, Mark::X);

    post('/games/'.$game->id.'/moves', ['cell_index' => 8])
        ->assertStatus(303)
        ->assertSessionMissing('outcome');

    $record = loggingSoleRecordOf('move.accepted');

    loggingAssertCommonFields($record, 'move.accepted', $game->id);

    expect($record['mark'] ?? null)->toBe('x', 'the record does not carry the acting Mark (Req 10.4)')
        ->and($record['cell_index'] ?? null)->toBe(8, 'the record does not carry the Cell_Index (Req 10.4)')
        ->and($record['sequence_index'] ?? null)->toBe(2, 'the record does not carry the Sequence_Index the Move took (Req 10.4)')
        ->and($record['outcome'] ?? null)->toBe('accepted', 'the record does not carry the acceptance outcome (Req 10.4)')
        ->and(loggingEventNames())->toBe(['move.accepted'], 'an accepted Move in a Game still in progress emitted more than its own record');
});

/*
 * Req 10.4 for a refused Move, and the two fields that distinguish the record from an
 * acceptance.
 *
 * The acting Mark is the Mark bound to the presented Player_Token — `x` here — and
 * NOT the Mark_To_Move, which is `o`. That is the only case in which the two differ,
 * so it is the only case that says which one the record carries.
 */
it('emits exactly one move.rejected record naming the rejection outcome and the acting mark', function () {
    $fixture = loggingFixture(GameState::Active, [0]);
    $game = $fixture['game'];

    loggingActAs($fixture, Mark::X);

    post('/games/'.$game->id.'/moves', ['cell_index' => 3])
        ->assertStatus(303)
        ->assertSessionHas('outcome', MoveOutcome::NotYourTurn->value);

    $record = loggingSoleRecordOf('move.rejected');

    loggingAssertCommonFields($record, 'move.rejected', $game->id);

    expect($record['mark'] ?? null)->toBe('x', 'the record carries the Mark_To_Move rather than the acting Mark of Requirement 10.4')
        ->and($record['cell_index'] ?? null)->toBe(3, 'the record does not carry the Cell_Index the request named (Req 10.4)')
        ->and($record['sequence_index'] ?? null)->toBe(1, 'the record does not carry the Sequence_Index the Move would have taken (Req 10.4)')
        ->and($record['outcome'] ?? null)->toBe(MoveOutcome::NotYourTurn->value, 'the record does not carry the rejection outcome (Req 10.4)')
        ->and(DB::table('moves')->where('game_id', $game->id)->count())->toBe(1, 'the Move was accepted, so the record above is not about a rejection')
        ->and(loggingEventNames())->toBe(['move.rejected'], 'a refused Move emitted more than its own record');
});

/*
 * A Cell that was not an integer still produces the four move fields (Req 10.4), with
 * `cell_index` as the JSON-encoded raw value the design's logging section describes.
 * The quotes are what distinguish the string `"8"` from the Cell `8`.
 */
it('logs a cell index that was not an integer as its json encoding', function () {
    $fixture = loggingFixture(GameState::Active, [0, 4]);
    $game = $fixture['game'];

    loggingActAs($fixture, Mark::X);

    post('/games/'.$game->id.'/moves', ['cell_index' => 'banana'])
        ->assertStatus(303)
        ->assertSessionHas('outcome', MoveOutcome::InvalidMove->value);

    $record = loggingSoleRecordOf('move.rejected');

    expect($record['cell_index'] ?? null)->toBe('"banana"', 'a non-integer Cell_Index did not reach the record as its JSON encoding')
        ->and($record['outcome'] ?? null)->toBe(MoveOutcome::InvalidMove->value)
        ->and($record['sequence_index'] ?? null)->toBe(2);
});

/*
 * An oversized Cell is truncated, so one request cannot write an unbounded line into
 * the log stream.
 *
 * `describeCellIndex()` cuts the encoding at `CELL_INDEX_LIMIT` and appends `...`. That
 * branch had no test: the case above encodes to eight characters and nothing else in
 * the suite sends a longer value, so raising the limit to 65536 — disabling truncation
 * — left the whole suite green.
 *
 * The length is asserted exactly rather than as "shorter than the input". A cut at the
 * wrong offset, or a marker that is not appended, is the failure this pins, and both
 * would satisfy an inequality. 64 kept characters plus three for the marker is 67; the
 * kept characters are the opening quote and 63 of the payload.
 */
it('truncates an oversized cell index and marks it as truncated', function () {
    $fixture = loggingFixture(GameState::Active, [0, 4]);
    $game = $fixture['game'];

    loggingActAs($fixture, Mark::X);

    // Well past the limit, and a single repeated character so the encoding needs no
    // escaping and its length is the payload's length plus the two quotes.
    $oversized = str_repeat('a', 200);

    post('/games/'.$game->id.'/moves', ['cell_index' => $oversized])
        ->assertStatus(303)
        ->assertSessionHas('outcome', MoveOutcome::InvalidMove->value);

    $recorded = loggingSoleRecordOf('move.rejected')['cell_index'] ?? null;

    expect($recorded)->toBeString('the oversized Cell_Index did not reach the record')
        ->and(strlen((string) $recorded))->toBe(67, 'the truncated Cell_Index is not 64 kept characters plus the three-character marker')
        ->and(str_ends_with((string) $recorded, '...'))->toBeTrue("the truncated Cell_Index carries no truncation marker: {$recorded}")
        ->and($recorded)->toBe('"'.str_repeat('a', 63).'...', 'the truncation did not keep the leading 64 characters of the encoding');
});

/*
 * The Terminal_State transition is a second event and not a field of the first (Req
 * 10.3), so a winning Move emits both records. The order is asserted because the
 * Move is what caused the transition.
 */
it('emits both a move.accepted and a game.finished record for a winning move', function () {
    $fixture = loggingFixture(GameState::Active, [0, 3, 1, 4]);
    $game = $fixture['game'];

    loggingActAs($fixture, Mark::X);

    post('/games/'.$game->id.'/moves', ['cell_index' => 2])
        ->assertStatus(303)
        ->assertSessionMissing('outcome');

    $accepted = loggingSoleRecordOf('move.accepted');
    $finished = loggingSoleRecordOf('game.finished');

    loggingAssertCommonFields($accepted, 'move.accepted', $game->id);
    loggingAssertCommonFields($finished, 'game.finished', $game->id);

    expect(loggingEventNames())->toBe(
        ['move.accepted', 'game.finished'],
        'a winning Move did not emit exactly the two records Requirement 10.3 asks for, in the order the Move caused them',
    )
        ->and($accepted['cell_index'] ?? null)->toBe(2)
        ->and($accepted['sequence_index'] ?? null)->toBe(4)
        ->and($finished['result'] ?? null)->toBe('won_by_x', 'the game.finished record does not report the result')
        ->and($finished['winning_mark'] ?? null)->toBe('x', 'the game.finished record does not report the winning Mark')
        ->and(DB::table('games')->where('id', $game->id)->value('state'))->toBe(GameState::Won->value, 'the Game did not finish, so the record above describes an event that did not happen');
});

/*
 * A draw carries `winning_mark: null` — the field PRESENT and empty rather than
 * absent, which is what `array_key_exists` distinguishes and `?? null` cannot.
 *
 * The Move_List is the nine-Move draw `SubmitMoveTest` plays out; the first eight are
 * seeded and the ninth is posted, so the transition is the application's.
 */
it('emits a game.finished record carrying a null winning mark for a draw', function () {
    $fixture = loggingFixture(GameState::Active, [0, 1, 2, 4, 3, 5, 7, 6]);
    $game = $fixture['game'];

    loggingActAs($fixture, Mark::X);

    post('/games/'.$game->id.'/moves', ['cell_index' => 8])
        ->assertStatus(303)
        ->assertSessionMissing('outcome');

    $finished = loggingSoleRecordOf('game.finished');

    loggingAssertCommonFields($finished, 'game.finished', $game->id);

    expect(array_key_exists('winning_mark', $finished))->toBeTrue('the game.finished record omits winning_mark on a draw instead of carrying it empty')
        ->and($finished['winning_mark'])->toBeNull('a drawn Game reported a winning Mark')
        ->and($finished['result'] ?? null)->toBe('drawn')
        ->and(DB::table('games')->where('id', $game->id)->value('state'))->toBe(GameState::Drawn->value, 'the Game is not drawn, so the record above describes an event that did not happen')
        ->and(loggingEventNames())->toBe(['move.accepted', 'game.finished']);
});

/*
 * Req 10.3's sixth event. `game_id` is the PRECEDING Game — the row whose
 * Version_Counter the creation increments and the one an opponent is polling — and
 * `rematch_game_id` the Game just created.
 */
it('emits exactly one rematch.created record naming both games', function () {
    $fixture = loggingFixture(GameState::Won, [0, 3, 1, 4, 2]);
    $preceding = $fixture['game'];

    loggingActAs($fixture, Mark::X);

    post('/games/'.$preceding->id.'/rematch')->assertStatus(303)->assertSessionMissing('outcome');

    $rematchId = (string) DB::table('games')->where('rematch_of_game_id', $preceding->id)->value('id');
    $record = loggingSoleRecordOf('rematch.created');

    loggingAssertCommonFields($record, 'rematch.created', $preceding->id);

    expect($rematchId)->not->toBe('', 'no Rematch was created, so the record above describes an event that did not happen')
        ->and($record['rematch_game_id'] ?? null)->toBe($rematchId, 'the record does not name the Rematch that was created')
        ->and(loggingEventNames())->toBe(['rematch.created'], 'creating a Rematch emitted records for events that did not happen');
});

/*
 * ─────────────────────────────────────────────────────────────────────────────
 * 2. EVENTS THAT DID NOT HAPPEN EMIT NOTHING (Req 10.3).
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Three requests that succeed or are refused without anything happening: the session
 * short-circuit of Requirements 2.4 and 2.5, which joins nobody; a third browser
 * meeting `game_full`, which claims nothing; and a second Rematch request, which
 * finds the row the first created.
 *
 * Each is asserted against a run in which the event DID happen — the real join and
 * the real creation before them — so "no record" is a difference rather than the
 * absence of any logging at all.
 */
it('emits no record for a join that claims nothing and no second record for a rematch that creates nothing', function () {
    $fixture = loggingFixture(GameState::WaitingForOpponent);
    $game = $fixture['game'];

    // The join that DID happen. The joining session is the O Player from here on, so
    // its id is kept to resume by — the fixture's own O token was never bound to the
    // row, the join wrote its own.
    loggingSwitchSession();
    post('/join', ['join_code' => $game->join_code])->assertStatus(303)->assertSessionMissing('outcome');

    expect(loggingEventNames())->toBe(['game.joined'], 'the join that claimed the O slot emitted no record, so the absences below say nothing');

    // Req 2.4: the same session submits the code again and is short-circuited.
    post('/join', ['join_code' => $game->join_code])
        ->assertStatus(303)
        ->assertRedirect(url('/games/'.$game->id))
        ->assertSessionMissing('outcome');

    $joiner = Session::getId();

    // Req 2.3: a third browser meets `game_full`.
    loggingSwitchSession();
    post('/join', ['join_code' => $game->join_code])
        ->assertStatus(303)
        ->assertSessionHas('outcome', 'game_full');

    expect(loggingEventNames())->toBe(['game.joined'], 'a join that joined nobody emitted a game.joined record (Req 10.3)');

    // And the Rematch, whose second and third requests find the existing row.
    DB::table('games')->where('id', $game->id)->update([
        'state' => GameState::Drawn->value,
        'winning_mark' => null,
    ]);

    loggingActAs($fixture, Mark::X);
    post('/games/'.$game->id.'/rematch')->assertStatus(303)->assertSessionMissing('outcome');

    expect(loggingEventNames())->toBe(['game.joined', 'rematch.created'], 'the request that created the Rematch emitted no record, so the absence below says nothing');

    $rematchId = (string) DB::table('games')->where('rematch_of_game_id', $game->id)->value('id');

    post('/games/'.$game->id.'/rematch')->assertStatus(303)->assertRedirect(url('/games/'.$rematchId));

    // The other Player asks, in the session that joined: a request that creates
    // nothing, from a Player who had not asked before.
    loggingSwitchSession($joiner);
    post('/games/'.$game->id.'/rematch')->assertStatus(303)->assertRedirect(url('/games/'.$rematchId));

    expect(loggingEventNames())->toBe(
        ['game.joined', 'rematch.created'],
        'a Rematch request that found the existing Rematch emitted a second rematch.created record (Req 10.3)',
    )
        ->and(DB::table('games')->where('rematch_of_game_id', $game->id)->count())->toBe(1, 'three Rematch requests created more than one Rematch, so the single record is not a claim about convergence');
});

/*
 * ─────────────────────────────────────────────────────────────────────────────
 * 3. REQUIREMENT 10.5: NO ISSUED PLAYER_TOKEN AND NO JOIN_CODE IN THE OUTPUT
 *    PRODUCED WHILE EXERCISING EVERY ACTION.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * One run through every action the application has, in three Player_Sessions: create,
 * the entry pages, join, a short-circuited join, a `game_full` join, an unmatched
 * code, a refused state request, five accepted Moves, two refused Moves, the win, and
 * both Players' Rematch requests.
 *
 * The needles are the values that run actually issued — read out of the sessions and
 * off the row — rather than constants, so a token format change cannot leave the scan
 * looking for something the application no longer produces. Both Join_Code forms are
 * scanned: the ten stored characters and the hyphenated display form, which is what a
 * Player sees and what a naive `logger` call would interpolate.
 *
 * The non-vacuity guards are the point of the test: a scan for absent needles passes
 * trivially against no output, so the needles are asserted to be real credentials and
 * the output is asserted to hold all six events.
 */
it('writes no issued Player_Token and no Join_Code into the output of a run through every action', function () {
    // ---- Create, in the Creator's session. ----
    loggingSwitchSession();
    post('/games')->assertStatus(303);
    $creator = Session::getId();

    $game = Game::query()->sole();
    $stored = (string) $game->join_code;
    $display = (string) JoinCode::parse($stored)?->display();
    $tokens = new PlayerTokens;

    $secrets = ['the Creator\'s Player_Token' => (string) $tokens->heldFor($game->id)];

    // The three pages that render the Join_Code or the game state.
    get('/')->assertOk();
    get('/join/'.$display)->assertOk();
    get('/games/'.$game->id)->assertOk();
    $creator = Session::getId();

    // ---- Join, in a second session. ----
    loggingSwitchSession();
    post('/join', ['join_code' => $display])->assertStatus(303)->assertSessionMissing('outcome');
    $joiner = Session::getId();

    $secrets['the joining Player\'s Player_Token'] = (string) $tokens->heldFor($game->id);

    // Req 2.4: the same session again, short-circuited.
    post('/join', ['join_code' => $stored])->assertStatus(303)->assertSessionMissing('outcome');
    $joiner = Session::getId();

    // ---- A third, tokenless session: refused everything. ----
    loggingSwitchSession();
    post('/join', ['join_code' => $display])->assertStatus(303)->assertSessionHas('outcome', 'game_full');
    post('/join', ['join_code' => JoinCode::generate()->display()])->assertStatus(303)->assertSessionHas('outcome', 'not_recognised');
    get('/games/'.$game->id)->assertForbidden();

    /**
     * One Move as the session `$id`, returning the id that session must be resumed by
     * afterwards.
     */
    $move = function (string $id, mixed $cellIndex, ?string $outcome) use ($game): string {
        loggingSwitchSession($id);

        $response = post('/games/'.$game->id.'/moves', ['cell_index' => $cellIndex])->assertStatus(303);

        if ($outcome === null) {
            $response->assertSessionMissing('outcome');
        } else {
            $response->assertSessionHas('outcome', $outcome);
        }

        return Session::getId();
    };

    // ---- Five accepted Moves, two refused, and the win. ----
    $creator = $move($creator, 0, null);
    $creator = $move($creator, 6, MoveOutcome::NotYourTurn->value);
    $joiner = $move($joiner, 'banana', MoveOutcome::InvalidMove->value);
    $joiner = $move($joiner, 3, null);
    $creator = $move($creator, 1, null);
    $joiner = $move($joiner, 4, null);
    $creator = $move($creator, 2, null);

    // ---- The Rematch, asked for by both Players. ----
    loggingSwitchSession($creator);
    post('/games/'.$game->id.'/rematch')->assertStatus(303)->assertSessionMissing('outcome');
    $creator = Session::getId();

    $rematchId = (string) DB::table('games')->where('rematch_of_game_id', $game->id)->value('id');
    $secrets['the Creator\'s Rematch Player_Token'] = (string) $tokens->heldFor($rematchId);

    get('/games/'.$rematchId)->assertOk();

    loggingSwitchSession($joiner);
    post('/games/'.$game->id.'/rematch')->assertStatus(303)->assertRedirect(url('/games/'.$rematchId));
    $secrets['the joining Player\'s Rematch Player_Token'] = (string) $tokens->heldFor($rematchId);

    get('/games/'.$rematchId)->assertOk();

    $output = loggingOutput();
    $events = loggingEventNames();

    // ---- Non-vacuity: real credentials, real output, every event present. ----
    foreach ($secrets as $description => $value) {
        expect($value)->toMatch('/^[0-9a-f]{64}$/', "{$description} is not a 64-character Player_Token, so scanning the output for it asserts nothing (Req 3.8)");
    }

    expect($stored)->toHaveLength(10, 'the stored Join_Code is not the ten characters, so scanning for it asserts nothing')
        ->and($display)->toBe(substr($stored, 0, 5).'-'.substr($stored, 5))
        ->and($output)->not->toBe('', 'no records were written at all, so Requirement 10.5 would hold vacuously')
        ->and(count(array_unique(array_values($secrets))))->toBe(4, 'two of the four Player_Tokens are the same value, so fewer credentials were issued than this run assumes')
        ->and(array_count_values($events))->toBe([
            'game.created' => 1,
            'game.joined' => 1,
            'move.accepted' => 5,
            'move.rejected' => 2,
            'game.finished' => 1,
            'rematch.created' => 1,
        ], 'the run did not emit one record per occurrence of each of Requirement 10.3\'s six events: '.implode(', ', $events));

    // ---- Req 10.5. ----
    $secrets['the stored Join_Code'] = $stored;
    $secrets['the displayed Join_Code'] = $display;

    foreach ($secrets as $description => $value) {
        // `str_contains` rather than `toContain()`, which takes variadic needles and
        // no message argument, so a message passed there is silently asserted as a
        // needle.
        expect(str_contains($output, $value))->toBeFalse("{$description} appears in the log output (Req 10.5)");
    }

    // Every record is asserted to carry the three mandated fields, over the whole run
    // rather than over the isolated cases above (Req 10.4).
    foreach (loggingContexts() as $position => $context) {
        $event = $context['event'] ?? null;

        loggingAssertCommonFields($context, is_string($event) ? $event : '', $context['game_id'] ?? '');

        expect(is_string($context['game_id'] ?? null))->toBeTrue("record {$position} carries no Game_Id (Req 10.4)");
    }
});

/*
 * `RedactSecrets` as the channel applies it, which is the only way to assert both that
 * it strips the keys and that it is attached to the channel at all
 * (`config/logging.php`).
 *
 * The surviving keys are asserted as well as the stripped ones: a processor that
 * discarded every context entry would satisfy the absences alone.
 *
 * `GameEventLogger` cannot reach this — it takes typed arguments and has no key-value
 * bag — so the record is written through the channel directly, which is what a future
 * writer bypassing the logger would do.
 */
it('strips context keys naming a token, a join code or a secret at any depth, and keeps the rest', function () {
    Log::channel(GameEventLogger::CHANNEL)->info('redaction.probe', [
        'event' => 'redaction.probe',
        'token' => 'LEAKED-bare-token',
        'Player_Token' => 'LEAKED-player-token',
        'x-token' => 'LEAKED-prefixed-token',
        'joinCode' => 'LEAKED-camel-join-code',
        'join_code' => 'LEAKED-snake-join-code',
        'apiSecret' => 'LEAKED-api-secret',
        'nested' => [
            'join_code' => 'LEAKED-nested-join-code',
            'safe' => 'KEPT-nested-value',
        ],
        'game_id' => 'KEPT-game-id',
        'cell_index' => 4,
    ]);

    $output = loggingOutput();
    $record = loggingSoleRecordOf('redaction.probe');

    expect($output)->not->toBe('', 'the probe record was not written, so the absences below say nothing');

    foreach (['a bare token', 'a Player_Token', 'a prefixed x-token', 'a camelCase joinCode', 'a snake_case join_code', 'an apiSecret', 'a nested join_code'] as $position => $described) {
        $leaked = ['LEAKED-bare-token', 'LEAKED-player-token', 'LEAKED-prefixed-token', 'LEAKED-camel-join-code', 'LEAKED-snake-join-code', 'LEAKED-api-secret', 'LEAKED-nested-join-code'][$position];

        expect(str_contains($output, $leaked))->toBeFalse("the value of {$described} survived redaction (Req 10.5)");
    }

    expect(array_keys($record))->toBe(['event', 'nested', 'game_id', 'cell_index'], 'the surviving context keys are not the safe ones; a processor that discarded everything would pass the absences above')
        ->and($record['game_id'] ?? null)->toBe('KEPT-game-id', 'a safe context entry was stripped')
        ->and($record['cell_index'] ?? null)->toBe(4, 'a safe context entry was stripped')
        ->and($record['nested'] ?? null)->toBe(['safe' => 'KEPT-nested-value'], 'the nested array was dropped whole rather than having its forbidden key removed');
});

/*
 * ─────────────────────────────────────────────────────────────────────────────
 * 4. `game.invariant_violation`, THE DESIGN'S RECORD FOR A CORRUPT MOVE_LIST.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Asserted separately from the six and never folded into their count: no requirement
 * asks for it, only the design's failure table for `InvalidMoveList::Error`, because
 * it reports corruption rather than a Game lifecycle event.
 *
 * The corruption is built here, in fixture data — one `moves` row at Sequence_Index 1
 * with none at 0. The schema accepts it (`SchemaConstraintTest`) and the Rules_Engine
 * rejects it, so `GameSnapshot::of()` throws `CorruptMoveListException` and the
 * `report` hook in `bootstrap/app.php` is what turns it into a record. Removing that
 * hook is what this test exists to fail on.
 */
it('emits exactly one game.invariant_violation record carrying the game id for a corrupt move list', function () {
    $fixture = loggingFixture();
    $game = $fixture['game'];

    $gapped = new Move;
    $gapped->game_id = $game->id;
    $gapped->cell_index = 4;
    $gapped->sequence_index = 1;
    $gapped->save();

    loggingActAs($fixture, Mark::X);

    // The 500 is the framework's answer to an unhandled exception, which is the
    // design's mapping for this row; the record is the part that needed code.
    get('/games/'.$game->id)->assertStatus(500);

    $record = loggingSoleRecordOf('game.invariant_violation');

    loggingAssertCommonFields($record, 'game.invariant_violation', $game->id);

    expect(DB::table('moves')->where('game_id', $game->id)->count())->toBe(1, 'the gapped Move row was not accepted by the schema, so no corruption was constructed')
        ->and(loggingEventNames())->toBe(
            ['game.invariant_violation'],
            'the corruption path emitted a Game lifecycle record; game.invariant_violation is not one of Requirement 10.3\'s six events and must not be reported as one',
        );
});
