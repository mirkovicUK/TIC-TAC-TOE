<?php

declare(strict_types=1);

use App\Domain\TicTacToe\Mark;
use App\Games\GameState;
use App\Games\JoinCode;
use App\Games\JoinGame;
use App\Games\JoinOutcome;
use App\Games\MintedToken;
use App\Games\PlayerTokens;
use App\Games\ResolvedPlayer;
use App\Models\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

// Feature: remote-tic-tac-toe, Property 13: Joining is exclusive
//
// Validates: Requirements 2.1, 2.2, 2.3, 2.4, 2.5, 2.6, 2.7
//
/*
 * Task 5.4 — `JoinGame`, the conditional UPDATE of ADR-006.
 *
 * A Feature test necessarily: the subject reads a row, writes a row and writes
 * the session. `RefreshDatabase` supplies the schema that `DB_DATABASE=:memory:`
 * otherwise leaves absent, and `phpunit.xml` sets `SESSION_DRIVER=array`, so the
 * session is in-memory and per-test — the arrangement `CreateGameTest`,
 * `PlayerTokensTest` and `GameResolverTest` use.
 *
 * WHAT IS ASSERTED HERE AND WHAT IS ASSERTED ELSEWHERE. This file covers the five
 * paths through `handle()` — accepted, short-circuited, unmatched, unparseable,
 * full — plus the two claims that are about the *mechanism* rather than about a
 * path: that the losing request leaves no credential anywhere, and that the claim
 * is one guarded statement with no read between the lookup and the write.
 * Task 5.8 writes the join race of `ConcurrencyTest`, which is Property 13's
 * exclusivity claim over two sessions in sequence; task 5.7 extends this file
 * rather than creating it, and task 5.6 asserts the transport — the 303 to
 * `/join` and the flashed outcome, neither of which `App\Games` knows about.
 */

uses(RefreshDatabase::class);

/**
 * The subject, with its one collaborator supplied explicitly rather than resolved
 * from the container, so each test states what `JoinGame` depends on. The same
 * `PlayerTokens` instance comes back so a test can read the very session the
 * service wrote.
 *
 * @return array{JoinGame, PlayerTokens}
 */
function joiningServiceAnd(): array
{
    $tokens = new PlayerTokens;

    return [new JoinGame($tokens), $tokens];
}

/**
 * A saved Game waiting for an opponent, with the X slot occupied and NOTHING in
 * the session: the state `CreateGame` leaves behind as seen from a *second*
 * browser.
 *
 * The X token is minted and assigned directly rather than through
 * `PlayerTokens::issue()`, because `issue()` writes the session — which is the
 * creator's session, and would trip the short-circuit in every test that means to
 * be somebody else. The `MintedToken` comes back so a test that *does* mean to be
 * the creator can `remember()` it deliberately.
 *
 * `last_activity_at` is backdated so that an accepted join visibly moves it.
 * Attributes are assigned one by one because mass assignment is closed on this
 * model.
 *
 * @return array{Game, MintedToken}
 */
function joiningWaitingGame(?string $joinCode = null): array
{
    $token = (new PlayerTokens)->mint();

    $game = new Game;
    $game->id = Str::uuid7()->toString();
    $game->join_code = $joinCode ?? JoinCode::generate()->stored;
    $game->state = GameState::WaitingForOpponent;
    $game->version_counter = 0;
    $game->x_token_hash = $token->hash;
    $game->last_activity_at = now()->subMinutes(5);
    $game->save();

    return [$game, $token];
}

/**
 * The four columns an accepted join is allowed to move, read straight from the
 * table rather than through the model, so a stale in-memory instance cannot make
 * an assertion pass.
 *
 * @return array{state: string, o_token_hash: string|null, version_counter: int, last_activity_at: string}
 */
function joiningRowOf(string $gameId): array
{
    $row = (array) DB::table('games')->where('id', $gameId)->first();

    return [
        'state' => (string) $row['state'],
        'o_token_hash' => is_string($row['o_token_hash']) ? $row['o_token_hash'] : null,
        'version_counter' => (int) $row['version_counter'],
        'last_activity_at' => (string) $row['last_activity_at'],
    ];
}

/**
 * Every SQL statement issued while `$work` runs, lower-cased and in order.
 *
 * The query log is the only way to assert both halves of "one statement, no
 * read-then-write": that exactly one UPDATE is issued, and that nothing reads the
 * row between the Join_Code lookup and that UPDATE.
 *
 * @param  callable(): mixed  $work
 * @return list<string>
 */
function joiningStatementsDuring(callable $work): array
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
 * THE ACCEPTED JOIN (Req 2.1, 2.6).
 *
 * All of Requirement 2.1 in one place: the visitor is assigned `O`, holds a
 * Player_Token bound to this Game and that Mark, and the Game is `active`. Plus
 * 2.6's increment, which is asserted as `0 → 1` against the persisted column
 * rather than as "greater than before", since the criterion is an increment of one
 * and `version_counter + 1` is evaluated by the database.
 *
 * The three halves of "issued" are checked the way `CreateGameTest` checks them,
 * because any two can hold while the credential is unusable: the hash is on the
 * row, the session holds a raw value for this Game, and the two are the same
 * token. The last assertion is the `MintedToken` hazard the design names outright
 * — `SET o_token_hash = :hash` with `$token->raw` in it would write the secret
 * into the database (Req 8.7) and no type checker would say a word — so the column
 * is compared against the digest AND against the raw value.
 */
it('assigns O to a joining visitor, activates the game and increments the version counter', function () {
    [$join, $tokens] = joiningServiceAnd();
    [$game] = joiningWaitingGame();

    $before = joiningRowOf($game->id);

    $result = $join->handle(JoinCode::parse((string) $game->join_code)?->display());

    expect($result)->toBeInstanceOf(ResolvedPlayer::class, 'a Join_Code matching a waiting Game was not accepted (Req 2.1)');

    // Narrowed for the analyser as well as the reader; the expectation above is
    // what actually fails if the join was refused.
    if (! $result instanceof ResolvedPlayer) {
        throw new RuntimeException('the join was refused, so the assertions below would say nothing');
    }

    $raw = (string) $tokens->heldFor($game->id);
    $after = joiningRowOf($game->id);

    expect($result->mark)->toBe(Mark::O, 'the joining visitor was not assigned the Mark O (Req 2.1)')
        ->and($result->game->id)->toBe($game->id, 'the returned Game is not the Game the code named')
        ->and($result->game->state)->toBe(GameState::Active, 'the returned model still reports the pre-join state')
        ->and($result->game->version_counter)->toBe(1, 'the returned model still reports the pre-join Version_Counter')
        ->and($after['state'])->toBe(GameState::Active->value, 'the persisted Game_State did not become active (Req 2.1)')
        ->and($after['version_counter'])->toBe(1, 'the Version_Counter was not incremented by exactly one (Req 2.6)')
        ->and($before['version_counter'])->toBe(0)
        ->and($after['last_activity_at'])->toBeGreaterThan($before['last_activity_at'], 'last_activity_at was not moved by the accepted join')
        ->and($raw)->not->toBe('', 'the joining session holds no Player_Token (Req 2.1)')
        ->and($after['o_token_hash'])->toBe(hash('sha256', $raw), 'the persisted O hash is not the digest of the token in the session')
        ->and($after['o_token_hash'])->not->toBe($raw, 'the raw Player_Token was written into o_token_hash (Req 8.7)')
        ->and($tokens->resolve($result->game, $raw))->toBe(Mark::O, 'the issued token does not resolve to O on this Game (Req 2.1, 3.1)')
        ->and($result->game->x_token_hash)->not->toBeNull('the X slot was disturbed by the join');
});

/*
 * THE CREATOR PASTING THEIR OWN JOIN_CODE (Req 2.5, and 2.4 with the Mark
 * happening to be X).
 *
 * The Game comes back with `X`, no second Player is created, and the Game_State
 * and Version_Counter are unchanged — asserted against every column an accepted
 * join would have moved, so "unchanged" is a claim about the row rather than about
 * the two fields the criterion happens to name. The session is compared before and
 * after too: the creator must still hold the *same* token, not a freshly minted
 * one that happens to resolve.
 */
it('returns the creator their own game with the mark X and changes nothing', function () {
    [$join, $tokens] = joiningServiceAnd();
    [$game, $creatorToken] = joiningWaitingGame();

    $tokens->remember($game->id, $creatorToken);

    $before = joiningRowOf($game->id);

    $result = $join->handle((string) $game->join_code);

    expect($result)->toBeInstanceOf(ResolvedPlayer::class, "the Creator's own Join_Code was refused (Req 2.5)");

    if (! $result instanceof ResolvedPlayer) {
        throw new RuntimeException('the short-circuit refused, so the assertions below would say nothing');
    }

    expect($result->mark)->toBe(Mark::X, 'the Creator was not returned the Mark bound to their own token (Req 2.5)')
        ->and($result->game->id)->toBe($game->id)
        ->and(joiningRowOf($game->id))->toBe($before, 'submitting their own Join_Code changed the Creator\'s Game (Req 2.5)')
        ->and(joiningRowOf($game->id)['state'])->toBe(GameState::WaitingForOpponent->value, 'the Game left waiting_for_opponent without a second Player')
        ->and(joiningRowOf($game->id)['o_token_hash'])->toBeNull('a second Player was created for the Creator\'s own session (Req 2.4)')
        ->and($tokens->heldFor($game->id))->toBe($creatorToken->raw, 'the Creator\'s Player_Token was replaced');
});

/*
 * A PLAYER RE-SUBMITTING THE CODE OF A GAME THEY ARE ALREADY IN (Req 2.4).
 *
 * The same short-circuit as the Creator's, reached with `O` instead of `X`, on a
 * Game that is already `active` and therefore already has two Players — which is
 * exactly the state Requirement 2.3 answers `game_full` for, but only for a
 * session holding no token. So this is the pair of tests that shows the branch is
 * decided by the session and not by the state: identical row, opposite answers.
 *
 * The join is performed by the subject rather than fabricated, so the token being
 * short-circuited on is one the subject itself issued.
 */
it('returns a joined player their game with the bound mark O without creating a third player', function () {
    [$join, $tokens] = joiningServiceAnd();
    [$game] = joiningWaitingGame();

    $join->handle((string) $game->join_code);

    $held = (string) $tokens->heldFor($game->id);
    $before = joiningRowOf($game->id);

    $again = $join->handle((string) $game->join_code);

    expect($again)->toBeInstanceOf(ResolvedPlayer::class, 'a Player of the Game was refused their own Game (Req 2.4)')
        ->and($again instanceof ResolvedPlayer ? $again->mark : null)->toBe(Mark::O, 'the second submission did not return the Mark bound to the presented token (Req 2.4)')
        ->and(joiningRowOf($game->id))->toBe($before, 'a repeated submission by a Player changed the Game (Req 2.4)')
        ->and($tokens->heldFor($game->id))->toBe($held, 'a repeated submission replaced the Player_Token');
});

/*
 * A CODE MATCHING NO GAME (Req 2.2).
 *
 * The code is well formed — ten Crockford characters from `generate()` — and
 * simply belongs to no row. A waiting Game with a *different* code exists, so the
 * rejection is not the trivial one of an empty table.
 */
it('rejects a well formed join code that matches no game as not_recognised', function () {
    [$join] = joiningServiceAnd();
    [$game] = joiningWaitingGame();

    $unmatched = JoinCode::generate();

    expect($unmatched->stored)->not->toBe($game->join_code, 'the fixture generated the Game\'s own code, so this asserts nothing')
        ->and($join->handle($unmatched->display()))->toBe(
            JoinOutcome::NotRecognised,
            'a Join_Code matching no Game was not rejected as not_recognised (Req 2.2)',
        );
});

/*
 * AN UNPARSEABLE CODE IS THE SAME ANSWER AS AN UNMATCHED ONE (Req 2.2).
 *
 * Asserted as an EQUIVALENCE against the unmatched case rather than as a list of
 * expectations naming one constant, so an edit that gave malformed input its own
 * outcome fails here even if whoever made it updated this test's expected value.
 * That indistinguishability is the point: a distinguishable "wrong shape" reply
 * would tell a prober which strings are worth trying.
 *
 * The inputs cover every way `JoinCode::parse()` can answer null and one way it is
 * never reached — too short, too long, a `U` (which Crockford excludes outright
 * and so cannot be folded), punctuation, empty, and a non-string, since a JSON
 * body may carry anything and the design keeps one vocabulary for one condition.
 */
it('rejects an unparseable or non-string join code exactly as it rejects an unmatched one', function () {
    [$join] = joiningServiceAnd();
    joiningWaitingGame();

    $unmatched = $join->handle(JoinCode::generate()->display());

    $unparseable = [
        'too short' => '4K7P2',
        'too long' => '4K7P29QZR3X',
        'excluded symbol U' => '4K7P29QZRU',
        'punctuation' => '4K7P2/9QZR',
        'empty' => '',
        'whitespace only' => '   ',
        'non-string array' => ['4K7P29QZR3'],
        'non-string integer' => 1234567890,
        'null' => null,
    ];

    expect($unmatched)->toBe(JoinOutcome::NotRecognised);

    foreach ($unparseable as $description => $input) {
        $outcome = $join->handle($input);

        expect($outcome)->toBe($unmatched, "a {$description} join code is distinguishable from an unmatched one (Req 2.2)")
            ->and(json_encode($outcome))->toBe(json_encode($unmatched), "the outcome for a {$description} join code serialises differently, so the difference would reach the client");
    }
});

/*
 * NORMALISATION BEFORE LOOKUP.
 *
 * One submission exercising all four transformations at once — surrounding
 * whitespace, lower case, a hyphen, and both Crockford folds — against a stored
 * code chosen to contain a `1` and a `0` so that `I`/`L` → `1` and `O` → `0` have
 * something to fold *to*. `generate()` cannot be made to emit those digits on
 * demand, which is why the fixture names the code.
 *
 * The transformation itself belongs to `JoinCode::parse()` and is asserted
 * exhaustively in `JoinCodeTest`; what this asserts is that `JoinGame` normalises
 * *before* the lookup, which is only observable through a join succeeding.
 */
it('normalises a submitted code before looking it up', function () {
    [$join] = joiningServiceAnd();
    [$game] = joiningWaitingGame('10ABCDEFGH');

    $result = $join->handle('  ioabc-defgh  ');

    expect($result)->toBeInstanceOf(ResolvedPlayer::class, 'a transcribed Join_Code was not normalised before lookup')
        ->and($result instanceof ResolvedPlayer ? $result->mark : null)->toBe(Mark::O)
        ->and($result instanceof ResolvedPlayer ? $result->game->id : null)->toBe($game->id, 'the normalised code found the wrong Game');
});

/*
 * A GAME THAT ALREADY HAS TWO PLAYERS (Req 2.3).
 *
 * The Game is joined by the subject first, so the row reaches its two-Player state
 * the way a real one does, and the session is then flushed so the caller is a third
 * party holding no token — which is the precise condition Requirement 2.3 states.
 * The row is asserted unchanged across the refusal, so `game_full` is a rejection
 * rather than a write that happened to be reported.
 */
it('rejects a third party for a game that already has two players as game_full', function () {
    [$join, $tokens] = joiningServiceAnd();
    [$game] = joiningWaitingGame();

    $join->handle((string) $game->join_code);

    $joined = joiningRowOf($game->id);

    Session::flush();

    $outcome = $join->handle((string) $game->join_code);

    expect($tokens->heldFor($game->id))->toBeNull('the third party still holds a token, so this is not Requirement 2.3\'s condition')
        ->and($outcome)->toBe(JoinOutcome::GameFull, 'a Game with two Players did not answer game_full to a third party (Req 2.3)')
        ->and(joiningRowOf($game->id))->toBe($joined, 'the refused join changed the Game');
});

/*
 * THE LOSING REQUEST LEAVES NO CREDENTIAL ANYWHERE — the "no orphan credential"
 * claim, asserted from both ends.
 *
 * A second join against a row whose O slot is taken must leave the session holding
 * nothing for that Game_Id, and must leave the persisted hash as the *winner's*.
 * The winner's token is checked to still resolve afterwards, because the failure
 * this guards against is not an untidy session — it is an unguarded second write
 * overwriting `o_token_hash` and locking the real O Player out of their own Game,
 * unrecoverably.
 *
 * The absence is asserted on the raw session store as well as through
 * `heldFor()`, so a key written with an empty or malformed value — which
 * `heldFor()` reports as null by design — cannot pass this.
 */
it('leaves no player token in the session or the row for a losing join', function () {
    [$join, $tokens] = joiningServiceAnd();
    [$game] = joiningWaitingGame();

    $join->handle((string) $game->join_code);

    $winner = (string) $tokens->heldFor($game->id);
    $claimed = joiningRowOf($game->id);

    Session::flush();

    $outcome = $join->handle((string) $game->join_code);

    $stored = Game::query()->findOrFail($game->id);

    expect($outcome)->toBe(JoinOutcome::GameFull)
        ->and($tokens->heldFor($game->id))->toBeNull('the losing request left a Player_Token in the session')
        ->and(Session::has('player_tokens.'.$game->id))->toBeFalse('the losing request wrote a session key for the Game it did not join')
        ->and(Session::all())->toBe([], 'the losing request wrote something to the session')
        ->and(joiningRowOf($game->id))->toBe($claimed, 'the losing request wrote to the row')
        ->and($tokens->resolve($stored, $winner))->toBe(Mark::O, 'the losing request replaced the winner\'s Player_Token, locking the O Player out of their own Game');
});

/*
 * THE GUARD ACTUALLY GUARDS — observed through the affected-row count, not
 * asserted as intent.
 *
 * The same guarded statement `JoinGame` issues is run a second time by hand
 * against the claimed row, and its return value is read: SQLite reports zero rows
 * affected, which is the entire mechanism by which the loser is told `game_full`
 * (Req 2.7, ADR-006). The state and the Version_Counter are confirmed not to move,
 * so the zero is a genuine no-op rather than an idempotent rewrite.
 *
 * This is deliberately the low-level half of the claim. The behavioural half —
 * two sessions in sequence, A gets `O` and B gets `game_full` — is task 5.8's
 * `ConcurrencyTest`, and Property 13 belongs there.
 */
it('affects zero rows and moves nothing when the guarded update runs against a claimed slot', function () {
    [$join, $tokens] = joiningServiceAnd();
    [$game] = joiningWaitingGame();

    $join->handle((string) $game->join_code);

    $claimed = joiningRowOf($game->id);

    $second = (new PlayerTokens)->mint();

    $affected = Game::query()
        ->whereKey($game->id)
        ->where('state', GameState::WaitingForOpponent->value)
        ->whereNull('o_token_hash')
        ->update([
            'state' => GameState::Active->value,
            'o_token_hash' => $second->hash,
            'version_counter' => DB::raw('version_counter + 1'),
            'last_activity_at' => now()->addMinute(),
        ]);

    expect($claimed['version_counter'])->toBe(1, 'the first join did not increment the Version_Counter, so this test asserts nothing')
        ->and($affected)->toBe(0, 'the guarded UPDATE claimed a slot that was already taken; the affected-row count is the whole of Requirement 2.7')
        ->and(joiningRowOf($game->id))->toBe($claimed, 'the losing UPDATE moved the row')
        ->and($tokens->resolve(Game::query()->findOrFail($game->id), $second->raw))->toBeNull('the losing UPDATE bound its token to the Game');
});

/*
 * ONE STATEMENT, AND NO READ BETWEEN THE LOOKUP AND THE WRITE.
 *
 * The absence of that read is the point. A `SELECT` of `state` after the Join_Code
 * lookup and before the UPDATE would be a re-check in PHP, which reintroduces
 * exactly the read-then-write window the guarded statement exists to close — and
 * it would be invisible in behaviour, because both shapes pass this suite's happy
 * path. The query log is the only way to assert an absence of queries.
 *
 * Three assertions: the first statement is the Join_Code lookup, the second is the
 * UPDATE, and exactly one UPDATE is issued in the whole call. The SELECT that
 * follows the UPDATE is the deliberate re-read of the claimed row, so it is
 * asserted to come *after* the write rather than asserted away.
 */
it('claims the slot in one guarded update with no read between the lookup and the write', function () {
    [$join] = joiningServiceAnd();
    [$game] = joiningWaitingGame();

    $statements = joiningStatementsDuring(fn () => $join->handle((string) $game->join_code));

    $updates = array_values(array_filter($statements, static fn (string $sql): bool => str_starts_with($sql, 'update')));
    $updateAt = array_search(true, array_map(static fn (string $sql): bool => str_starts_with($sql, 'update'), $statements), true);

    $trace = implode(' | ', $statements);

    expect($statements)->not->toBe([], 'no statements were logged, so this test asserts nothing')
        ->and($statements[0])->toStartWith('select', "the first statement is not the Join_Code lookup: {$trace}")
        ->and(str_contains($statements[0], 'join_code'))->toBeTrue("the first statement does not look the Game up by Join_Code: {$trace}")
        ->and($updates)->toHaveCount(1, "the claim was not a single UPDATE: {$trace}")
        ->and($updateAt)->toBe(1, "a statement ran between the Join_Code lookup and the guarded UPDATE, which is the read-then-write the single statement exists to avoid: {$trace}");

    // `toContain()` takes variadic needles and no message, so the shape of the
    // one UPDATE is checked as named booleans instead — each failure then says
    // which half of the statement is missing rather than printing the SQL twice.
    $fragments = [
        'sets the state' => '"state" = ?',
        'sets the O token hash' => '"o_token_hash" = ?',
        'increments the Version_Counter by an expression the database evaluates (Req 2.6)' => 'version_counter + 1',
        'sets last_activity_at' => '"last_activity_at" = ?',
        'is guarded on the O slot being free' => '"o_token_hash" is null',
    ];

    foreach ($fragments as $claim => $fragment) {
        expect(str_contains($updates[0], $fragment))->toBeTrue("the guarded UPDATE neither {$claim}: {$trace}");
    }

    // The state appears twice — once in SET, once in WHERE — which is what
    // distinguishes a guarded claim from an unconditional write.
    expect(substr_count($updates[0], '"state" = ?'))->toBe(2, "the UPDATE does not both set and guard on the Game_State: {$trace}");
});

/*
 * THE SHORT-CIRCUIT ISSUES NO WRITE AT ALL.
 *
 * The companion to the test above, and the query-log half of Requirement 2.5's
 * "leave the Game_State unchanged": not merely that the columns end up where they
 * started, but that no UPDATE was attempted — so a future edit that claimed the
 * slot and then restored it would fail here rather than pass on the strength of
 * the row looking untouched afterwards.
 */
it('issues no write when a session already holds a token for the game', function () {
    [$join, $tokens] = joiningServiceAnd();
    [$game, $creatorToken] = joiningWaitingGame();

    $tokens->remember($game->id, $creatorToken);

    $statements = joiningStatementsDuring(fn () => $join->handle((string) $game->join_code));

    $writes = array_values(array_filter(
        $statements,
        static fn (string $sql): bool => str_starts_with($sql, 'update') || str_starts_with($sql, 'insert') || str_starts_with($sql, 'delete'),
    ));

    expect($writes)->toBe([], 'the short-circuit wrote to the database (Req 2.4, 2.5): '.implode(' | ', $writes))
        ->and($statements)->toHaveCount(1, 'the short-circuit issued more than the Join_Code lookup: '.implode(' | ', $statements));
});
