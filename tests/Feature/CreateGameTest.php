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
use Inertia\Inertia;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

// Feature: remote-tic-tac-toe
//
// Validates: Requirements 1.1, 1.2, 1.3, 1.4, 1.5, 8.7
//
/*
 * `CreateGame`.
 *
 * A Feature test because the subject inserts a row and writes the session.
 * `RefreshDatabase` supplies the schema `DB_DATABASE=:memory:` leaves absent, and
 * `phpunit.xml` sets `SESSION_DRIVER=array` so the session is in-memory and
 * per-test.
 *
 * Excluded here: the Join_Code generator is asserted in `JoinCodeTest`, which needs
 * neither a database nor a session. What is asserted here is the row.
 *
 * Every assertion below runs against a row the schema accepted, and the schema
 * carries seven CHECK constraints — so "it saved" already establishes the values are
 * legal. The assertions are written out anyway because legal is not the same as what
 * Requirement 1 asks for.
 */

uses(RefreshDatabase::class);

/**
 * The subject, with its collaborator supplied explicitly rather than resolved from
 * the container.
 */
function createGame(): CreateGame
{
    return new CreateGame(new PlayerTokens);
}

/*
 * Req 1.1: `waiting_for_opponent` and an empty Move_List.
 *
 * The empty Move_List is the absence of `moves` rows, not a column, so there is
 * nothing for `CreateGame` to initialise. `o_token_hash` being NULL is what "no
 * second player has joined" means (Req 2.1).
 *
 * The row is re-read from the database rather than trusted from the model.
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
 * The version nibble is checked as well as the shape, because every UUID version has
 * the same 8-4-4-4-12 layout and a shape-only assertion would pass for a v4 — losing
 * the time-ordering v7 was chosen for. Offset 14 is the version nibble and must be
 * `7`; offset 19 is the variant and must be one of 8, 9, a or b.
 *
 * That the id derives from no sequence cannot be asserted from outside; the claim is
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
 * The stored form must be ten characters with no hyphen because `JoinGame` strips
 * hyphens before its lookup, so an eleven-character stored value could never be
 * matched by a normalised submission.
 *
 * `join_code` being non-null is asserted explicitly because the reachability CHECK
 * (`join_code IS NOT NULL OR rematch_of_game_id IS NOT NULL`) depends on it for this
 * insert path — a rematch satisfies that CHECK the other way, a created Game has only
 * this way.
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
 * Ten creations rather than two, so the claim covers the generator and the unique
 * index together. A collision at 50 bits is around 10^-14 across ten codes, so a
 * repeat here is a defect rather than bad luck.
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
 * "Issued" is asserted in three halves because any two can hold while the credential
 * is unusable: the hash is on the row, the session holds a raw value for this Game,
 * and the two are the same token — established by `resolve()` and independently by
 * the comparison against `hash('sha256', $raw)`.
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
 * Every column is scanned rather than the two token columns, so a column added later
 * is covered without anyone extending this test. `PlayerTokensTest` makes the same
 * claim against a hand-built row; here it runs against the path a real creation
 * takes, whose caller owns the write.
 */
it('leaves the raw Player_Token in no column of the created row', function () {
    $tokens = new PlayerTokens;
    $game = (new CreateGame($tokens))->handle();

    $raw = (string) $tokens->heldFor($game->id);
    $row = (array) DB::table('games')->where('id', $game->id)->first();

    expect($raw)->not->toBe('', 'no token was issued, so this test asserts nothing')
        ->and($row)->not->toBeEmpty('the created row was not found, so this test asserts nothing');

    // `str_contains` rather than `toContain()`, which takes variadic needles and no
    // message argument, so a message passed there is silently asserted as a needle.
    foreach ($row as $column => $value) {
        expect(str_contains(is_string($value) ? $value : (string) json_encode($value), $raw))
            ->toBeFalse("column {$column} contains the raw Player_Token (Req 8.7)");
    }
});

/*
 * One INSERT, and no SELECT asking whether the Join_Code is free.
 *
 * Checking availability before inserting is a read-then-write race; Requirement 1.4 is
 * carried by the unique index instead. The query log is the only way to assert an
 * absence of queries.
 *
 * `insert` appearing exactly once also pins that `PlayerTokens::issue()` did not save:
 * were it to, one logical creation would be an INSERT followed by an UPDATE and the
 * row would exist for an instant with no token.
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

/*
 * Req 8.7, at the transport: the raw Player_Token reaches no response — not the create
 * response, not the game page's HTML, not the Inertia JSON a poll receives.
 *
 * `CreateGame` itself cannot leak it: it returns a `Game` and nothing more. What this
 * guards is the create path downstream — a controller flashing the token, a
 * `GameRepresentation` field carrying it, an Inertia prop added later.
 *
 * The three surfaces are produced by different code and can disagree. The HTML embeds
 * the props in the root template's `data-page` attribute, so a leaked prop appears
 * there JSON-encoded and HTML-escaped; the `X-Inertia` request re-serialises the same
 * props for a partial reload; the 303 is scanned with its headers, since a flash would
 * travel in the session cookie rather than the body.
 *
 * Non-vacuity: a scan for a 64-character needle passes trivially against an empty
 * body, a 409, or a page carrying no game data, so the token is asserted to be a real
 * 64-hex digest matching the persisted hash and each response is asserted to carry
 * `yourMark` and the Join_Code first.
 */
it('renders the raw Player_Token into no response body: not the redirect, the page HTML, or the Inertia JSON', function () {
    $created = post('/games');

    $game = Game::query()->sole();
    $raw = (string) (new PlayerTokens)->heldFor($game->id);

    // The needle is the credential that was issued: 64 hex characters whose digest is
    // the hash on the row.
    expect($raw)->toMatch('/^[0-9a-f]{64}$/', 'no raw Player_Token is held for the created Game, so the scans below assert nothing')
        ->and($game->x_token_hash)->toBe(hash('sha256', $raw), 'the held token is not the one bound to this Game, so the scans below scan for the wrong needle');

    $page = get('/games/'.$game->id);
    $json = get('/games/'.$game->id, [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) Inertia::getVersion(),
    ]);

    $html = (string) $page->getContent();
    $payload = (string) $json->getContent();
    $display = (string) JoinCode::parse((string) $game->join_code)?->display();

    // Each surface carries this Game's data, ruling out a scan that finds nothing
    // because the response was empty or refused.
    $page->assertOk();
    $json->assertOk();

    expect($display)->not->toBe('')
        ->and(str_contains($html, 'yourMark'))->toBeTrue('the game page HTML carries no props, so scanning it asserts nothing')
        ->and(str_contains($html, $display) || str_contains($html, $game->join_code ?? ''))->toBeTrue('the game page HTML carries no Join_Code, so it is not the Creator\'s page and scanning it asserts nothing')
        ->and(str_contains($payload, 'yourMark'))->toBeTrue('the Inertia JSON carries no game prop, so scanning it asserts nothing');

    // And the secret is in none of them, nor in the redirect that started the path.
    foreach (['the game page HTML' => $html, 'the Inertia JSON' => $payload, 'the create redirect' => (string) $created->getContent()] as $surface => $body) {
        expect(str_contains($body, $raw))->toBeFalse("{$surface} contains the raw Player_Token (Req 8.7)");
    }

    foreach ([$created, $page, $json] as $response) {
        foreach ($response->headers->all() as $header => $values) {
            foreach ($values as $value) {
                expect(str_contains((string) $value, $raw))->toBeFalse("the {$header} response header contains the raw Player_Token (Req 8.7)");
            }
        }
    }
});
