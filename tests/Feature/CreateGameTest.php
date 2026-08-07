<?php

declare(strict_types=1);

use App\Domain\TicTacToe\Mark;
use App\Games\CreateGame;
use App\Games\GameState;
use App\Games\JoinCode;
use App\Games\PlayerTokens;
use App\Models\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

// Feature: remote-tic-tac-toe
//
// Validates: Requirements 1.1, 1.2, 1.3, 1.4, 1.5, 8.7
//
/*
 * Task 5.2 — `CreateGame`.
 *
 * A Feature test necessarily: the subject inserts a row and writes the session,
 * so it needs both. `RefreshDatabase` supplies the schema that `DB_DATABASE=:memory:`
 * otherwise leaves absent, and `phpunit.xml` sets `SESSION_DRIVER=array`, so the
 * session is in-memory and per-test — the same arrangement `PlayerTokensTest`
 * uses.
 *
 * The Join_Code generator is asserted in `JoinCodeTest`, which needs neither a
 * database nor a session. What is asserted here is the *row*: that the generated
 * code reaches the column in its stored form, and that everything else about a
 * freshly created Game is what Requirement 1 says it is.
 *
 * A note on the insert itself. Every assertion below runs against a row that the
 * schema accepted, and that schema carries seven CHECK constraints — so "it
 * saved" already establishes that the state is one of the four legal values, that
 * `winning_mark` is absent while the state is not `won`, that `version_counter`
 * is not negative, that the Game is reachable, and that the O slot is empty while
 * it waits. The assertions are written out anyway, because a constraint tells you
 * a value was *legal*, not that it was the one Requirement 1 asks for.
 */

uses(RefreshDatabase::class);

/**
 * The subject, with its one collaborator supplied explicitly rather than
 * resolved from the container, so the test states what `CreateGame` depends on.
 */
function createGame(): CreateGame
{
    return new CreateGame(new PlayerTokens);
}

/*
 * Req 1.1: `waiting_for_opponent` and an empty Move_List.
 *
 * The empty Move_List is the ABSENCE of `moves` rows, not a column — so it is
 * asserted as a count of zero, and there is nothing for `CreateGame` to
 * initialise. `version_counter` starts at 0 and `o_token_hash` is NULL, which is
 * what "no second player has joined" means (Req 2.1).
 *
 * The row is re-read from the database rather than trusted from the model, so
 * these are assertions about what was persisted.
 */
it('creates a game waiting for an opponent, with no moves and no second player', function () {
    $game = createGame()->handle();

    $stored = Game::query()->findOrFail($game->id);

    expect($game->state)->toBe(GameState::WaitingForOpponent)
        ->and($stored->state)->toBe(GameState::WaitingForOpponent, 'the persisted state is not waiting_for_opponent (Req 1.1)')
        ->and($stored->version_counter)->toBe(0)
        ->and($stored->o_token_hash)->toBeNull('a second player was assigned at creation (Req 2.1)')
        ->and($stored->winning_mark)->toBeNull()
        ->and($stored->rematch_of_game_id)->toBeNull()
        ->and($stored->moves()->count())->toBe(0, 'the Move_List of a new Game must be empty (Req 1.1)')
        ->and($stored->last_activity_at->timestamp)->toBeGreaterThan(0, 'last_activity_at was not set');
});

/*
 * Req 1.2: a UUIDv7 generated in PHP, deriving from no database sequence.
 *
 * THE VERSION NIBBLE IS CHECKED, NOT JUST THE SHAPE. Every UUID version has the
 * same 8-4-4-4-12 layout, so a shape-only assertion would pass for a UUIDv4 — and
 * v4 would satisfy Requirement 1.2 as it happens, but it is not what the design
 * specifies and the time-ordering it loses is the reason v7 was chosen. The
 * version is the first nibble of the third group (offset 14) and must be `7`; the
 * variant is the first nibble of the fourth group (offset 19) and must be one of
 * 8, 9, a or b.
 *
 * Two creations producing different ids covers the crudest failure. That the id
 * derives from no sequence cannot be asserted from outside — an integer sequence
 * would fail the format assertion, which is as close as a test gets; the claim is
 * carried by reading `handle()`.
 */
it('assigns a UUIDv7 game id, different for every game', function () {
    $first = createGame()->handle();
    $second = createGame()->handle();

    foreach ([$first->id, $second->id] as $id) {
        expect($id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', "the game id {$id} is not a UUID")
            ->and($id[14])->toBe('7', "the game id {$id} is not version 7: its version nibble is {$id[14]} (Req 1.2)")
            ->and($id[19])->toBeIn(['8', '9', 'a', 'b'], "the game id {$id} does not carry the RFC 9562 variant bits");
    }

    expect($second->id)->not->toBe($first->id, 'two creations produced the same game id');
});

/*
 * Req 1.3, 1.4: the Join_Code as it reaches the column.
 *
 * The STORED form is asserted to be the ten characters with no hyphen, which is
 * the half of this that has a consequence elsewhere: task 5.4 strips hyphens
 * before its lookup, so an eleven-character stored value could never be matched
 * by a normalised submission. The displayed form is derived from the column by
 * `JoinCode::parse(...)->display()`, which is the route task 5.5 takes to
 * `props.game.joinCode`.
 *
 * `join_code` being non-null is asserted explicitly because the reachability
 * CHECK (`join_code IS NOT NULL OR rematch_of_game_id IS NOT NULL`) depends on it
 * for this insert path — a rematch satisfies that CHECK the other way, a created
 * Game has only this way.
 */
it('assigns a stored Join_Code of ten Crockford characters that displays hyphenated', function () {
    $game = createGame()->handle();

    $stored = DB::table('games')->where('id', $game->id)->value('join_code');

    expect($stored)->toBeString('the Join_Code column is null; the reachability CHECK depends on it (Req 1.4)')
        ->and($stored)->toBe($game->join_code, 'the returned model disagrees with the persisted Join_Code');

    $code = JoinCode::parse((string) $stored);

    expect(strlen((string) $stored))->toBe(10, 'the stored Join_Code must be the ten characters, unhyphenated, or task 5.4 could never match it')
        ->and($stored)->not->toContain('-')
        ->and(strspn((string) $stored, JoinCode::ALPHABET))->toBe(10, 'the stored Join_Code contains a symbol outside Crockford base32')
        ->and($code)->not->toBeNull()
        ->and($code?->display())->toBe(substr((string) $stored, 0, 5).'-'.substr((string) $stored, 5), 'the displayed form is not XXXXX-XXXXX');
});

/*
 * Req 1.4, from the other side: two Games never share a Join_Code.
 *
 * Ten creations rather than two, so the assertion is about the generator and the
 * unique index together rather than about one pair. A collision at 50 bits is
 * around 10^-14 across ten codes, so a repeat here is a defect.
 */
it('assigns a different Join_Code to every game', function () {
    $codes = [];

    for ($index = 0; $index < 10; $index++) {
        $codes[] = createGame()->handle()->join_code;
    }

    expect(count(array_unique($codes)))->toBe(10, 'two Games were created with the same Join_Code (Req 1.4)')
        ->and(DB::table('games')->distinct()->count('join_code'))->toBe(10);
});

/*
 * Req 1.5: the Creator is X, and holds a Player_Token bound to this Game and that
 * Mark.
 *
 * All three halves of "issued" are asserted, because any two of them can hold
 * while the credential is unusable: the hash is on the row, the session holds a
 * raw value for this Game, and the two are the same token — which is what
 * `resolve()` returning `Mark::X` establishes and what a comparison against
 * `hash('sha256', $raw)` establishes independently of `resolve()`.
 *
 * The O slot is asserted empty in the same breath: issuing to the wrong slot
 * would leave a Creator who cannot play and would trip the waiting-state CHECK.
 */
it('assigns the creator the mark X and issues a Player_Token bound to it', function () {
    $tokens = new PlayerTokens;
    $game = (new CreateGame($tokens))->handle();

    $stored = Game::query()->findOrFail($game->id);
    $raw = $tokens->heldFor($game->id);

    expect($raw)->toBeString('the session holds no Player_Token for the created Game (Req 1.5)')
        ->and($stored->x_token_hash)->toBeString('no X token hash was persisted (Req 1.5)')
        ->and($stored->x_token_hash)->toBe(hash('sha256', (string) $raw), 'the persisted hash is not the digest of the token in the session')
        ->and($stored->o_token_hash)->toBeNull('the creator was issued the O slot')
        ->and($tokens->resolve($stored, (string) $raw))->toBe(Mark::X, 'the creator\'s token does not resolve to X (Req 1.5, 3.2)');
});

/*
 * Req 8.7, at the storage layer: the raw Player_Token is in no column of the row.
 *
 * Every column is scanned rather than the two token columns, so a column added
 * later is covered without anyone extending this test. The same assertion exists
 * in `PlayerTokensTest` against a hand-built row; it is repeated here because
 * `CreateGame` is the path a real creation takes, and it is the caller that owns
 * the write.
 */
it('leaves the raw Player_Token in no column of the created row', function () {
    $tokens = new PlayerTokens;
    $game = (new CreateGame($tokens))->handle();

    $raw = (string) $tokens->heldFor($game->id);
    $row = (array) DB::table('games')->where('id', $game->id)->first();

    expect($raw)->not->toBe('', 'no token was issued, so this test asserts nothing')
        ->and($row)->not->toBeEmpty('the created row was not found, so this test asserts nothing');

    // `str_contains` rather than `toContain()`, which takes variadic needles
    // and no message argument — a message passed there is silently asserted as
    // a second needle.
    foreach ($row as $column => $value) {
        expect(str_contains(is_string($value) ? $value : (string) json_encode($value), $raw))
            ->toBeFalse("column {$column} contains the raw Player_Token (Req 8.7)");
    }
});

/*
 * One INSERT, and no SELECT asking whether the Join_Code is free.
 *
 * The absence of that read is the point: checking availability before inserting
 * is a read-then-write race, and Requirement 1.4 is carried by the unique index
 * instead — which is why the task says "uniqueness enforced by the index". The
 * query log is the only way to assert an absence of queries.
 *
 * `insert` appearing exactly once also pins that `PlayerTokens::issue()` did not
 * save: were it to, one logical creation would be an INSERT followed by an UPDATE
 * and the row would exist for an instant with no token.
 */
it('creates the game in a single insert with no availability read', function () {
    DB::enableQueryLog();
    DB::flushQueryLog();

    createGame()->handle();

    $statements = array_map(
        static fn (array $entry): string => strtolower((string) $entry['query']),
        DB::getQueryLog(),
    );

    DB::disableQueryLog();

    $inserts = array_filter($statements, static fn (string $sql): bool => str_starts_with($sql, 'insert'));
    $selects = array_filter($statements, static fn (string $sql): bool => str_starts_with($sql, 'select'));

    expect($inserts)->toHaveCount(1, 'the Game was written by more than one statement: '.implode(' | ', $statements))
        ->and($selects)->toBe([], 'CreateGame issued a read; Join_Code uniqueness is enforced by the index, not by a check-then-insert: '.implode(' | ', $selects));
});
