<?php

declare(strict_types=1);

use App\Domain\TicTacToe\Mark;
use App\Games\GameState;
use App\Games\JoinCode;
use App\Games\MintedToken;
use App\Games\PlayerTokens;
use App\Games\ResolvedPlayer;
use App\Games\VisibilityOutcome;
use App\Http\Exceptions\GameNotVisibleException;
use App\Http\Middleware\ResolveActingPlayer;
use App\Models\ExpiryRecord;
use App\Models\Game;
use App\Models\Move;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\withoutExceptionHandling;

// Feature: remote-tic-tac-toe, Property 7: Authorisation precedes validity and denies all visibility
//
// Validates: Requirements 3.3, 3.4, 3.9, 3.10, 9.6, 13.6, 13.7, 13.8
//
/*
 * The `ResolveActingPlayer` middleware.
 *
 * This file declares its own stand-in routes rather than using the application's
 * because the handlers it declares record whether they ran, which is what makes the
 * Req 3.9 short-circuit observable. A real controller can only be observed by what
 * it returned, so a refusal and a controller that ran and then refused are
 * indistinguishable through it.
 *
 * The routes carry `->middleware('web')` as well as the alias: the middleware reads
 * the session through `PlayerTokens`, and the `web` group is where
 * `SubstituteBindings` lives, whose ordering this file pins below.
 *
 * Excluded, and where that ground lives instead: `NotAPlayer.tsx` keyed by outcome
 * and the exception renderer are `ShowGameController`'s tests; that the real routes
 * carry this middleware and that their controllers avoid type-hinting
 * `App\Models\Game` is the route-audit test's; Property 7 across every Game_State
 * and every game-scoped route needs those routes and is the same audit's.
 *
 * Requests go through the imported `Pest\Laravel\get()` and `post()` because inside
 * a Pest closure `$this` is a `TestCall` to static analysis, so `$this->get()` is an
 * undefined method to PHPStan. Same calls on the same test case.
 */

uses(RefreshDatabase::class);

/**
 * A saved `games` row. Same fixture shape as `GameResolverTest`, kept separate
 * because Pest's global function namespace is shared across the suite and two
 * files may not declare the same helper.
 */
function middlewareGame(GameState $state = GameState::Active): Game
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
 * Gives `$game` a Player in `$mark`'s slot without putting anything in this session:
 * mint, assign the hash, save, and never call `remember()`.
 *
 * It cannot use `PlayerTokens::issue()`. In a feature test the session the
 * assertions run against is the session the request under test reads, so `issue()`
 * would hand the requesting browser the very credential the test asserts it does not
 * have — turning row 5 into row 1.
 */
function middlewarePlayerHash(Game $game, Mark $mark): MintedToken
{
    $token = (new PlayerTokens)->mint();

    match ($mark) {
        Mark::X => $game->x_token_hash = $token->hash,
        Mark::O => $game->o_token_hash = $token->hash,
    };

    $game->save();

    return $token;
}

/**
 * The types through which a Board, a Move_List, a Game_State or a Mark_To_Move
 * could be reached, as fully qualified names.
 *
 * Compared exactly rather than by substring: `App\Games` contains the word "Game",
 * so a substring test would flag `VisibilityOutcome`, the one type a refusal is
 * supposed to carry.
 *
 * @return list<string>
 */
function middlewareTypesAGameHidesIn(): array
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
 * A tombstone with no `games` row: the state rows 3 and 6 share.
 */
function middlewareTombstone(): string
{
    $gameId = Str::uuid7()->toString();

    $record = new ExpiryRecord;
    $record->game_id = $gameId;
    $record->deleted_at = now();
    $record->save();

    return $gameId;
}

/**
 * Declares the two stand-in routes and returns the recorder the handlers write to.
 *
 * The handler stands in for every downstream condition — the lifecycle guards, the
 * `cell_index` check, the snapshot read — because all of them live behind it in the
 * pipeline. If it did not run, none of them was evaluated (Req 3.9).
 *
 * @return object{ran: bool, mark: string|null, gameId: string|null}
 */
function middlewareRecorder(): object
{
    $recorder = new class
    {
        public bool $ran = false;

        public ?string $mark = null;

        public ?string $gameId = null;
    };

    Route::middleware(['web', 'acting.player'])->group(function () use ($recorder): void {
        Route::get('/_probe/games/{game}', function (Request $request) use ($recorder) {
            $resolved = ResolveActingPlayer::resolved($request);

            $recorder->ran = true;
            $recorder->mark = $resolved->mark->value;
            $recorder->gameId = $resolved->game->id;

            return response()->json(['seen' => $resolved->mark->value]);
        });

        Route::post('/_probe/games/{game}/moves', function () use ($recorder) {
            $recorder->ran = true;

            return response()->json(['accepted' => true]);
        });
    });

    return $recorder;
}

/*
 * Row 1 through the pipeline. The Mark and the Game come back from
 * `ResolveActingPlayer::resolved()`, so this covers the whole path: the raw `{game}`
 * parameter read, resolved, put on the request, and typed back by the accessor.
 */
it('row 1: passes an authorised player through and hands the controller the acting mark', function () {
    $tokens = new PlayerTokens;
    $game = middlewareGame();

    $tokens->issue($game, Mark::O);
    $game->save();

    $recorder = middlewareRecorder();

    get('/_probe/games/'.$game->id)
        ->assertOk()
        ->assertExactJson(['seen' => 'o']);

    expect($recorder->ran)->toBeTrue('the handler did not run for an authorised player')
        ->and($recorder->mark)->toBe('o', 'the controller was handed the wrong acting Mark (Req 3.2)')
        ->and($recorder->gameId)->toBe($game->id, 'the controller was handed the wrong Game');
});

/*
 * Rows 2 and 5 — 403, and the handler never runs. Both are driven through the
 * pipeline so neither is assumed from the other.
 */
it('rows 2 and 5: answers 403 and runs no handler for a request with no valid token', function () {
    $tokens = new PlayerTokens;
    $game = middlewareGame();

    middlewarePlayerHash($game, Mark::X);

    // Row 5: no token at all.
    $recorder = middlewareRecorder();

    get('/_probe/games/'.$game->id)->assertForbidden();

    expect($recorder->ran)->toBeFalse('the handler ran for a tokenless request (row 5, Req 3.9)');

    // Row 2: a token held for this id whose hash is on neither slot.
    $unbound = $tokens->mint();
    $tokens->remember($game->id, $unbound);

    get('/_probe/games/'.$game->id)->assertForbidden();

    expect($recorder->ran)->toBeFalse('the handler ran for a request whose token matches neither slot (row 2)');
});

/*
 * Row 3 — 410. Req 13.6's distinct outcome, expressed by the transport as Gone.
 */
it('row 3: answers 410 for a session holding a token for a swept game', function () {
    $tokens = new PlayerTokens;
    $gameId = middlewareTombstone();

    $tokens->remember($gameId, $tokens->mint());

    $recorder = middlewareRecorder();

    get('/_probe/games/'.$gameId)->assertStatus(410);

    expect($recorder->ran)->toBeFalse('the handler ran for an expired Game');
});

/*
 * Rows 4, 6 and 7 — the three rows that answer `not_recognised` with 404.
 */
it('rows 4, 6 and 7: answers 404 for every not_recognised row', function () {
    $tokens = new PlayerTokens;
    $recorder = middlewareRecorder();

    // Row 4: token held, no row, no tombstone.
    $neverWas = Str::uuid7()->toString();
    $tokens->remember($neverWas, $tokens->mint());

    get('/_probe/games/'.$neverWas)->assertNotFound();

    // Row 6: tokenless, no row, tombstone.
    $swept = middlewareTombstone();

    get('/_probe/games/'.$swept)->assertNotFound();

    // Row 7: tokenless, nothing at all.
    get('/_probe/games/'.Str::uuid7()->toString())->assertNotFound();

    expect($recorder->ran)->toBeFalse('the handler ran for a Game_Id with no row');
});

/*
 * Rows 6 and 7 as an equivalence over HTTP; the resolver-level equivalence is in
 * `GameResolverTest`. The two responses are compared to each other rather than to
 * fixed expectations, so an edit that gave row 6 its own answer fails here even if
 * the individual expectations were updated to match.
 */
it('rows 6 and 7: the two responses are indistinguishable to a tokenless caller', function () {
    middlewareRecorder();

    $swept = middlewareTombstone();
    $neverWas = Str::uuid7()->toString();

    $sweptResponse = get('/_probe/games/'.$swept);
    $neverWasResponse = get('/_probe/games/'.$neverWas);

    // Each response's own Game_Id is normalised out before the comparison. A refusal
    // renders `NotAPlayer.tsx`, so the body is an Inertia page object, and a page
    // object always carries the URL of the request it answers — here
    // `/_probe/games/<the id the caller asked for>`. The two bodies therefore differ
    // by construction, in one place, in the one string the caller supplied itself.
    // Substituting a placeholder leaves every other byte under assertion: status,
    // component name, props, version.
    $sweptBody = str_replace($swept, '{game-id}', (string) $sweptResponse->getContent());
    $neverWasBody = str_replace($neverWas, '{game-id}', (string) $neverWasResponse->getContent());

    expect($sweptResponse->getStatusCode())->toBe(
        $neverWasResponse->getStatusCode(),
        'a swept Game and an id that never existed answered with different statuses, so a tokenless caller can tell them apart',
    )->and($sweptBody)->toBe(
        $neverWasBody,
        'the two response bodies differ other than by the Game_Id the caller itself supplied, so the tombstone is observable to a caller holding no token',
    );

    // Non-vacuity: rules out the normalisation itself making the two equal. Each
    // body really does carry the placeholder, so each echoed its own id and neither
    // echoed the other's.
    expect(str_contains($sweptBody, '{game-id}'))->toBeTrue('the swept response does not carry its own Game_Id, so normalising it out proves nothing')
        ->and(str_contains($sweptBody, $neverWas))->toBeFalse('the swept response mentions the id that never existed')
        ->and(str_contains($neverWasBody, $swept))->toBeFalse('the never-existed response mentions the swept id');
});

/*
 * The three `not_authorised` modes, mutually indistinguishable over HTTP (Req 9.6).
 * The `resolve()` expectation is a non-vacuity guard: it rules out mode 3's token
 * not actually being bound to the other Game, which would make it mode 2 again.
 */
it('answers the three not_authorised modes with indistinguishable responses', function () {
    $tokens = new PlayerTokens;

    $target = middlewareGame();
    middlewarePlayerHash($target, Mark::X);

    $other = middlewareGame();
    $elsewhere = middlewarePlayerHash($other, Mark::X);

    middlewareRecorder();

    // Mode 1: no token.
    $absent = get('/_probe/games/'.$target->id);

    // Mode 2: a token bound to nothing.
    $tokens->remember($target->id, $tokens->mint());
    $unrecognised = get('/_probe/games/'.$target->id);

    // Mode 3: a token genuinely bound to the other Game.
    $tokens->remember($target->id, $elsewhere);
    $boundElsewhere = get('/_probe/games/'.$target->id);

    expect($tokens->resolve($other, $elsewhere->raw))->toBe(Mark::X, "mode 3's token is not bound to the other Game, so the mode is not what it claims")
        ->and($absent->getStatusCode())->toBe(403)
        ->and($unrecognised->getStatusCode())->toBe($absent->getStatusCode(), 'an unrecognised token is distinguishable from no token (Req 9.6)')
        ->and($boundElsewhere->getStatusCode())->toBe($absent->getStatusCode(), 'a token bound elsewhere is distinguishable from no token (Req 3.4, 9.6)')
        ->and($unrecognised->getContent())->toBe($absent->getContent(), 'the response body differs between two of the three failure modes (Req 9.6)')
        ->and($boundElsewhere->getContent())->toBe($absent->getContent(), 'the response body differs between two of the three failure modes (Req 9.6)');
});

/*
 * Req 3.9 through the pipeline: authorisation precedes validity.
 *
 * Two payloads go to the move-shaped route, one that would be a valid Move and one
 * whose `cell_index` is out of range, against a Game in `waiting_for_opponent` whose
 * own guard would answer `game_not_started`. Three conditions a request past this
 * middleware would meet, none of them reachable — which is Property 7's "whether or
 * not the request would otherwise have been a valid Move".
 */
it('evaluates authorisation before any move-validity or lifecycle condition', function () {
    $game = middlewareGame(GameState::WaitingForOpponent);

    middlewarePlayerHash($game, Mark::X);

    $recorder = middlewareRecorder();

    $wouldBeValid = post('/_probe/games/'.$game->id.'/moves', ['cell_index' => 4]);
    $wouldBeInvalid = post('/_probe/games/'.$game->id.'/moves', ['cell_index' => 99]);

    expect($recorder->ran)->toBeFalse('the handler ran for an unauthorised move request, so a validity or lifecycle condition could have been evaluated first (Req 3.9)')
        ->and($wouldBeValid->getStatusCode())->toBe(403, 'an unauthorised move request was answered as something other than not_authorised (Req 3.9)')
        ->and($wouldBeInvalid->getStatusCode())->toBe(403, 'an out-of-range cell was answered as invalid_move rather than not_authorised (Req 3.9)')
        ->and($wouldBeInvalid->getContent())->toBe($wouldBeValid->getContent(), 'the two requests were answered differently, so the payload was inspected before authorisation was settled')
        ->and($game->fresh()?->state)->toBe(GameState::WaitingForOpponent, 'the rejected request changed the Game_State')
        ->and(Move::query()->where('game_id', $game->id)->count())->toBe(0, 'the rejected request created a Move (Req 13.7, Property 9)');
});

/*
 * The refusal carries the outcome and nothing else — the seam `NotAPlayer.tsx` is
 * rendered from. `withoutExceptionHandling()` is what makes the middleware's own
 * throw observable instead of the rendered response.
 */
it('throws one exception carrying the outcome and the status, with no game on it', function () {
    $game = middlewareGame(GameState::Won);

    middlewarePlayerHash($game, Mark::X);

    $move = new Move;
    $move->game_id = $game->id;
    $move->cell_index = 2;
    $move->sequence_index = 0;
    $move->save();

    middlewareRecorder();

    withoutExceptionHandling();

    $thrown = null;

    try {
        get('/_probe/games/'.$game->id);
    } catch (GameNotVisibleException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(GameNotVisibleException::class, 'the middleware did not short-circuit by throwing');

    if (! $thrown instanceof GameNotVisibleException) {
        throw new RuntimeException('nothing was thrown, so the assertions below would say nothing');
    }

    expect($thrown->outcome)->toBe(VisibilityOutcome::NotAuthorised)
        ->and($thrown->getStatusCode())->toBe(403, 'the status does not match the outcome the design assigns it')
        ->and($thrown->getMessage())->toBe('not_authorised');

    // Asserted over the declared property types rather than by naming properties a
    // defect would have added, so a `?Game` or a `GameSnapshot` bolted on later fails
    // here whatever it is called (Req 3.10).
    $exposed = [];

    foreach ((new ReflectionObject($thrown))->getProperties() as $property) {
        $declared = $property->getType();

        foreach (explode('|', ltrim((string) $declared, '?')) as $type) {
            if (in_array(ltrim($type, '\\'), middlewareTypesAGameHidesIn(), true)) {
                $exposed[] = sprintf('%s is typed %s', $property->getName(), (string) $declared);
            }
        }
    }

    expect($exposed)->toBe([], 'the refusal carries game state (Req 3.10): '.implode(', ', $exposed));

    // Built by iterating the enum, so adding a fourth outcome fails here rather than
    // silently escaping the mapping.
    $statuses = [];

    foreach (VisibilityOutcome::cases() as $outcome) {
        $statuses[$outcome->value] = (new GameNotVisibleException($outcome))->getStatusCode();
    }

    expect($statuses)->toBe([
        'not_authorised' => 403,
        'game_expired' => 410,
        'not_recognised' => 404,
    ], 'the outcome-to-status mapping is not the one the design specifies');
});

/*
 * No game data in a refused response (Req 3.10, Property 7). Body and headers are
 * both scanned. The Game_Id is left out of the scan deliberately: it is not game
 * state, and it arrived in the URL of the request being refused.
 *
 * The structural test above says the rejection value cannot carry game state; this
 * says the response as rendered does not.
 */
it('renders no board, move list, game state or mark in a refused response', function () {
    $game = middlewareGame(GameState::Won);

    $hash = middlewarePlayerHash($game, Mark::X)->hash;

    foreach ([[4, 0], [0, 1], [8, 2]] as [$cell, $sequence]) {
        $move = new Move;
        $move->game_id = $game->id;
        $move->cell_index = $cell;
        $move->sequence_index = $sequence;
        $move->save();
    }

    middlewareRecorder();

    $response = get('/_probe/games/'.$game->id);

    $rendered = (string) $response->getContent().' '.json_encode($response->headers->all());

    // `str_contains` rather than `toContain()`, which takes variadic needles and no
    // message argument — a message passed there is silently asserted as a second
    // needle. The empty-body expectation rules out the scan passing vacuously.
    expect($response->getStatusCode())->toBe(403)
        ->and($rendered)->not->toBe('', 'the response is empty, so this scan asserts nothing')
        ->and(str_contains($rendered, (string) $game->join_code))->toBeFalse('the refused response discloses the Join_Code')
        ->and(str_contains($rendered, $hash))->toBeFalse('the refused response discloses a Player_Token hash (Req 8.7)')
        ->and(str_contains($rendered, GameState::Won->value))->toBeFalse('the refused response discloses the Game_State (Req 3.10)')
        ->and(str_contains($rendered, 'winning_mark'))->toBeFalse('the refused response discloses the winning Mark (Req 3.10)')
        ->and(str_contains($rendered, 'cell_index'))->toBeFalse('the refused response discloses the Move_List (Req 3.10)')
        ->and(str_contains($rendered, 'sequence_index'))->toBeFalse('the refused response discloses the Move_List (Req 3.10)');
});

/*
 * Ordering hazard: route-model binding answers before this middleware runs.
 * `SubstituteBindings` is in the `web` group, and group middleware runs before route
 * middleware, so a handler type-hinting `App\Models\Game` on a `{game}` parameter
 * resolves the model first and aborts with the framework's own 404 for any id with no
 * row. That collapses rows 3, 4, 6 and 7 into one 404 and destroys the
 * `game_expired` distinction Req 13.6 requires.
 *
 * So no game-scoped route may type-hint `App\Models\Game` or register a
 * `Route::model()`/`Route::bind()` for the name `game`; they use
 * `ResolveActingPlayer::resolved($request)`, which supplies the acting Mark too.
 */
it('is preceded by route-model binding, which is why no game-scoped route may type-hint the model', function () {
    $tokens = new PlayerTokens;
    $expired = middlewareTombstone();

    $tokens->remember($expired, $tokens->mint());

    middlewareRecorder();

    // The correct arrangement: no binding, so this middleware decides.
    get('/_probe/games/'.$expired)->assertStatus(410);

    // The hazard: the same id, the same middleware, plus a type-hinted model.
    Route::middleware(['web', 'acting.player'])->get(
        '/_probe/bound/{game}',
        fn (Game $game) => response()->json(['id' => $game->id]),
    );

    get('/_probe/bound/'.$expired)->assertNotFound();

    // Non-vacuity: rules out the 404 above being a missing route or an unregistered
    // middleware. The same bound route answers 403 for an id that does have a row,
    // which only this middleware can produce.
    $live = middlewareGame();
    middlewarePlayerHash($live, Mark::X);

    get('/_probe/bound/'.$live->id)->assertForbidden();
});

/*
 * A route with no `{game}` parameter is a routing defect, not a 404. Answering
 * `not_recognised` there would turn a misregistration into a plausible 404 on every
 * request, which is also why the middleware is an alias and is on no global stack.
 */
it('fails loudly rather than answering not_recognised when registered on a route naming no game', function () {
    Route::middleware(['web', 'acting.player'])->get('/_probe/nameless', fn () => response()->json(['ok' => true]));

    withoutExceptionHandling();

    expect(fn () => get('/_probe/nameless'))
        ->toThrow(LogicException::class);
});

/*
 * The accessor refuses to guess: `resolved()` on a request that never passed through
 * the middleware throws rather than returning null, since the alternative is a
 * controller on an unprotected game-scoped route serving a Game to anyone. The row 1
 * test above is the positive half.
 */
it('refuses to hand out an acting player for a request that never passed through it', function () {
    expect(fn () => ResolveActingPlayer::resolved(Request::create('/_probe/games/anything')))
        ->toThrow(LogicException::class);

    // Non-vacuity: rules out `resolved()` throwing unconditionally. A request
    // carrying a `ResolvedPlayer` under that key is accepted.
    $game = middlewareGame();
    $request = Request::create('/_probe/games/'.$game->id);
    $request->attributes->set(ResolveActingPlayer::REQUEST_ATTRIBUTE, new ResolvedPlayer($game, Mark::O));

    expect(ResolveActingPlayer::resolved($request)->mark)->toBe(Mark::O);
});
