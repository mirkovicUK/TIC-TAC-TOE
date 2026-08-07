<?php

declare(strict_types=1);

use App\Domain\TicTacToe\Analysis;
use App\Domain\TicTacToe\Mark;
use App\Domain\TicTacToe\MoveList;
use App\Domain\TicTacToe\RulesEngine;
use App\Games\GameRepresentation;
use App\Games\GameSnapshot;
use App\Games\GameState;
use App\Games\JoinCode;
use App\Games\PlayerTokens;
use App\Models\Game;
use App\Models\Move;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Feature: remote-tic-tac-toe
//
// Validates: Requirements 6.3, 6.7, 7.12, 8.3, 8.4, 8.7
//
/*
 * `GameRepresentation`, the one serialiser producing `props.game`: the exact key
 * set, the field types, the enum backing values in the encoded JSON, the
 * derived-versus-persisted split, the state gate on `joinCode`/`joinUrl`, the
 * absence of any token value, and the query count.
 *
 * `RefreshDatabase` supplies the schema that `DB_DATABASE=:memory:` otherwise leaves
 * absent.
 *
 * Excluded, and where that ground lives instead: Property 11 as a property — the
 * representation against `RulesEngine::analyse` over generated Move_Lists, and the
 * response being identical whatever Version_Counter the request presents — is
 * `RepresentationTest`, and the version half needs the HTTP surface.
 *
 * Every assertion runs against `json_decode(json_encode(...))` rather than the PHP
 * array, so an enum object that never became a string, or a nine-entry map that
 * became a JSON object, fails here rather than in the browser.
 */

uses(RefreshDatabase::class);

/**
 * Every key of the design's `GameProps`, in the design's order.
 *
 * @return list<string>
 */
function representationKeys(): array
{
    return [
        'id',
        'state',
        'version',
        'board',
        'moves',
        'markToMove',
        'yourMark',
        'isYourTurn',
        'winningMark',
        'winningLines',
        'joinCode',
        'joinUrl',
        'rematchGameId',
        'lastMoveAt',
    ];
}

/**
 * A saved Game with `$cellIndices` played into it, as `SubmitMove` would leave it,
 * with both token slots occupied so a leak of either has something to leak.
 *
 * `state` and `winning_mark` are computed from `RulesEngine` rather than passed in,
 * so no test can state an outcome the board does not have. The one test that needs
 * the two sources to disagree writes the column itself, afterwards.
 *
 * @param  list<int>  $cellIndices
 * @return array{Game, array{x: string, o: string}}
 */
function representationGame(array $cellIndices, ?string $joinCode = null): array
{
    $tokens = new PlayerTokens;
    $x = $tokens->mint();
    $o = $tokens->mint();

    $analysis = RulesEngine::analyse(MoveList::fromCellIndices(...$cellIndices));

    if (! $analysis instanceof Analysis) {
        throw new RuntimeException('the fixture was given an ill-formed Move_List, so nothing below would mean anything');
    }

    $game = new Game;
    $game->id = Str::uuid7()->toString();
    $game->join_code = $joinCode ?? JoinCode::generate()->stored;
    $game->state = $cellIndices === []
        ? GameState::Active
        : GameState::fromOutcome($analysis->outcome);
    $game->winning_mark = $analysis->winner();
    $game->version_counter = count($cellIndices) + 1;
    $game->x_token_hash = $x->hash;
    $game->o_token_hash = $o->hash;
    $game->last_activity_at = now();
    $game->save();

    foreach ($cellIndices as $sequenceIndex => $cellIndex) {
        $move = new Move;
        $move->game_id = $game->id;
        $move->cell_index = $cellIndex;
        $move->sequence_index = $sequenceIndex;
        $move->save();
    }

    return [$game->refresh(), ['x' => $x->raw, 'o' => $o->raw]];
}

/**
 * A saved Game waiting for an opponent: X slot occupied, O slot empty, which is
 * what the CHECK on that state requires.
 */
function representationWaitingGame(): Game
{
    $game = new Game;
    $game->id = Str::uuid7()->toString();
    $game->join_code = '10ABCDEFGH';
    $game->state = GameState::WaitingForOpponent;
    $game->version_counter = 0;
    $game->x_token_hash = (new PlayerTokens)->mint()->hash;
    $game->last_activity_at = now();
    $game->save();

    return $game;
}

/**
 * The representation of `$game` for `$mark`, decoded from its own JSON.
 *
 * The round trip is the point: `json_encode` is what the transport does to this
 * array, and the decoded result is the only place enums-as-backing-strings and
 * `board`/`winningLines` as JSON arrays rather than objects are observable.
 *
 * @return array<string, mixed>
 */
function representationOf(Game $game, Mark $mark): array
{
    $encoded = json_encode(GameRepresentation::of(GameSnapshot::of($game), $mark));

    expect($encoded)->toBeString('the representation could not be encoded as JSON, so it cannot reach the client');

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode((string) $encoded, true, flags: JSON_THROW_ON_ERROR);

    return $decoded;
}

/*
 * The exact key set and the exact types. Equality on the key list rather than a
 * series of "has a key" checks, so an extra field fails too — an extra field is how
 * a token hash, a `last_activity_at` or a `rematch_of_game_id` would reach the
 * client. Order carries no meaning to a JSON consumer and is pinned only to keep
 * this list diffable against the design's `GameProps` block.
 */
it('produces exactly the fields of the design shape, in order, with the design types', function () {
    [$game] = representationGame([4, 0, 8]);

    $props = representationOf($game, Mark::O);

    expect(array_keys($props))->toBe(representationKeys(), 'the representation is not the design shape: '.implode(', ', array_keys($props)));

    expect($props['id'])->toBeString()
        ->and($props['state'])->toBeString()
        ->and($props['version'])->toBeInt()
        ->and($props['board'])->toBeArray()
        ->and($props['board'])->toHaveCount(9, 'board is not nine cells')
        ->and(array_keys($props['board']))->toBe(range(0, 8), 'board decoded as a JSON object rather than a nine-entry array')
        ->and($props['moves'])->toBeArray()
        ->and($props['markToMove'])->toBeString()
        ->and($props['yourMark'])->toBeString()
        ->and($props['isYourTurn'])->toBeBool()
        ->and($props['winningLines'])->toBeArray()
        ->and($props['lastMoveAt'])->toBeString();

    // The fixture is `active`, unwon and has no rematch, so all four nullable fields
    // should be null.
    foreach (['winningMark', 'joinCode', 'joinUrl', 'rematchGameId'] as $nullable) {
        expect($props[$nullable])->toBeNull("{$nullable} is not null on a Game that has none");
    }

    // `'x'`/`'o'`/null and nothing else is the client's union for a cell.
    foreach ($props['board'] as $cellIndex => $occupant) {
        expect($occupant === null || $occupant === 'x' || $occupant === 'o')
            ->toBeTrue("board cell {$cellIndex} is not 'x', 'o' or null");
    }

    foreach ($props['moves'] as $index => $move) {
        expect(array_keys($move))->toBe(['cell', 'sequence', 'mark'], "moves[{$index}] is not {cell, sequence, mark}")
            ->and($move['cell'])->toBeInt()
            ->and($move['sequence'])->toBeInt()
            ->and($move['mark'])->toBeString();
    }
});

/*
 * Enum backing values, checked in the encoded JSON rather than reasoned about.
 * `json_encode` serialises `Mark` and `GameState` as their backing values only
 * because they are *backed* enums; `WinningLine` in the same namespace is not. Every
 * enum-valued field is compared against a literal on all four Game_States, so a
 * dropped `->value` surfaces as `{"value":"x"}` rather than as a pass.
 */
it('emits enum values as their backing strings for every game state', function () {
    $waiting = representationWaitingGame();

    expect(representationOf($waiting, Mark::X)['state'])->toBe('waiting_for_opponent');

    // Expected `markToMove` is spelled out per case rather than computed from the
    // list length, so a serialiser deriving it from something other than parity fails
    // here: two Moves → `x`, five → `o`, nine → `o`.
    $cases = [
        'active' => [[4, 0], Mark::X, 'x'],
        'won' => [[0, 3, 1, 4, 2], Mark::X, 'o'],
        'drawn' => [[0, 1, 2, 4, 3, 5, 7, 6, 8], Mark::O, 'o'],
    ];

    foreach ($cases as $state => [$cellIndices, $yourMark, $expectedMarkToMove]) {
        [$game] = representationGame($cellIndices);

        $props = representationOf($game, $yourMark);

        expect($props['state'])->toBe($state, "the Game_State did not serialise as '{$state}'")
            ->and($props['markToMove'])->toBe($expectedMarkToMove, "markToMove did not serialise as '{$expectedMarkToMove}' on a {$state} Game")
            ->and($props['yourMark'])->toBe($yourMark->value, "yourMark did not serialise as '{$yourMark->value}' on a {$state} Game");
    }

    // `won` is the only state carrying a winning Mark.
    [$won] = representationGame([0, 3, 1, 4, 2]);

    expect(representationOf($won, Mark::X)['winningMark'])->toBe('x', 'the persisted winning Mark did not serialise as its backing value');
});

/*
 * The derived-versus-persisted split, made observable by forcing the two sources to
 * disagree: a `won` row is written with `winning_mark = 'o'` on a board X won. The
 * row's `'o'` must reach `winningMark` while the derivation supplies the lines and
 * `markToMove`, unreconciled.
 *
 * `SubmitMove` writes that column only from `Analysis::winner()`, so this row is
 * unreachable in the application and is written with `DB::table()` to get past the
 * model's cast. It satisfies the `state = 'won'` ⇔ `winning_mark IS NOT NULL` CHECK,
 * and it is the only thing that catches a serialiser reading `winningMark` from
 * `Analysis::winner()`.
 *
 * The first expectation is a non-vacuity guard: it rules out the fixture board not
 * being won by X, in which case the two sources would agree.
 */
it('reads winningMark from the row and the lines and mark to move from the analysis', function () {
    [$game] = representationGame([0, 3, 1, 4, 2]);

    $derived = RulesEngine::analyse(GameSnapshot::of($game)->moveList);

    expect($derived)->toBeInstanceOf(Analysis::class)
        ->and($derived instanceof Analysis ? $derived->winner() : null)
        ->toBe(Mark::X, 'the fixture board was not won by X, so this test asserts nothing');

    DB::table('games')->where('id', $game->id)->update(['winning_mark' => 'o']);

    $props = representationOf($game->fresh() ?? $game, Mark::X);

    expect($props['winningMark'])->toBe('o', 'winningMark was not read from the row: the serialiser reconciled it against the derived winner, which would make Property 11 true by construction')
        ->and($props['winningLines'])->toBe([[0, 1, 2]], 'winningLines was not read from the Analysis')
        ->and($props['board'])->toBe(['x', 'x', 'x', 'o', 'o', null, null, null, null], 'board was not read from the Analysis')
        ->and($props['state'])->toBe('won', 'state was not read from the row');

    // 41 does not match the Move count, so a `version` derived from the Move_List
    // length fails here.
    DB::table('games')->where('id', $game->id)->update(['version_counter' => 41]);

    expect(representationOf($game->fresh() ?? $game, Mark::X)['version'])->toBe(41, 'version was not read from the row (Req 8.3)');
});

/*
 * `markToMove` stays defined in a Terminal_State (Req 4.1) and `isYourTurn` follows
 * it with no state check, so `isYourTurn` is true for O on a board X has won. That is
 * intended: `Board.tsx`'s disabled condition is `!isYourTurn || state !== 'active'`,
 * and nulling `markToMove` here would move a UI decision into the serialiser.
 *
 * Both Players are read off the same row, so the only difference is the Mark.
 */
it('keeps markToMove defined in a terminal state and derives isYourTurn from it alone', function () {
    [$won] = representationGame([0, 3, 1, 4, 2]);

    $forX = representationOf($won, Mark::X);
    $forO = representationOf($won, Mark::O);

    expect($forX['state'])->toBe('won')
        ->and($forX['markToMove'])->toBe('o', 'markToMove was nulled or changed in a Terminal_State (Req 4.1)')
        ->and($forO['markToMove'])->toBe('o', 'markToMove depends on which Player is viewing, which it must not')
        ->and($forX['isYourTurn'])->toBeFalse('isYourTurn is true for X when markToMove is o')
        ->and($forO['isYourTurn'])->toBeTrue('isYourTurn is not markToMove === yourMark: it was suppressed because the Game is over, which is Board.tsx\'s job and not the serialiser\'s');

    // The same claim on a full board: nine Moves, so `markToMove` is `o`.
    [$drawn] = representationGame([0, 1, 2, 4, 3, 5, 7, 6, 8]);

    $props = representationOf($drawn, Mark::O);

    expect($props['state'])->toBe('drawn')
        ->and($props['markToMove'])->toBe('o', 'markToMove is not the parity of the Move_List length in a drawn Game')
        ->and($props['isYourTurn'])->toBeTrue()
        ->and($props['winningMark'])->toBeNull()
        ->and($props['winningLines'])->toBe([], 'a drawn Game reported a Winning_Line');
});

/*
 * Both lines of a double win (Req 6.3, 6.5). `X0 O1 X2 O3 X6 O5 X8 O7 X4` — X's
 * ninth Move at cell 4 completes both diagonals, reachable in legal play.
 *
 * The lines are sorted before comparison because the engine finds them in
 * `WinningLine::cases()` order, which is not part of the client's contract. The count
 * is asserted separately so "contains both" cannot pass by reporting one line twice.
 */
it('reports every completed winning line for a double win', function () {
    [$game] = representationGame([0, 1, 2, 3, 6, 5, 8, 7, 4]);

    $props = representationOf($game, Mark::X);

    $lines = $props['winningLines'];
    sort($lines);

    expect($props['state'])->toBe('won', 'the double-win board is not won')
        ->and($props['winningMark'])->toBe('x')
        ->and($lines)->toHaveCount(2, 'the double win did not report two lines (Req 6.3)')
        ->and($lines)->toBe([[0, 4, 8], [2, 4, 6]], 'the double win did not report both diagonals')
        ->and($props['board'])->toBe(['x', 'o', 'x', 'o', 'x', 'o', 'x', 'o', 'x'], 'the double-win board is not the board that was played');
});

/*
 * `joinCode` and `joinUrl` only while `waiting_for_opponent`. All three other states
 * are checked, not just `won`: a gate written against `isTerminal()` or an empty
 * Move_List would leak the code on `active` and still pass.
 *
 * The column holds the unhyphenated ten characters while the prop is the hyphenated
 * display form, so both are asserted against the same row. The `join_code` expectation
 * in the loop rules out a null prop passing because the fixture has no code at all.
 */
it('exposes the join code and join url only while waiting for an opponent', function () {
    $waiting = representationWaitingGame();

    $props = representationOf($waiting, Mark::X);

    expect($waiting->join_code)->toBe('10ABCDEFGH', 'the column does not hold the unhyphenated form')
        ->and($props['joinCode'])->toBe('10ABC-DEFGH', 'joinCode is not the hyphenated display form')
        ->and($props['joinUrl'])->toBeString()
        ->and($props['joinUrl'])->toBe(url('/join/10ABC-DEFGH'), 'joinUrl is not an absolute URL at the join action carrying the code')
        ->and(str_starts_with((string) $props['joinUrl'], 'http'))->toBeTrue('joinUrl is not absolute, so it cannot be pasted into another browser (Req 1.6)');

    foreach (['active' => [4, 0], 'won' => [0, 3, 1, 4, 2], 'drawn' => [0, 1, 2, 4, 3, 5, 7, 6, 8]] as $state => $cellIndices) {
        [$game] = representationGame($cellIndices);

        $other = representationOf($game, Mark::X);

        expect($other['state'])->toBe($state, 'the fixture is not in the state this iteration means to check')
            ->and($game->join_code)->not->toBeNull('the fixture has no Join_Code, so a null joinCode would pass trivially')
            ->and($other['joinCode'])->toBeNull("joinCode is exposed on a {$state} Game")
            ->and($other['joinUrl'])->toBeNull("joinUrl is exposed on a {$state} Game");
    }
});

/*
 * `rematchGameId` whenever a rematch exists (Req 7.12).
 *
 * The rematch row is inserted by hand rather than through `CreateRematch` so a break
 * in the rematch service does not fail a serialiser test; `RematchTest` covers the
 * producer. The shape follows ADR-010: `active`, `join_code` NULL, both token slots
 * NULL, `rematch_of_game_id` pointing back.
 *
 * `rematch_of_game_id` lives on the *rematch* row, so the direction is what matters:
 * the preceding Game reports the rematch's id and the rematch reports null. A
 * serialiser reading `$game->rematch_of_game_id` instead of the back-reference gets
 * those exactly the wrong way round, which is why both are asserted.
 */
it('reports the rematch game id on the preceding game once a rematch exists', function () {
    [$preceding] = representationGame([0, 3, 1, 4, 2]);

    expect(representationOf($preceding, Mark::X)['rematchGameId'])->toBeNull('a Game with no rematch reported one');

    $rematch = new Game;
    $rematch->id = Str::uuid7()->toString();
    $rematch->state = GameState::Active;
    $rematch->version_counter = 0;
    $rematch->rematch_of_game_id = $preceding->id;
    $rematch->last_activity_at = now();
    $rematch->save();

    $props = representationOf($preceding->fresh() ?? $preceding, Mark::X);

    expect($props['rematchGameId'])->toBe($rematch->id, 'the preceding Game did not report the Game_Id of its rematch (Req 7.12)')
        ->and(representationOf($rematch, Mark::O)['rematchGameId'])
        ->toBeNull('the rematch reported itself as its own rematch: the back-reference was read in the wrong direction')
        ->and(representationOf($rematch, Mark::O)['joinCode'])
        ->toBeNull('the rematch has no Join_Code and must not report one');
});

/*
 * `lastMoveAt` is null on an empty board and ISO 8601 UTC otherwise. The empty case
 * catches the obvious wrong implementation, reading `games.last_activity_at`, which is
 * populated at creation and would start the client's idle clock from the join rather
 * than from a Move (Req 9.4).
 *
 * The format is verified by parsing rather than by regex: a regex would accept
 * `2026-01-01T00:00:00Z` written off a local-time clock, which silently costs a
 * minute of the idle threshold.
 */
it('reports lastMoveAt as ISO 8601 UTC, and null when no move exists', function () {
    $waiting = representationWaitingGame();

    expect(representationOf($waiting, Mark::X)['lastMoveAt'])
        ->toBeNull('a Game with an empty Move_List reported a lastMoveAt, which is games.last_activity_at rather than a Move');

    [$empty] = representationGame([]);

    expect(representationOf($empty, Mark::X)['lastMoveAt'])
        ->toBeNull('an active Game with no Moves reported a lastMoveAt');

    [$played] = representationGame([4, 0, 8]);

    $stored = Move::query()->where('game_id', $played->id)->orderByDesc('sequence_index')->firstOrFail();

    $reported = (string) representationOf($played, Mark::O)['lastMoveAt'];

    $parsed = new DateTimeImmutable($reported);

    expect(str_ends_with($reported, 'Z'))->toBeTrue("lastMoveAt carries no UTC designator, so Date.parse is implementation-defined: {$reported}")
        ->and($parsed->getOffset())->toBe(0, "lastMoveAt does not parse as UTC: {$reported}")
        ->and($parsed->format('Y-m-d H:i:s'))->toBe(
            $stored->created_at->utc()->format('Y-m-d H:i:s'),
            "lastMoveAt is not the created_at of the last Move: {$reported}",
        );
});

/*
 * No token value anywhere in the output (Req 8.7).
 *
 * The encoded string is searched rather than the fields checked one by one, so a
 * nested value, an extra key or a hash concatenated into a URL is covered too. The
 * digests are searched for as well as the raw tokens because a hash in the payload is
 * a verifier for an offline guess.
 *
 * The Game_Id search afterwards is the non-vacuity guard: it rules out a typo'd
 * haystack, in which every absence above would hold for free.
 */
it('excludes every player token value and digest from the serialised representation', function () {
    [$game, $raw] = representationGame([4, 0, 8]);

    $encoded = (string) json_encode(GameRepresentation::of(GameSnapshot::of($game), Mark::X));

    $secrets = [
        'the raw X token' => $raw['x'],
        'the raw O token' => $raw['o'],
        'the X token digest' => (string) $game->x_token_hash,
        'the O token digest' => (string) $game->o_token_hash,
    ];

    foreach ($secrets as $description => $secret) {
        expect($secret)->not->toBe('', "{$description} is empty, so searching for it would pass vacuously");

        // `str_contains` rather than `toContain()`, which takes variadic needles and
        // no message argument — a message passed there is silently asserted as a
        // second needle.
        expect(str_contains($encoded, $secret))->toBeFalse("{$description} appears in the game representation (Req 8.7)");
    }

    expect(str_contains($encoded, $game->id))->toBeTrue('the Game_Id is absent from the payload, so the searches above prove nothing');

    // Column names that could only have come from a token slot.
    foreach (['token', 'hash', 'x_token_hash', 'o_token_hash'] as $fragment) {
        expect(stripos($encoded, $fragment))->toBeFalse("the representation mentions '{$fragment}' (Req 8.7)");
    }
});

/*
 * The signature cannot express a conditional request (Req 8.4, ADR-002). Asserted by
 * reflection because the claim is an absence with no behaviour to observe: a third
 * parameter carrying a client Version_Counter is the shape a 304 path needs, and a
 * nullable return is the shape "unchanged" needs.
 *
 * The HTTP half of Req 8.4 — no ETag, identical response whatever version the request
 * presents — needs the game route and lives in `RepresentationTest`.
 */
it('takes no client version and returns the whole representation on every call', function () {
    $method = new ReflectionMethod(GameRepresentation::class, 'of');

    $parameters = array_map(
        static fn (ReflectionParameter $parameter): string => (string) $parameter->getType(),
        $method->getParameters(),
    );

    expect($parameters)->toBe(
        [GameSnapshot::class, Mark::class],
        'GameRepresentation::of accepts something other than a snapshot and a Mark; a client Version_Counter parameter is the shape a 304 path needs (ADR-002)',
    )->and((string) $method->getReturnType())->toBe('array', 'the return type admits a "not modified" value (Req 8.4)');

    [$game] = representationGame([4, 0, 8]);

    expect(representationOf($game, Mark::X))->toBe(representationOf($game, Mark::X), 'two identical state requests produced different representations');
});

/*
 * The query cost, counted rather than claimed, because this runs on the polling path
 * (Req 8.1). `GameRepresentation::of` issues two of its own — the rematch
 * back-reference and the last Move's timestamp — on top of the one `GameSnapshot::of`
 * issues for the Move_List, so three per poll.
 *
 * The rematch lookup is honoured from a loaded relation, which is the only lever a
 * caller has; a change that stopped honouring it would otherwise be invisible.
 */
it('issues two queries of its own, one of which an eager-loading caller can avoid', function () {
    [$game] = representationGame([4, 0, 8]);

    $count = static function (callable $work): int {
        DB::enableQueryLog();
        DB::flushQueryLog();

        $work();

        $queries = count(DB::getQueryLog());

        DB::disableQueryLog();

        return $queries;
    };

    $snapshot = GameSnapshot::of($game);

    $lazy = $count(fn () => GameRepresentation::of($snapshot, Mark::X));

    $eager = $count(function () use ($game) {
        $loaded = Game::query()->with('rematch')->findOrFail($game->id);

        return GameRepresentation::of(GameSnapshot::of($loaded), Mark::X);
    });

    expect($lazy)->toBe(2, 'GameRepresentation::of no longer issues exactly two queries of its own; the polling path cost has changed')
        // Four: the row, the rematch eager load, the Move_List read and the last-Move
        // read, with the rematch lookup folded into the eager load rather than issued
        // by the serialiser.
        ->and($eager)->toBe(4, 'the eager-loaded rematch relation is no longer honoured, so a caller has no way to avoid that query');
});
