<?php

declare(strict_types=1);

use App\Domain\TicTacToe\Mark;
use App\Games\GameState;
use App\Games\JoinCode;
use App\Games\PlayerTokens;
use App\Http\Controllers\ShowGameController;
use App\Models\ExpiryRecord;
use App\Models\Game;
use App\Models\Move;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

// Feature: remote-tic-tac-toe
//
// Validates: Requirements 1.6, 1.7, 2.2, 2.3, 3.7, 9.6
//
/*
 * The five routes, their controllers, and the entry pages, over the real HTTP surface.
 *
 * The services themselves are covered elsewhere: `CreateGame`, `JoinGame`,
 * `GameResolver` and `GameRepresentation` each have a service-level test, and the
 * visibility table is `GameResolverTest`'s and `ResolveActingPlayerTest`'s. What is
 * left to this file is that the application's own routes exist, carry the right
 * middleware, and answer with the transport the design's outcome table assigns.
 *
 * A feature test has one session, so "a Game somebody else is already playing" cannot
 * be built by making a second request — that would put the other Player's token in the
 * session under test. The helpers below mint a token, write only its hash to the row
 * and never call `remember()`.
 */

uses(RefreshDatabase::class);

/**
 * A saved `games` row with a known Join_Code and no Player_Token in this session.
 *
 * `$code` is the stored form — ten characters, no hyphen — because that is what the
 * column holds and what a lookup compares.
 */
function entryGame(GameState $state = GameState::WaitingForOpponent, string $code = '10ABCDEFGH'): Game
{
    $game = new Game;
    $game->id = Str::uuid7()->toString();
    $game->join_code = $code;
    $game->state = $state;
    // The CHECK on `games` pairs `state = 'won'` with a non-null `winning_mark` in the
    // same row, so it cannot be set by a second UPDATE.
    $game->winning_mark = $state === GameState::Won ? Mark::X : null;
    $game->version_counter = 0;
    $game->last_activity_at = now();
    $game->save();

    return $game;
}

/**
 * Puts a Player in `$mark`'s slot without putting anything in this session.
 */
function entryPlayerHash(Game $game, Mark $mark): string
{
    $token = (new PlayerTokens)->mint();

    match ($mark) {
        Mark::X => $game->x_token_hash = $token->hash,
        Mark::O => $game->o_token_hash = $token->hash,
    };

    $game->save();

    return $token->hash;
}

/**
 * The Inertia page a response carries, read from the real payload rather than through
 * a test macro.
 *
 * @param  TestResponse<Response>  $response
 */
function entryPage(TestResponse $response): AssertableInertia
{
    return AssertableInertia::fromTestResponse($response);
}

/**
 * The props of the Inertia page a response carries.
 *
 * @param  TestResponse<Response>  $response
 * @return array<string, mixed>
 */
function entryProps(TestResponse $response): array
{
    $page = entryPage($response)->toArray();

    return is_array($page['props'] ?? null) ? $page['props'] : [];
}

it('renders the entry page', function () {
    get('/')->assertOk();

    entryPage(get('/'))->component('Home');
});

/*
 * `POST /games` → 303 → `GET /games/{game}` (Req 1.6, 1.7).
 *
 * Neither the Join_Code nor the Join_Link is in the POST response, since it is a
 * redirect, so the assertion follows it and reads the props the game page was given —
 * where `JoinCodePanel` gets them.
 */
it('creates a game, redirects to it with 303, and delivers the join code and join link there', function () {
    $response = post('/games');

    $game = Game::query()->sole();

    $response->assertStatus(303)->assertRedirect(url('/games/'.$game->id));

    $props = entryProps(get('/games/'.$game->id));

    expect($props['game'] ?? null)->toBeArray();

    /** @var array<string, mixed> $representation */
    $representation = $props['game'];
    $code = JoinCode::parse((string) $game->join_code);

    expect($code)->not->toBeNull('the created row does not hold a well formed Join_Code')
        ->and($representation['state'])->toBe('waiting_for_opponent')
        ->and($representation['yourMark'])->toBe('x', 'the Creator was not given the Mark X (Req 1.5)')
        ->and($representation['version'])->toBe(0)
        ->and($representation['joinCode'])->toBe($code?->display(), 'the game page did not receive the Join_Code (Req 1.6)')
        ->and($representation['joinUrl'])->toBe(url('/join/'.$code?->display()), 'the game page did not receive a Join_Link at the join route (Req 1.6)');

    // The round trip Req 1.6 is about: the Join_Link the page was handed opens the join
    // form with the code filled in.
    expect(entryProps(get((string) $representation['joinUrl']))['joinCode'])
        ->toBe($code?->display(), 'following the Join_Link did not prefill the join form');
});

/*
 * The path `GameRepresentation::joinUrlFor()` builds through `route('join', ...)`.
 * Renaming or remounting the route would silently change a URL players have already
 * pasted into messages, so this is asserted against the literal path: a pasted link
 * does not follow a rename.
 */
it('registers the join route at the path a join link already carries', function () {
    expect(Route::has('join'))->toBeTrue('the join route is not named `join`, which `GameRepresentation::joinUrlFor()` resolves')
        ->and(route('join', '10ABC-DEFGH'))->toBe(url('/join/10ABC-DEFGH'), 'the named join route no longer resolves to /join/{join_code}')
        ->and(route('join'))->toBe(url('/join'), 'the join code segment is not optional, so a rejected join has nowhere to land');
});

/*
 * `GET /join/{join_code?}` — prefilled from a link, empty without one.
 *
 * A link written in lower case with an `l` for a `1` is the same Join_Code and arrives
 * in the display form the other player is looking at. Nothing is looked up, so a code
 * that is not a Join_Code at all still renders for the player to correct.
 */
it('prefills the join form from a join link, in any transcription, and renders empty without one', function () {
    $withCode = get('/join/10abc-defgh');

    entryPage($withCode)->component('Join');

    expect(entryProps($withCode)['joinCode'])->toBe('10ABC-DEFGH', 'a lower-case Join_Link did not arrive in the display form')
        ->and(entryProps(get('/join/l0ABC-DEFGH'))['joinCode'])->toBe('10ABC-DEFGH', 'an `l` typed for a `1` was not folded (Crockford)')
        ->and(entryProps(get('/join'))['joinCode'])->toBeNull('GET /join with no code did not render an empty join form')
        ->and(entryProps(get('/join/nonsense'))['joinCode'])->toBe('nonsense', 'an unreadable code was not passed back for the player to correct');

    // `GET /join` is the manual-entry form rather than a 404, because it is also where
    // every rejection lands.
    get('/join')->assertOk();
});

/*
 * A rejected join is a 303 back to `/join` with the outcome flashed — the design's
 * third transport family (Req 2.2, 2.3). Not a 4xx, and carrying nothing about the
 * Game. The follow-up GET is asserted too, since the outcome reaching `Join.tsx` as a
 * prop is the half a player can see.
 */
it('answers an unmatched join code with a 303 back to the join form carrying not_recognised', function () {
    $response = post('/join', ['join_code' => 'ZZZZZ-ZZZZZ']);

    $response->assertStatus(303)
        ->assertRedirect(url('/join'))
        ->assertSessionHas('outcome', 'not_recognised');

    $props = entryProps(get('/join'));

    expect($props['outcome'])->toBe('not_recognised', 'the flashed outcome did not reach the join page')
        ->and($props['joinCode'])->toBeNull('the join page came back with a code the player never submitted');
});

/*
 * A non-string `join_code` is the same answer, not a 422 and not a 500.
 * `JoinGame::handle()` takes `mixed` for this reason: a Form Request would answer with
 * a validation payload and give a prober a second vocabulary to distinguish "wrong
 * shape" from "no such code".
 */
it('answers a join code that is not even a string with the same not_recognised outcome', function () {
    post('/join', ['join_code' => ['array' => 'value']])
        ->assertStatus(303)
        ->assertRedirect(url('/join'))
        ->assertSessionHas('outcome', 'not_recognised');

    post('/join')
        ->assertStatus(303)
        ->assertRedirect(url('/join'))
        ->assertSessionHas('outcome', 'not_recognised');
});

/*
 * A Game that already has two Players is `game_full` on the same transport (Req 2.3):
 * same 303 to the same page, and only the outcome differs, which is the design's rule
 * that distinctness is carried by the outcome and not by the status.
 */
it('answers a full game with a 303 back to the join form carrying game_full', function () {
    $game = entryGame(GameState::Active);

    entryPlayerHash($game, Mark::X);
    entryPlayerHash($game, Mark::O);

    post('/join', ['join_code' => '10ABC-DEFGH'])
        ->assertStatus(303)
        ->assertRedirect(url('/join'))
        ->assertSessionHas('outcome', 'game_full');

    expect(entryProps(get('/join'))['outcome'])->toBe('game_full', 'the flashed outcome did not reach the join page');
});

/*
 * An accepted join lands on the game page as O (Req 2.1). Reaching the page at all is
 * the authorisation answer: the O token went into the session during the POST, so the
 * following GET is row 1 of the visibility table rather than `not_authorised`.
 */
it('joins a waiting game and lands on the game page holding the mark O', function () {
    $game = entryGame();

    entryPlayerHash($game, Mark::X);

    post('/join', ['join_code' => '10ABC-DEFGH'])
        ->assertStatus(303)
        ->assertRedirect(url('/games/'.$game->id))
        ->assertSessionMissing('outcome');

    $props = entryProps(get('/games/'.$game->id));

    expect($props['game'] ?? null)->toBeArray();

    /** @var array<string, mixed> $representation */
    $representation = $props['game'];

    expect($representation['yourMark'])->toBe('o')
        ->and($representation['state'])->toBe('active')
        ->and($representation['version'])->toBe(1, 'the Version_Counter was not incremented by the join (Req 2.6)')
        ->and($representation['joinCode'])->toBeNull('the Join_Code is still on screen for a Game that has two Players');
});

/*
 * A stranger gets 403 and no game data (Req 3.7, 9.6).
 *
 * The absence is asserted by searching the response body rather than by listing the
 * props this controller remembered to omit, so a `game` prop added by accident or a
 * Game_State leaking through another key fails either way. The Game is in a terminal
 * state with Moves and a winning Mark, so there is real state to leak.
 */
it('refuses a stranger with 403, the NotAPlayer page, and no game data anywhere in the response', function () {
    $game = entryGame(GameState::Won);

    $hash = entryPlayerHash($game, Mark::X);

    foreach ([[0, 0], [3, 1], [1, 2], [4, 3], [2, 4]] as [$cell, $sequence]) {
        $move = new Move;
        $move->game_id = $game->id;
        $move->cell_index = $cell;
        $move->sequence_index = $sequence;
        $move->save();
    }

    $response = get('/games/'.$game->id);

    $response->assertForbidden();

    entryPage($response)->component('NotAPlayer');

    $props = entryProps($response);

    expect($props['outcome'])->toBe('not_authorised')
        ->and(array_key_exists('game', $props))->toBeFalse('the refusal carries a game prop (Req 3.10)');

    $body = (string) $response->getContent();

    expect($body)->not->toBe('', 'the response is empty, so this scan asserts nothing')
        ->and(str_contains($body, '10ABCDEFGH'))->toBeFalse('the refusal discloses the Join_Code')
        ->and(str_contains($body, '10ABC-DEFGH'))->toBeFalse('the refusal discloses the Join_Code')
        ->and(str_contains($body, $hash))->toBeFalse('the refusal discloses a Player_Token hash (Req 8.7)')
        ->and(str_contains($body, 'won'))->toBeFalse('the refusal discloses the Game_State (Req 3.7)')
        ->and(str_contains($body, 'markToMove'))->toBeFalse('the refusal discloses the Mark_To_Move (Req 3.7)')
        ->and(str_contains($body, 'winningLines'))->toBeFalse('the refusal discloses the winning lines (Req 3.7)')
        ->and(str_contains($body, 'yourMark'))->toBeFalse('the refusal discloses a Mark (Req 3.7)')
        ->and(str_contains($body, 'moves'))->toBeFalse('the refusal discloses the Move_List (Req 3.7)');
});

/*
 * An unknown Game_Id is 404 and a swept one 410, both on the same page. What is
 * asserted is that the application's own route reaches the visibility table, and that
 * the page is keyed by the outcome rather than the status — which is what lets
 * `NotAPlayer.tsx` hold three messages and no `switch` on a status code.
 */
it('answers an unknown game id with 404 and a swept one with 410, keyed by outcome', function () {
    $unknown = get('/games/'.Str::uuid7()->toString());

    $unknown->assertNotFound();
    entryPage($unknown)->component('NotAPlayer');
    expect(entryProps($unknown)['outcome'])->toBe('not_recognised');

    $tokens = new PlayerTokens;
    $sweptId = Str::uuid7()->toString();

    $record = new ExpiryRecord;
    $record->game_id = $sweptId;
    $record->deleted_at = now();
    $record->save();

    $tokens->remember($sweptId, $tokens->mint());

    $swept = get('/games/'.$sweptId);

    $swept->assertStatus(410);
    entryPage($swept)->component('NotAPlayer');
    expect(entryProps($swept)['outcome'])->toBe('game_expired', 'a swept Game was not distinguished for the session that played it (Req 13.6)');
});

/*
 * The route-model-binding constraint, on the real route. `ResolveActingPlayerTest`
 * demonstrates what binding would cost. Both halves are needed here:
 * `Route::model()`/`Route::bind()` for the name `game` (explicit binding) and the
 * controller's signature (implicit binding, driven by the type-hint and nothing else).
 */
it('resolves the game parameter through the middleware rather than through route-model binding', function () {
    $route = Route::getRoutes()->getByName('games.show');

    expect($route)->not->toBeNull()
        ->and($route?->gatherMiddleware())->toContain('acting.player')
        ->and(app('router')->getBindingCallback('game'))->toBeNull('an explicit binder is registered for {game}, which answers before the middleware runs');

    foreach ((new ReflectionMethod(ShowGameController::class, '__invoke'))->getParameters() as $parameter) {
        expect((string) $parameter->getType())->not->toBe(
            Game::class,
            'the game page controller type-hints the model, so route-model binding answers before ResolveActingPlayer runs',
        );
    }
});
