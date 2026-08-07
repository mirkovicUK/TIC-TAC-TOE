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
 * Task 5.5 — `GameRepresentation`, the one serialiser producing `props.game`.
 *
 * A Feature test necessarily: the subject reads a `games` row, its `moves` rows
 * and its rematch back-reference, and `RefreshDatabase` supplies the schema that
 * `DB_DATABASE=:memory:` otherwise leaves absent.
 *
 * NAMED `GameRepresentationTest` SO AS NOT TO COLLIDE WITH TASK 12.4, which writes
 * `RepresentationTest` for Property 11. The split is deliberate and the boundary is
 * worth stating, because the two files would otherwise drift into duplicates.
 *
 *   - HERE: the shape as it reaches the client. The exact key set, the field types,
 *     the enum backing values in the encoded JSON, the derived-versus-persisted
 *     split as a *construction* claim, the state gate on `joinCode`/`joinUrl`, the
 *     absence of any token value, and the query count.
 *   - TASK 12.4: Property 11 as a property — the representation equals
 *     `RulesEngine::analyse` over generated Move_Lists, the persisted
 *     `winning_mark` equals the derived winner across positions, and the response
 *     is identical whatever Version_Counter the request presents. The last of those
 *     needs the HTTP surface of task 5.6 and cannot be written here at all.
 *
 * Every assertion below runs against `json_decode(json_encode(...))` rather than
 * against the PHP array, so what is verified is the shape as it reaches the client
 * — an enum object that never became a string, or a nine-entry map that became a
 * JSON object instead of an array, fails here rather than in the browser.
 */

uses(RefreshDatabase::class);

/**
 * Every key of the design's `GameProps`, in the design's order.
 *
 * Named as a constant rather than inlined so that the key-set assertion and the
 * type table below cannot disagree about what the shape is.
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
 * A saved Game with `$cellIndices` played into it, as `SubmitMove` would leave it:
 * contiguous sequence indices from zero, `state` and `winning_mark` written from
 * the derivation, and both token slots occupied so that a leak of either would have
 * something to leak.
 *
 * The Game_State and the winning Mark are computed here from `RulesEngine` rather
 * than passed in, because a fixture that let a test *state* the outcome would let a
 * test state one the board does not have — and the derived-versus-persisted split
 * is the thing under examination. The one test that needs a disagreement writes the
 * column itself, afterwards.
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
 * array, and asserting on the decoded result is the only way to verify that enums
 * left as backing strings and that `board` and `winningLines` are JSON arrays
 * rather than objects.
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
 * THE EXACT KEY SET, AND THE EXACT TYPES.
 *
 * Asserted as equality on the key list rather than as a series of "has a key"
 * checks, so that BOTH failure directions fail here: a missing field, and an extra
 * one. The second matters more than it looks — an extra field is how a token hash,
 * a `last_activity_at` or a `rematch_of_game_id` would arrive in the client, and no
 * assertion about the fields the design names would notice it.
 *
 * The order is asserted too. It carries no meaning to a JSON consumer, and it is
 * pinned anyway because it is free to pin and it keeps this file and the design's
 * `GameProps` block diffable by eye.
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

    // The four nullable fields, on a Game that has none of them: `active`, so no
    // join code and no join URL; not won, so no winning Mark; no rematch.
    foreach (['winningMark', 'joinCode', 'joinUrl', 'rematchGameId'] as $nullable) {
        expect($props[$nullable])->toBeNull("{$nullable} is not null on a Game that has none");
    }

    // Every cell of a three-Move board: two occupied by the two Marks, and the rest
    // null. `'x'`/`'o'`/null and nothing else is the client's union.
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
 * ENUM BACKING VALUES, VERIFIED IN THE ENCODED JSON RATHER THAN ASSUMED.
 *
 * `Mark` and `GameState` are backed enums, and a backed enum passed to
 * `json_encode` does serialise as its backing value — but only because it is a
 * *backed* enum, and `WinningLine` in the same namespace is not. So this is checked
 * rather than reasoned about: every enum-valued field is compared against a literal
 * string, on all four Game_States, so a `->value` dropped anywhere would surface as
 * `{"value":"x"}` or as a JSON failure instead of a passing test.
 */
it('emits enum values as their backing strings for every game state', function () {
    $waiting = representationWaitingGame();

    expect(representationOf($waiting, Mark::X)['state'])->toBe('waiting_for_opponent');

    // `markToMove` is the parity of the Move_List length in every one of these:
    // two Moves → `x`, five → `o`, nine → `o`. Spelled out per case rather than
    // computed, so a serialiser that derived it from something other than parity
    // fails here.
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

    // The winning Mark, from the row, on the only state that has one.
    [$won] = representationGame([0, 3, 1, 4, 2]);

    expect(representationOf($won, Mark::X)['winningMark'])->toBe('x', 'the persisted winning Mark did not serialise as its backing value');
});

/*
 * THE DERIVED-VERSUS-PERSISTED SPLIT, ASSERTED WHERE IT IS OBSERVABLE.
 *
 * The split is only testable at all because the two sources can be made to
 * disagree, so this test makes them disagree: a `won` row is written with
 * `winning_mark = 'o'` on a board X won. The representation must report the ROW's
 * `'o'` as `winningMark` while reporting the DERIVATION's lines and `markToMove`,
 * and it must not reconcile the two.
 *
 * That is a state the application cannot produce — `SubmitMove` writes the column
 * only from `Analysis::winner()` — which is precisely why it is written here by
 * hand, with `DB::table()` to get past the model's cast. It is not a scenario to
 * support; it is the only way to observe *which source each field came from*, and
 * without it a serialiser that read `winningMark` from `Analysis::winner()` would
 * pass every other test in this file.
 *
 * The `state = 'won'` ⇔ `winning_mark IS NOT NULL` CHECK is satisfied throughout,
 * so this is a row the schema permits.
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

    // The other half of the split: `version` is the row's counter, not a count of
    // Moves and not anything the Analysis knows.
    DB::table('games')->where('id', $game->id)->update(['version_counter' => 41]);

    expect(representationOf($game->fresh() ?? $game, Mark::X)['version'])->toBe(41, 'version was not read from the row (Req 8.3)');
});

/*
 * `markToMove` IS STILL DEFINED IN A TERMINAL STATE (Req 4.1), AND `isYourTurn`
 * FOLLOWS IT WITHOUT A STATE CHECK.
 *
 * The design's own example: X wins at Sequence_Index 4, so `markToMove` is `O` and
 * `isYourTurn` is TRUE for the O Player on a finished board. That is asserted here
 * as the intended behaviour rather than patched, because `Board.tsx`'s disabled
 * condition is `!isYourTurn || state !== 'active'` and its second half is what keeps
 * the board inert — nulling `markToMove` here would move a UI decision into the
 * serialiser.
 *
 * Both Players are checked on the same board, which is also the whole of
 * `isYourTurn == (markToMove == yourMark)` in one place: identical row, opposite
 * answers, and the only thing that differs is the Mark the token is bound to.
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

    // A drawn board, where the ninth Move leaves `markToMove` as the parity of nine.
    [$drawn] = representationGame([0, 1, 2, 4, 3, 5, 7, 6, 8]);

    $props = representationOf($drawn, Mark::O);

    expect($props['state'])->toBe('drawn')
        ->and($props['markToMove'])->toBe('o', 'markToMove is not the parity of the Move_List length in a drawn Game')
        ->and($props['isYourTurn'])->toBeTrue()
        ->and($props['winningMark'])->toBeNull()
        ->and($props['winningLines'])->toBe([], 'a drawn Game reported a Winning_Line');
});

/*
 * BOTH LINES OF A DOUBLE WIN (Req 6.3, 6.5).
 *
 * `X0 O1 X2 O3 X6 O5 X8 O7 X4` — X's ninth Move at cell 4 completes both diagonals,
 * which is why Requirement 6.3 is plural and why `winningLines` is a list of lines
 * rather than one line. Reachable in legal play, so this is a board the application
 * can actually produce.
 *
 * Asserted as an unordered set of cell triples, because the order the engine finds
 * the lines in is `WinningLine::cases()` order and is not part of the client's
 * contract; asserting the order would pin an implementation detail. The count is
 * asserted separately so that "contains both" cannot pass by reporting one line
 * twice.
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
 * `joinCode` AND `joinUrl` ARE PRESENT ONLY WHILE `waiting_for_opponent`, AND NULL
 * IN ALL THREE OTHER STATES.
 *
 * All three are asserted, not one: the gate is a single comparison, and a gate
 * written against `isTerminal()` or against an empty Move_List would leak the code
 * on `active` while passing a test that only checked `won`. `active` is the
 * dangerous one — the Game is in play and a third party who reads the code off a
 * shared screen has nothing to gain but should not be offered it.
 *
 * The code is the HYPHENATED display form while the column holds the unhyphenated
 * ten characters, so both forms are asserted against the same row.
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
 * `rematchGameId` IS PRESENT WHENEVER A REMATCH EXISTS (Req 7.12).
 *
 * `CreateRematch` is task 7.1 and does not exist yet, so the rematch row is
 * inserted here directly — which is the only way this field can be covered at all,
 * and covering it now is better than leaving a field of the shape untested until
 * its producer is written. The row is inserted the way ADR-010 says a rematch is:
 * `active`, `join_code` NULL, both token slots NULL, `rematch_of_game_id` pointing
 * back at the preceding Game.
 *
 * The direction is the thing worth pinning. `rematch_of_game_id` lives on the
 * *rematch* row, so the preceding Game must report the rematch's id and the rematch
 * must report null — a serialiser that read `$game->rematch_of_game_id` instead of
 * the back-reference would have those two exactly the wrong way round, and would
 * pass any test that only looked at one of them.
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
 * `lastMoveAt` IS NULL ON AN EMPTY BOARD, AND ISO 8601 UTC OTHERWISE.
 *
 * The empty case is the one that matters, because the obvious wrong implementation
 * — reading `games.last_activity_at`, which is populated at creation — reports a
 * timestamp for a Game nobody has moved in, and the client's idle indication would
 * then start its clock from the join rather than from a Move (Req 9.4).
 *
 * The format is verified by PARSING it rather than by matching a regex: the string
 * is read back as a date, compared for equality with the stored timestamp to the
 * second, and its zone is asserted to be UTC. A regex would accept
 * `2026-01-01T00:00:00Z` written from a local-time clock, which is the failure that
 * actually costs a minute of the idle threshold.
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
 * NO TOKEN VALUE ANYWHERE IN THE OUTPUT (Req 8.7).
 *
 * Walked over the SERIALISED JSON rather than checked field by field, because a
 * field-by-field check only covers the fields somebody thought of: a nested value,
 * an extra key, or a hash concatenated into a URL would all survive it. Searching
 * the encoded string for the four secrets — two raw tokens and their two digests —
 * covers the whole payload including anything a future field adds.
 *
 * The digests are searched for as well as the raw values, because "not even hashed"
 * is the actual requirement: a hash in the payload is a verifier for an offline
 * guess, and there is no reason for the client to hold one.
 *
 * The search is also confirmed to be capable of failing, by finding a value that IS
 * in the payload. Without that, a typo in the haystack would make every assertion
 * here vacuous.
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

        // `str_contains` rather than `toContain()`, which takes variadic needles
        // and no message argument — a message passed there is silently asserted as
        // a second needle.
        expect(str_contains($encoded, $secret))->toBeFalse("{$description} appears in the game representation (Req 8.7)");
    }

    // The search works: a value that is in the payload is found by the same means.
    expect(str_contains($encoded, $game->id))->toBeTrue('the Game_Id is absent from the payload, so the searches above prove nothing');

    // And no column name that could only have come from a token slot is present.
    foreach (['token', 'hash', 'x_token_hash', 'o_token_hash'] as $fragment) {
        expect(stripos($encoded, $fragment))->toBeFalse("the representation mentions '{$fragment}' (Req 8.7)");
    }
});

/*
 * THE SIGNATURE CANNOT EXPRESS A CONDITIONAL REQUEST (Req 8.4, ADR-002).
 *
 * Asserted by reflection, because the claim is about an ABSENCE and there is no
 * behaviour to observe: `of()` takes a snapshot and a Mark, and nothing else. A
 * third parameter carrying a client Version_Counter is the shape a 304 path would
 * need, and a nullable return is the shape "unchanged" would need — so both are
 * pinned here rather than left to prose. The HTTP half of Requirement 8.4 (no
 * ETag, identical response whatever version the request presents) needs task 5.6's
 * routes and belongs to task 12.4.
 *
 * The behavioural half that IS available is asserted alongside: two calls on the
 * same row produce identical output, so nothing in the serialiser is stateful or
 * remembers what it last sent.
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
 * THE QUERY COST, COUNTED RATHER THAN CLAIMED.
 *
 * This runs on the polling path, twice every two seconds per Game (Req 8.1), so the
 * number is worth pinning: `GameRepresentation::of` itself issues TWO queries — the
 * rematch back-reference and the last Move's timestamp — on top of the one
 * `GameSnapshot::of` issues for the Move_List. Three per poll in `App\Games`.
 *
 * The rematch lookup is honoured from a loaded relation, so a caller that eager-loads
 * pays two instead of three; that is asserted too, since it is the only lever a
 * caller has and a change that stopped honouring it would be invisible otherwise.
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
        // The eager call is: the row, the rematch eager load, the Move_List read,
        // and the last-Move read. Four, with the rematch lookup folded into the
        // eager load rather than issued by the serialiser.
        ->and($eager)->toBe(4, 'the eager-loaded rematch relation is no longer honoured, so a caller has no way to avoid that query');
});
