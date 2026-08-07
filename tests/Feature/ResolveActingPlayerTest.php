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
 * Task 5.3 — the `ResolveActingPlayer` middleware.
 *
 * MIDDLEWARE-LEVEL TESTS ARE POSSIBLE WITHOUT TASK 5.6, and this file is them.
 * The application's own game-scoped routes do not exist yet — 5.6 defines
 * `GET /games/{game}`, 6.2 the move route, 7.x the rematch route — but a route is
 * not a prerequisite for testing a middleware: routes can be declared in the test
 * itself, and declaring them here is better than waiting for 5.6 in one specific
 * way. These routes carry handlers that RECORD WHETHER THEY RAN, which is what
 * makes the Requirement 3.9 short-circuit observable at all. A real controller
 * could only be observed by what it returned.
 *
 * The routes are declared with `->middleware('web')` as well as the alias, because
 * the middleware reads the session (through `PlayerTokens`) and because the `web`
 * group is where `SubstituteBindings` lives — and the interaction with route-model
 * binding is one of the things this file pins.
 *
 * WHAT 5.6 AND 12.2 STILL HAVE TO COVER, stated plainly rather than assumed:
 *
 *   - The rendered page. This file asserts the STATUS and that no game data
 *     reaches the body; `NotAPlayer.tsx` keyed by outcome, and the `outcome` prop
 *     reaching it, are 5.6's, and the renderer for `GameNotVisibleException` is
 *     registered there.
 *   - The real routes. This file's routes are stand-ins, so nothing here can
 *     assert that `GET /games/{game}` and `POST /games/{game}/moves` actually
 *     carry this middleware, nor that their controllers avoid type-hinting
 *     `App\Models\Game`. That is 5.6's and 6.2's to get right and 12.2's to
 *     verify across every route naming a Game_Id.
 *   - Property 7 in full: every Game_State against every such route, which needs
 *     those routes to exist. Task 12.2.
 *
 * A note on the style, since this is the first file in the suite to make HTTP
 * requests and therefore sets the convention. The requests go through
 * `Pest\Laravel\get()` and `post()` rather than `get()`. Inside a Pest
 * closure `$this` is a `TestCall` as far as static analysis is concerned, so
 * `get()` is an undefined method to PHPStan and every request would need an
 * ignore; the imported functions are declared to return `TestResponse` and analyse
 * cleanly. They are the same calls on the same test case.
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
 * Gives `$game` a Player in `$mark`'s slot WITHOUT putting anything in this
 * session: mint, assign the hash, save, and never call `remember()`.
 *
 * This is the fixture almost every test below needs, and it has to be built this
 * way rather than with `PlayerTokens::issue()`. In a feature test the session the
 * assertions run against is the same session the request under test reads, so
 * `issue()` would hand the requesting browser the very credential the test is
 * asserting it does not have — turning row 5 into row 1. Minting and assigning
 * the hash alone gives what these tests actually mean by "this Game has a player,
 * and it is not you".
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
 * Compared exactly rather than by substring, deliberately: `App\Games` contains
 * the word "Game", so a substring test would flag `VisibilityOutcome` — the one
 * type a refusal is *supposed* to carry — and would pass only by being wrong in
 * two places at once.
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
 * The recorder is what makes Requirement 3.9 assertable without `SubmitMove`: the
 * handler stands in for EVERY downstream condition — the lifecycle guards, the
 * `cell_index` check, the snapshot read — because all of them live behind it in the
 * pipeline. If it did not run, none of them was evaluated.
 *
 * The GET route also reports back what `ResolveActingPlayer::resolved()` handed it,
 * which is the seam task 5.6's controllers use, exercised here rather than
 * described.
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
 * ROW 1 THROUGH THE PIPELINE — the request reaches the handler, and the handler
 * gets the acting player through the seam 5.6 will use.
 *
 * The Mark and the Game come back from `ResolveActingPlayer::resolved()`, so this
 * asserts the whole path: the middleware read the raw `{game}` parameter, resolved
 * it, put a `ResolvedPlayer` on the request, and the accessor typed it back.
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
 * ROWS 2 AND 5 — 403, and the handler never runs.
 *
 * Both rows that answer `not_authorised` are driven through the pipeline, so the
 * status and the short-circuit are asserted for each rather than for one and
 * assumed for the other.
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
 * ROW 3 — 410. Requirement 13.6's distinct outcome, expressed by the transport as
 * Gone.
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
 * ROWS 4, 6 AND 7 — 404.
 *
 * The three rows that answer `not_recognised`: a token held for an id that was
 * never a Game, and a tokenless request against a swept id and against an id that
 * never existed.
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
 * ROWS 6 AND 7, AS AN EQUIVALENCE, THROUGH THE PIPELINE.
 *
 * The resolver-level equivalence is in `GameResolverTest`; this is the same claim
 * about what a tokenless caller can actually observe over HTTP. The two responses
 * are compared to each other — status and body — so an edit that gave row 6 its own
 * answer fails here even if the individual expectations were updated to match.
 */
it('rows 6 and 7: the two responses are indistinguishable to a tokenless caller', function () {
    middlewareRecorder();

    $swept = middlewareTombstone();
    $neverWas = Str::uuid7()->toString();

    $sweptResponse = get('/_probe/games/'.$swept);
    $neverWasResponse = get('/_probe/games/'.$neverWas);

    expect($sweptResponse->getStatusCode())->toBe(
        $neverWasResponse->getStatusCode(),
        'a swept Game and an id that never existed answered with different statuses, so a tokenless caller can tell them apart',
    )->and($sweptResponse->getContent())->toBe(
        $neverWasResponse->getContent(),
        'the two response bodies differ, so the tombstone is observable to a caller holding no token',
    );

    // Neither body may mention the swept id while the other cannot mention it,
    // which is the one way two 404 bodies could still differ meaningfully.
    expect((string) $sweptResponse->getContent())->not->toContain($swept, 'the 404 body echoes the Game_Id, so the two rows could be compared by it');
});

/*
 * THE THREE `not_authorised` MODES, MUTUALLY INDISTINGUISHABLE OVER HTTP
 * (Req 9.6).
 *
 * The Web_Client renders one indication for all three, so all three responses must
 * be the same response: same status, same body. Compared pairwise, so no mode is
 * privileged as the expected one.
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
 * REQUIREMENT 3.9 THROUGH THE PIPELINE — authorisation precedes validity, and
 * nothing downstream is evaluated.
 *
 * The assertion does not depend on `SubmitMove` existing. The stand-in handler is
 * downstream of the middleware, so it is downstream of nothing and everything at
 * once: every move-validity and lifecycle condition the design specifies lives
 * behind it, and if it did not run, none of them ran either.
 *
 * Two payloads are posted to the move-shaped route, one that would be a valid Move
 * and one whose `cell_index` is out of range, against a Game in `waiting_for_opponent`
 * — a state whose own guard would answer `game_not_started`. All three of those
 * conditions are visible to a request that got past this middleware, and none of
 * them is reachable: both requests answer 403 with the same body, and the handler
 * never ran. "Whether or not the request would otherwise have been a valid Move"
 * is Property 7's own phrase, and the two payloads are it.
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
 * THE REFUSAL CARRIES THE OUTCOME AND NOTHING ELSE — the seam task 5.6 renders
 * from.
 *
 * With the exception handler disabled the middleware's own throw is observable, so
 * this asserts what 5.6 receives: the `VisibilityOutcome` and the status, on one
 * exception, with no Game anywhere on it. That is the whole interface between this
 * task and the `NotAPlayer.tsx` page, and it is asserted rather than described
 * because 5.6 cannot be written against a seam that is only documented.
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

    // Nothing about the Game is reachable from the refusal. Asserted over the
    // declared property types rather than by naming the properties a defect would
    // have added, so a `?Game` or a `GameSnapshot` bolted on later fails here
    // whatever it is called (Req 3.10).
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

    // The three outcomes map to the three statuses the design's outcome table
    // assigns, and the mapping is exhaustive over the enum — so this cannot drift
    // if a fourth outcome is ever added.
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
 * NO GAME DATA IN A REFUSED RESPONSE (Req 3.10, Property 7).
 *
 * A Game in a terminal state with a Move_List and a winning Mark is refused, and
 * the whole response — body and headers — is scanned for every fact Requirement
 * 3.10 excludes: the Game_State, the winning Mark, the Move_List, the Join_Code and
 * the Player_Token hash. The Game_Id is excluded from the scan deliberately: it is
 * not game state, and it arrived in the URL of the request being refused.
 *
 * This is the response-level counterpart of the resolver-level structural test.
 * Both are worth having: one says the rejection VALUE cannot carry game state, this
 * one says the response as actually rendered does not.
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

    expect($response->getStatusCode())->toBe(403)
        ->and($rendered)->not->toBe('', 'the response is empty, so this scan asserts nothing')
        ->and($rendered)->not->toContain((string) $game->join_code, 'the refused response discloses the Join_Code')
        ->and($rendered)->not->toContain($hash, 'the refused response discloses a Player_Token hash (Req 8.7)')
        ->and($rendered)->not->toContain(GameState::Won->value, 'the refused response discloses the Game_State (Req 3.10)')
        ->and($rendered)->not->toContain('winning_mark', 'the refused response discloses the winning Mark (Req 3.10)')
        ->and($rendered)->not->toContain('cell_index', 'the refused response discloses the Move_List (Req 3.10)')
        ->and($rendered)->not->toContain('sequence_index', 'the refused response discloses the Move_List (Req 3.10)');
});

/*
 * THE HAZARD, PINNED: ROUTE-MODEL BINDING ANSWERS BEFORE THIS MIDDLEWARE RUNS.
 *
 * `SubstituteBindings` is in the `web` group and group middleware runs before
 * route middleware, so a handler type-hinting `App\Models\Game` on a `{game}`
 * parameter resolves the model first — and aborts with the framework's own 404 for
 * any id with no row. That would collapse rows 3, 4, 6 and 7 into one 404 and
 * destroy the `game_expired` distinction Requirement 13.6 requires.
 *
 * The two halves below are the whole reason this test exists rather than a comment
 * saying "don't do that":
 *
 *   - A bound route answers 404 where the unbound one answers 410, so the loss is
 *     demonstrated rather than asserted about.
 *   - The middleware does not run at all on the bound route, which is what makes
 *     it a loss no assertion inside the middleware could catch.
 *
 * Task 5.6 and task 6.2 therefore may not type-hint `App\Models\Game` on these
 * routes, and may register no `Route::model()` or `Route::bind()` for the name
 * `game`. They use `ResolveActingPlayer::resolved($request)` instead, which
 * supplies the acting Mark as well as the row.
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

    // And the 404 above really is the binding's, not a missing route or an
    // unregistered middleware: the same bound route answers 403 for an id that
    // DOES have a row, which only this middleware can produce. So binding is
    // reached first and answers first, exactly as claimed — and where it finds
    // nothing, the outcome this middleware would have chosen never happens.
    $live = middlewareGame();
    middlewarePlayerHash($live, Mark::X);

    get('/_probe/bound/'.$live->id)->assertForbidden();
});

/*
 * A ROUTE WITH NO `{game}` PARAMETER IS A ROUTING DEFECT, NOT A 404.
 *
 * This is why the middleware is an alias and is appended to no global stack. If it
 * were global, `GET /` and `POST /join` would reach it with no Game_Id and would
 * have to be answered somehow — and the tempting answer, `not_recognised`, would
 * turn a misregistration into a plausible 404 on every request. It throws instead,
 * so the mistake is loud and is fixed rather than lived with.
 */
it('fails loudly rather than answering not_recognised when registered on a route naming no game', function () {
    Route::middleware(['web', 'acting.player'])->get('/_probe/nameless', fn () => response()->json(['ok' => true]));

    withoutExceptionHandling();

    expect(fn () => get('/_probe/nameless'))
        ->toThrow(LogicException::class);
});

/*
 * THE ACCESSOR IS THE SEAM, AND IT REFUSES TO GUESS.
 *
 * `ResolveActingPlayer::resolved()` on a request that never passed through the
 * middleware throws rather than returning null, because the alternative is a
 * controller on an unprotected game-scoped route serving a Game to anyone. The
 * `LogicException` names the middleware, so the fix is in the message.
 *
 * The positive half is asserted in the row 1 test above, through a real request;
 * this is the negative half, which needs no route at all.
 */
it('refuses to hand out an acting player for a request that never passed through it', function () {
    expect(fn () => ResolveActingPlayer::resolved(Request::create('/_probe/games/anything')))
        ->toThrow(LogicException::class);

    // And the attribute it reads is the one it writes: a request carrying a
    // `ResolvedPlayer` under that key is accepted, so the constant is not
    // decorative.
    $game = middlewareGame();
    $request = Request::create('/_probe/games/'.$game->id);
    $request->attributes->set(ResolveActingPlayer::REQUEST_ATTRIBUTE, new ResolvedPlayer($game, Mark::O));

    expect(ResolveActingPlayer::resolved($request)->mark)->toBe(Mark::O);
});
