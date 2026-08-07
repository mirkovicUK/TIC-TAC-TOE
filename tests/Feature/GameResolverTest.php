<?php

declare(strict_types=1);

use App\Domain\TicTacToe\Mark;
use App\Games\GameResolver;
use App\Games\GameState;
use App\Games\JoinCode;
use App\Games\MintedToken;
use App\Games\PlayerTokens;
use App\Games\ResolvedPlayer;
use App\Games\VisibilityOutcome;
use App\Models\ExpiryRecord;
use App\Models\Game;
use App\Models\Move;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

// Feature: remote-tic-tac-toe, Property 7: Authorisation precedes validity and denies all visibility
//
// Validates: Requirements 3.3, 3.4, 3.9, 3.10, 9.6, 13.6, 13.7, 13.8
//
/*
 * Task 5.3 — `GameResolver`, the seven-row visibility table.
 *
 * A Feature test necessarily: the subject reads the session and two tables.
 * `RefreshDatabase` supplies the schema `DB_DATABASE=:memory:` otherwise leaves
 * absent, and `phpunit.xml` sets `SESSION_DRIVER=array`, so the session is
 * in-memory and per-test — the arrangement `PlayerTokensTest` and
 * `CreateGameTest` use.
 *
 * EACH OF THE SEVEN ROWS HAS ITS OWN TEST, NAMED FOR ITS ROW, so a failure names
 * the line of the design it contradicts. Four further tests carry the claims the
 * seven cannot, because each of those is a claim about a *relationship between*
 * two rows rather than about either one:
 *
 *   - rows 6 and 7 compared to EACH OTHER, so that an edit giving row 6 its own
 *     answer fails even if someone updated row 6's own expectation to match;
 *   - the three `not_authorised` modes of Requirement 9.6 compared to each other
 *     the same way;
 *   - rows 3 and 6 on IDENTICAL database state, which is the asymmetry the whole
 *     design rests on: the tombstone answers the player who was there and stays
 *     invisible to everyone else;
 *   - the structural claim that a rejection carries no game state at all.
 *
 * WHAT IS ASSERTED HERE AND WHAT IS ASSERTED ELSEWHERE. This file exercises the
 * resolver directly, so it can assert the outcome *values* and the queries issued.
 * `ResolveActingPlayerTest` asserts the same table through the request pipeline —
 * the statuses, the short-circuit, and that no game data reaches a response body.
 * Property 7's full sweep of every Game_State against every route naming a
 * Game_Id is task 12.2's `VisibilityTest`, once the routes of 5.6 and 6.2 exist.
 */

uses(RefreshDatabase::class);

/**
 * The subject, with its one collaborator supplied explicitly rather than resolved
 * from the container, so each test states what `GameResolver` depends on. The same
 * `PlayerTokens` instance is handed back so a test can mint against the very
 * session the resolver will read.
 *
 * @return array{GameResolver, PlayerTokens}
 */
function resolverAnd(): array
{
    $tokens = new PlayerTokens;

    return [new GameResolver($tokens), $tokens];
}

/**
 * A saved `games` row. Fixture, not the subject.
 *
 * `active` by default so that either token slot may be populated: the schema's
 * one-directional CHECK forbids an occupied O slot while a Game waits for an
 * opponent. `won` is given a `winning_mark`, which the paired CHECK requires, and
 * the other three states are given none, which it equally requires. A generated
 * `join_code` because `join_code IS NOT NULL OR rematch_of_game_id IS NOT NULL`
 * keeps every Game reachable; generated rather than fixed so a test may create
 * several without colliding on the unique index. Attributes are assigned one by
 * one because mass assignment is closed on this model.
 */
function resolverGame(GameState $state = GameState::Active): Game
{
    $game = new Game;
    $game->id = Str::uuid7()->toString();
    $game->join_code = JoinCode::generate()->stored;
    $game->state = $state;
    $game->winning_mark = $state === GameState::Won ? Mark::X : null;
    $game->version_counter = 0;
    $game->last_activity_at = now();
    $game->save();

    return $game;
}

/**
 * A tombstone for a Game_Id, with no `games` row anywhere — which is the state
 * the sweep leaves behind (Req 13.3) and the only state rows 3 and 6 are about.
 *
 * There is no relationship from `ExpiryRecord` to `Game` and no foreign key on
 * `game_id`, precisely so this is expressible; see the migration.
 */
function resolverTombstone(): string
{
    $gameId = Str::uuid7()->toString();

    $record = new ExpiryRecord;
    $record->game_id = $gameId;
    $record->deleted_at = now();
    $record->save();

    return $gameId;
}

/**
 * Puts a raw Player_Token in the session for `$gameId` that is bound to nothing:
 * 64 hex characters, minted properly, whose hash was never written to any row.
 *
 * This is failure mode two of Requirement 9.6 — an unrecognised token — and it is
 * also how rows 3 and 4 are reached, since "the session holds a token for this id"
 * is all those rows require and there is no row left to hold a matching hash.
 */
function resolverHoldUnboundToken(PlayerTokens $tokens, string $gameId): MintedToken
{
    $token = $tokens->mint();
    $tokens->remember($gameId, $token);

    return $token;
}

/**
 * The types through which a Board, a Move_List, a Game_State or a Mark_To_Move
 * could be reached, as fully qualified names.
 *
 * Compared exactly rather than by substring: `App\Games` contains the word "Game",
 * so a substring test would flag the outcome type itself and would pass only by
 * being wrong twice.
 *
 * @return list<string>
 */
function resolverTypesAGameHidesIn(): array
{
    return [
        Game::class,
        Move::class,
        Mark::class,
        GameState::class,
        ResolvedPlayer::class,
        'App\Games\GameSnapshot',
        'App\Domain\TicTacToe\Board',
        'App\Domain\TicTacToe\MoveList',
        'App\Domain\TicTacToe\Analysis',
    ];
}

/**
 * Every SQL statement issued while `$work` runs, lower-cased.
 *
 * The query log is the only way to assert that a table was NOT read, which is
 * what "do not consult the Expiry_Record unless it is needed" comes down to.
 *
 * @param  callable(): mixed  $work
 * @return list<string>
 */
function resolverStatementsDuring(callable $work): array
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
 * ROW 1 — session holds the token for the id, the row exists, the hash matches:
 * the acting player is resolved.
 *
 * Asserted for BOTH marks on one Game, because a resolver that returned a
 * constant `Mark::X` would satisfy the X half and every other test in this file.
 * The Mark comes from `PlayerTokens::resolve()` and from nowhere else (Req 3.2),
 * and the row that comes back is the row that was asked for.
 *
 * The Move_List is asserted NOT loaded. That is not a performance nicety: it is
 * the design decision that `ResolvedPlayer` carries a `Game` rather than a
 * `GameSnapshot`, so that authorisation costs no Move_List read on the polling
 * path and cannot raise `CorruptMoveListException` ahead of the authorisation
 * answer.
 */
it('row 1: resolves the acting player when the session holds the token for that game', function () {
    [$resolver, $tokens] = resolverAnd();
    $game = resolverGame();

    $tokens->issue($game, Mark::X);
    $game->save();
    $rawX = (string) $tokens->heldFor($game->id);

    $resolved = $resolver->resolve($game->id);

    expect($resolved)->toBeInstanceOf(ResolvedPlayer::class, 'a session holding the X token for this Game was not resolved as its player (row 1)');

    // Narrowed for the analyser as well as the reader; the expectation above is
    // what actually fails if resolution refused.
    if (! $resolved instanceof ResolvedPlayer) {
        throw new RuntimeException('row 1 did not resolve, so the assertions below would say nothing');
    }

    expect($resolved->mark)->toBe(Mark::X, 'the resolved Mark is not the one the presented token is bound to (Req 3.2)')
        ->and($resolved->game->id)->toBe($game->id, 'the resolved Game is not the Game that was asked for')
        ->and($resolved->game->relationLoaded('moves'))->toBeFalse('resolution loaded the Move_List; authorisation is a question about two columns of one row');

    // The other slot of the same row, so "bound to a Mark" is distinguished from
    // "bound to a row".
    Session::flush();

    $tokens->issue($game, Mark::O);
    $game->save();
    $rawO = (string) $tokens->heldFor($game->id);

    $resolvedO = $resolver->resolve($game->id);

    expect($rawO)->not->toBe($rawX)
        ->and($resolvedO)->toBeInstanceOf(ResolvedPlayer::class)
        ->and($resolvedO instanceof ResolvedPlayer ? $resolvedO->mark : null)
        ->toBe(Mark::O, 'the O token did not resolve to O (Req 3.2)');
});

/*
 * ROW 2 — session holds a token for the id, the row exists, the hash matches
 * neither slot: `not_authorised`.
 *
 * This is failure mode two of Requirement 9.6 reaching a live Game. The token is
 * a real 256-bit token, correctly shaped and correctly remembered; the only thing
 * wrong with it is that its digest is on no slot of this row. A resolver that
 * checked the *shape* of what the session holds instead of comparing hashes would
 * pass row 1 and fail here.
 */
it('row 2: rejects a session whose token matches neither slot of the row as not_authorised', function () {
    [$resolver, $tokens] = resolverAnd();
    $game = resolverGame();

    $tokens->issue($game, Mark::X);
    $game->save();

    // Overwrite the session entry for this Game with a token bound to nothing.
    resolverHoldUnboundToken($tokens, $game->id);

    expect($resolver->resolve($game->id))->toBe(
        VisibilityOutcome::NotAuthorised,
        'a token whose hash is on neither slot of this row was not rejected as not_authorised (row 2, Req 9.6)',
    );
});

/*
 * ROW 3 — session holds a token for the id, no row, a tombstone: `game_expired`.
 *
 * Requirement 13.6, and the reason `PlayerTokens::heldFor()` takes a Game_Id
 * string rather than a `Game`: there is no row here to pass. Note what "a valid
 * Player_Token" can possibly mean on this row and what it cannot — the row that
 * held the hash has been deleted, so no comparison is available and no comparison
 * could be. What the design's table asks for, and what is asserted, is that the
 * SESSION HOLDS A TOKEN FOR THIS ID. That is the strongest test available once
 * the Game is gone, and it is the fact that separates this row from row 6.
 */
it('row 3: reports game_expired for a session holding a token for an id with a tombstone and no row', function () {
    [$resolver, $tokens] = resolverAnd();
    $gameId = resolverTombstone();

    resolverHoldUnboundToken($tokens, $gameId);

    expect(Game::query()->whereKey($gameId)->exists())->toBeFalse('the fixture left a games row, so this is not row 3')
        ->and(ExpiryRecord::query()->whereKey($gameId)->exists())->toBeTrue('the fixture wrote no tombstone, so this is not row 3')
        ->and($resolver->resolve($gameId))->toBe(
            VisibilityOutcome::GameExpired,
            'a session holding a token for a swept Game was not told the Game expired (row 3, Req 13.6)',
        );
});

/*
 * ROW 4 — session holds a token for the id, no row, no tombstone:
 * `not_recognised`.
 *
 * The state a rolled-back creation leaves: the session key names a Game that was
 * never persisted. Requirement 13.8, and the case `CreateGame` and
 * `PlayerTokens::issue()` both cite as the reason the session may safely be
 * written before the row is saved — a stale session key authorises nothing.
 */
it('row 4: reports not_recognised for a session holding a token for an id with no row and no tombstone', function () {
    [$resolver, $tokens] = resolverAnd();
    $gameId = Str::uuid7()->toString();

    resolverHoldUnboundToken($tokens, $gameId);

    expect(ExpiryRecord::query()->whereKey($gameId)->exists())->toBeFalse('the fixture wrote a tombstone, so this is not row 4')
        ->and($resolver->resolve($gameId))->toBe(
            VisibilityOutcome::NotRecognised,
            'a token held for an id that was never a Game was not answered not_recognised (row 4, Req 13.8)',
        );
});

/*
 * ROW 5 — no token, the row exists: `not_authorised`.
 *
 * Requirements 3.10 and 9.6, and the row a correctly guessed Game_Id reaches. It
 * is also the row that makes a Game_Id not a credential: knowing one buys the
 * knowledge that it is a Game and nothing else, because authorisation comes from
 * the Player_Token and nothing else.
 *
 * A Move is inserted first, so the Game has a Move_List and a Board to withhold
 * and the refusal is not vacuous.
 */
it('row 5: rejects a tokenless session for an existing game as not_authorised', function () {
    [$resolver, $tokens] = resolverAnd();
    $game = resolverGame();

    $tokens->issue($game, Mark::X);
    $game->save();

    $move = new Move;
    $move->game_id = $game->id;
    $move->cell_index = 4;
    $move->sequence_index = 0;
    $move->save();

    // The session holds nothing at all for anything.
    Session::flush();

    expect($tokens->heldFor($game->id))->toBeNull('the session still holds a token, so this is not row 5')
        ->and($game->moves()->count())->toBe(1, 'the Game has no Move_List to withhold, so the refusal is vacuous')
        ->and($resolver->resolve($game->id))->toBe(
            VisibilityOutcome::NotAuthorised,
            'a tokenless request for an existing Game was not rejected as not_authorised (row 5, Req 3.10, 9.6)',
        );
});

/*
 * ROW 6 — no token, no row, a tombstone: `not_recognised`.
 *
 * The row the requirements leave unconstrained and the design chooses: 13.8
 * mandates row 7 and 13.6 grants `game_expired` only to a session presenting a
 * token, which is row 3, so row 6 is free — and answering it as row 7 is what
 * keeps the tombstone from becoming an oracle for which Game_Ids ever existed.
 */
it('row 6: reports not_recognised for a tokenless session on an id with a tombstone and no row', function () {
    [$resolver] = resolverAnd();
    $gameId = resolverTombstone();

    expect(ExpiryRecord::query()->whereKey($gameId)->exists())->toBeTrue('the fixture wrote no tombstone, so this is not row 6')
        ->and($resolver->resolve($gameId))->toBe(
            VisibilityOutcome::NotRecognised,
            'a tokenless request for a swept Game disclosed that it had existed (row 6)',
        );
});

/*
 * ROW 7 — no token, no row, no tombstone: `not_recognised`. Requirement 13.8
 * directly. An id that never was.
 */
it('row 7: reports not_recognised for a tokenless session on an id that was never a game', function () {
    [$resolver] = resolverAnd();
    $gameId = Str::uuid7()->toString();

    expect(Game::query()->whereKey($gameId)->exists())->toBeFalse()
        ->and(ExpiryRecord::query()->whereKey($gameId)->exists())->toBeFalse()
        ->and($resolver->resolve($gameId))->toBe(
            VisibilityOutcome::NotRecognised,
            'an id that was never a Game was not answered not_recognised (row 7, Req 13.8)',
        );
});

/*
 * ROWS 6 AND 7 ARE INDISTINGUISHABLE — asserted as an EQUIVALENCE, not as two
 * expectations that happen to name the same value.
 *
 * The two rejections are compared to each other, so an edit that gave row 6
 * `game_expired` fails here even if whoever made it updated row 6's own test to
 * match. Both directions of the comparison and both serialised forms are checked,
 * because "indistinguishable" is a claim about everything a caller could observe,
 * not just about `===`: same case, same backing value, same JSON.
 *
 * The query log carries the other half of it. A tokenless caller's answer is
 * produced without `expiry_records` being read at all, so the two rows are not
 * merely reported alike — the fact that separates them is never looked up. That
 * also means the two rows issue the same number of queries, which is as close to
 * "not distinguishable by timing" as an assertion can honestly get.
 */
it('rows 6 and 7: a tokenless caller cannot distinguish a swept game from an id that never was', function () {
    [$resolver] = resolverAnd();

    $swept = resolverTombstone();
    $neverWas = Str::uuid7()->toString();

    $sweptOutcome = null;
    $neverWasOutcome = null;

    $sweptStatements = resolverStatementsDuring(function () use ($resolver, $swept, &$sweptOutcome): void {
        $sweptOutcome = $resolver->resolve($swept);
    });

    $neverWasStatements = resolverStatementsDuring(function () use ($resolver, $neverWas, &$neverWasOutcome): void {
        $neverWasOutcome = $resolver->resolve($neverWas);
    });

    expect($sweptOutcome)->toBe($neverWasOutcome, 'row 6 and row 7 answered differently: a tokenless caller can tell that a Game_Id once existed')
        ->and($neverWasOutcome)->toBe($sweptOutcome)
        ->and(json_encode($sweptOutcome))->toBe(json_encode($neverWasOutcome), 'the two rejections serialise differently, so the difference would reach the client')
        ->and($sweptOutcome)->toBe(VisibilityOutcome::NotRecognised);

    $touchedExpiry = array_values(array_filter(
        [...$sweptStatements, ...$neverWasStatements],
        static fn (string $sql): bool => str_contains($sql, 'expiry_records'),
    ));

    expect($touchedExpiry)->toBe([], 'the tombstone was read for a tokenless caller; rows 6 and 7 must be answered without consulting it: '.implode(' | ', $touchedExpiry))
        ->and(count($sweptStatements))->toBe(count($neverWasStatements), 'the two rows issued different numbers of queries: '.implode(' | ', $sweptStatements).' vs '.implode(' | ', $neverWasStatements));
});

/*
 * ROW 3 VERSUS ROW 6, ON IDENTICAL DATABASE STATE — the asymmetry the design
 * rests on.
 *
 * One Game_Id, one tombstone, no `games` row, and the *same* two tables in the
 * *same* state for both calls. The only thing that changes between them is whether
 * the session holds a token for that id. The Game_Id is the same string, so this
 * cannot pass by accident of two different fixtures.
 *
 * That is the whole security argument for keeping tombstones: the player who was
 * there is told their Game is gone (Req 13.6), and to everyone else the id is
 * indistinguishable from one that never existed (Req 13.8).
 */
it('rows 3 and 6: the same missing game and tombstone answer differently only by whether a token is held', function () {
    [$resolver, $tokens] = resolverAnd();
    $gameId = resolverTombstone();

    $databaseState = static fn (): array => [
        'games' => DB::table('games')->where('id', $gameId)->count(),
        'tombstones' => DB::table('expiry_records')->where('game_id', $gameId)->count(),
    ];

    $before = $databaseState();

    resolverHoldUnboundToken($tokens, $gameId);

    $withToken = $resolver->resolve($gameId);

    $between = $databaseState();

    Session::flush();

    $withoutToken = $resolver->resolve($gameId);

    expect($before)->toBe(['games' => 0, 'tombstones' => 1], 'the fixture is not the state rows 3 and 6 share')
        ->and($between)->toBe($before, 'resolution changed the database, so the two calls did not see identical state')
        ->and($databaseState())->toBe($before, 'resolution changed the database, so the two calls did not see identical state')
        ->and($withToken)->toBe(VisibilityOutcome::GameExpired, 'the player who was there was not told the Game expired (row 3, Req 13.6)')
        ->and($withoutToken)->toBe(VisibilityOutcome::NotRecognised, 'a caller holding no token was told the Game had expired, which discloses that the id once existed (row 6)')
        ->and($withoutToken)->not->toBe($withToken, 'the tombstone answered both callers alike, so Requirement 13.6 is not satisfied for the player who was there');
});

/*
 * THE THREE `not_authorised` MODES OF REQUIREMENT 9.6, MUTUALLY
 * INDISTINGUISHABLE — again asserted as an equivalence between the three, not as
 * three expectations of one constant.
 *
 * The modes are the ones the requirement lists: no token at all; a token that is
 * unrecognised; and a token bound to a different Game_Id, built the way a real one
 * would arise — minted, its hash stored on Game B's row, and held in the session
 * under Game A's key, which is what a player of B pointing a request at A looks
 * like.
 *
 * All three pairs are compared, so no single mode is privileged as the expected
 * value, and the serialised forms are compared too, since it is the serialised
 * form that would reach the Web_Client and break Requirement 9.6's single
 * indication.
 */
it('reports not_authorised identically for an absent, an unrecognised and an elsewhere-bound token', function () {
    [$resolver, $tokens] = resolverAnd();

    $target = resolverGame();
    $tokens->issue($target, Mark::X);
    $target->save();

    $other = resolverGame();

    // Mode 1: no token at all.
    Session::flush();
    $absent = $resolver->resolve($target->id);

    // Mode 2: a well-formed token bound to nothing.
    Session::flush();
    resolverHoldUnboundToken($tokens, $target->id);
    $unrecognised = $resolver->resolve($target->id);

    // Mode 3: a token genuinely bound to the OTHER Game, presented for this one.
    Session::flush();
    $elsewhere = $tokens->mint();
    $other->x_token_hash = $elsewhere->hash;
    $other->save();
    $tokens->remember($target->id, $elsewhere);

    $boundElsewhere = $resolver->resolve($target->id);

    expect($tokens->resolve($other, $elsewhere->raw))->toBe(Mark::X, "mode 3's token is not actually bound to the other Game, so the mode is not what it claims")
        ->and($absent)->toBe(VisibilityOutcome::NotAuthorised)
        ->and($unrecognised)->toBe($absent, 'an unrecognised token is distinguishable from no token (Req 9.6)')
        ->and($boundElsewhere)->toBe($absent, 'a token bound to another Game is distinguishable from no token (Req 3.4, 9.6)')
        ->and($boundElsewhere)->toBe($unrecognised, 'a token bound to another Game is distinguishable from an unrecognised token (Req 9.6)')
        ->and(json_encode($unrecognised))->toBe(json_encode($absent))
        ->and(json_encode($boundElsewhere))->toBe(json_encode($absent));
});

/*
 * REQUIREMENT 3.9 AT THE RESOLVER — authorisation is settled before any
 * lifecycle or validity condition is evaluated, and is the only outcome reported.
 *
 * The assertion is possible without `SubmitMove`, and does not simulate it. If
 * authorisation were evaluated after — or even alongside — any other condition,
 * the answer for a tokenless caller would have to vary with the Game's condition:
 * a Game still waiting for an opponent would be `game_not_started`, a finished one
 * `game_ended`. So the test drives a tokenless request against a Game in every one
 * of the four Game_States, one of them holding a Move_List, and requires the four
 * answers to be the same single value. A resolver that consulted `state` at all
 * could not produce that.
 *
 * The pipeline half of Requirement 3.9 — that nothing downstream even runs — is
 * in `ResolveActingPlayerTest`, where there is a handler to observe not running.
 */
it('settles authorisation without consulting the game state, whatever state the game is in', function () {
    [$resolver, $tokens] = resolverAnd();

    $answers = [];

    foreach (GameState::cases() as $state) {
        $game = resolverGame($state);

        $tokens->issue($game, Mark::X);
        $game->save();

        if ($state !== GameState::WaitingForOpponent) {
            $move = new Move;
            $move->game_id = $game->id;
            $move->cell_index = 0;
            $move->sequence_index = 0;
            $move->save();
        }

        Session::flush();

        $answers[$state->value] = $resolver->resolve($game->id);
    }

    $distinct = array_values(array_unique(array_map(
        static fn (VisibilityOutcome|ResolvedPlayer $outcome): string => $outcome instanceof VisibilityOutcome ? $outcome->value : 'resolved',
        $answers,
    )));

    expect($answers)->toHaveCount(4, 'the four Game_States were not all exercised')
        ->and($distinct)->toBe(
            [VisibilityOutcome::NotAuthorised->value],
            'a tokenless request was answered differently for different Game_States, so authorisation is not settled first (Req 3.9): '.implode(', ', $distinct),
        );
});

/*
 * A REJECTION CARRIES NO GAME STATE — structurally, not by convention.
 *
 * Requirement 3.10 excludes the Board, the Move_List, the Game_State and the
 * Mark_To_Move from the response to a request presenting no valid token, and
 * Property 7 restates it. The shape chosen makes it unreachable rather than merely
 * unread: a rejection is a `VisibilityOutcome` case, which has no fields, so there
 * is nothing to read and nothing for a serialiser to walk.
 *
 * Three independent assertions, because each covers a different way the guarantee
 * could be lost:
 *
 *   - the value is not a `ResolvedPlayer` and has no property or method by which a
 *     Game, a Mark, a MoveList, a Board or an Analysis could be reached — which is
 *     what would fail if someone added `game()` or a nullable `?Game` field to the
 *     rejection type;
 *   - the enum declares exactly the two properties every enum has, `name` and
 *     `value`, and both are strings;
 *   - every rendering of the rejection a caller could produce — JSON, `print_r`,
 *     `var_export`, `serialize` — contains none of the Game's actual data: not the
 *     Game_State, not the Join_Code, not a token hash, not the Move it holds.
 *
 * The Game_Id is deliberately not in that list. It is not game state under
 * Requirement 3.10, and it arrives in the URL of the very request being refused,
 * so a refusal that repeated it would disclose nothing.
 */
it('exposes no board, move list, game state or mark on a rejection', function () {
    [$resolver, $tokens] = resolverAnd();
    $game = resolverGame(GameState::Won);

    $tokens->issue($game, Mark::X);
    $game->save();
    $hash = (string) $game->x_token_hash;

    $move = new Move;
    $move->game_id = $game->id;
    $move->cell_index = 7;
    $move->sequence_index = 0;
    $move->save();

    Session::flush();

    $rejection = $resolver->resolve($game->id);

    expect($rejection)->toBeInstanceOf(VisibilityOutcome::class)
        ->and($rejection)->not->toBeInstanceOf(ResolvedPlayer::class, 'a rejection came back as a resolved player');

    // Nothing on the rejection type can reach anything about a Game.
    $reflected = new ReflectionEnum(VisibilityOutcome::class);

    $propertyNames = array_map(
        static fn (ReflectionProperty $property): string => $property->getName(),
        $reflected->getProperties(),
    );

    sort($propertyNames);

    expect($propertyNames)->toBe(['name', 'value'], 'the rejection type declares a property beyond an enum\'s own name and value: '.implode(', ', $propertyNames));

    $reachable = [];

    foreach ($reflected->getMethods() as $method) {
        $returns = (string) $method->getReturnType();

        foreach (explode('|', ltrim($returns, '?')) as $type) {
            if (in_array(ltrim($type, '\\'), resolverTypesAGameHidesIn(), true)) {
                $reachable[] = sprintf('%s() returns %s', $method->getName(), $returns);
            }
        }
    }

    expect($reachable)->toBe([], 'the rejection type exposes game state through a method: '.implode(', ', $reachable));

    // And no rendering of it carries anything about the Game.
    $renderings = [
        'json' => (string) json_encode($rejection),
        'print_r' => print_r($rejection, true),
        'var_export' => var_export($rejection, true),
        'serialize' => serialize($rejection),
    ];

    foreach ($renderings as $form => $rendered) {
        expect($rendered)->not->toContain((string) $game->join_code, "the {$form} rendering of a rejection discloses the Join_Code")
            ->and($rendered)->not->toContain($hash, "the {$form} rendering of a rejection discloses a Player_Token hash (Req 8.7)")
            ->and($rendered)->not->toContain(GameState::Won->value, "the {$form} rendering of a rejection discloses the Game_State (Req 3.10)")
            ->and($rendered)->not->toContain('winning_mark', "the {$form} rendering of a rejection discloses the winning Mark (Req 3.10)")
            ->and($rendered)->not->toContain('cell_index', "the {$form} rendering of a rejection discloses the Move_List (Req 3.10)");
    }

    expect($renderings['json'])->toBe('"not_authorised"', 'a rejection serialises to something other than its outcome value alone');
});

/*
 * THE EXPIRY_RECORD IS READ ON EXACTLY THE TWO ROWS THAT NEED IT.
 *
 * Rows 1, 2 and 5 are settled by the `games` row, and rows 6 and 7 are settled
 * without the tombstone on purpose — so `expiry_records` is touched only on rows 3
 * and 4, where a token is held for an id with no row. Asserted from the query log
 * in both directions: absent where it must be absent, and present where it must be
 * present, so this cannot pass by the resolver never reading that table at all.
 */
it('reads the expiry record only when a token is held for an id with no game row', function () {
    [$resolver, $tokens] = resolverAnd();

    $withRow = resolverGame();
    $tokens->issue($withRow, Mark::X);
    $withRow->save();

    $sweptWithToken = resolverTombstone();
    $neverWasWithToken = Str::uuid7()->toString();
    $tokenless = resolverTombstone();

    $readsTombstone = static function (array $statements): bool {
        foreach ($statements as $sql) {
            if (str_contains($sql, 'expiry_records')) {
                return true;
            }
        }

        return false;
    };

    // Row 1: a token that matches. Row 2: the same id with a token bound to
    // nothing. Row 5: no token at all.
    $rowOne = resolverStatementsDuring(fn () => $resolver->resolve($withRow->id));

    resolverHoldUnboundToken($tokens, $withRow->id);
    $rowTwo = resolverStatementsDuring(fn () => $resolver->resolve($withRow->id));

    Session::flush();
    $rowFive = resolverStatementsDuring(fn () => $resolver->resolve($withRow->id));

    // Rows 6 and 7: tokenless, no row.
    $rowSix = resolverStatementsDuring(fn () => $resolver->resolve($tokenless));
    $rowSeven = resolverStatementsDuring(fn () => $resolver->resolve(Str::uuid7()->toString()));

    // Rows 3 and 4: a token held for an id with no row.
    resolverHoldUnboundToken($tokens, $sweptWithToken);
    resolverHoldUnboundToken($tokens, $neverWasWithToken);

    $rowThree = resolverStatementsDuring(fn () => $resolver->resolve($sweptWithToken));
    $rowFour = resolverStatementsDuring(fn () => $resolver->resolve($neverWasWithToken));

    expect($readsTombstone($rowOne))->toBeFalse('row 1 read the tombstone table it does not need: '.implode(' | ', $rowOne))
        ->and($readsTombstone($rowTwo))->toBeFalse('row 2 read the tombstone table it does not need: '.implode(' | ', $rowTwo))
        ->and($readsTombstone($rowFive))->toBeFalse('row 5 read the tombstone table it does not need: '.implode(' | ', $rowFive))
        ->and($readsTombstone($rowSix))->toBeFalse('row 6 read the tombstone; it must be answered without consulting it: '.implode(' | ', $rowSix))
        ->and($readsTombstone($rowSeven))->toBeFalse('row 7 read the tombstone table it does not need: '.implode(' | ', $rowSeven))
        ->and($readsTombstone($rowThree))->toBeTrue('row 3 did not read the tombstone, so its game_expired answer cannot be coming from the table: '.implode(' | ', $rowThree))
        ->and($readsTombstone($rowFour))->toBeTrue('row 4 did not read the tombstone: '.implode(' | ', $rowFour));
});
