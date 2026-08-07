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
 * Task 5.6 — the five routes, their controllers, and the entry pages, over the real
 * HTTP surface.
 *
 * WHAT THIS FILE IS FOR, given how much is already covered elsewhere. `CreateGame`,
 * `JoinGame`, `GameResolver` and `GameRepresentation` each have their own test at the
 * service level, and `ResolveActingPlayerTest` drives the visibility table through
 * stand-in routes. None of them can say that the APPLICATION'S OWN routes exist, carry
 * the right middleware, and answer with the transport the design's outcome table
 * assigns. That is what is asserted here, and it is asserted through requests rather
 * than by inspecting the route table, because the transport is the claim: a 303 where
 * the design says 303, a 403/404/410 page carrying only an outcome, and a `game` prop
 * that exists exactly when the caller is a Player.
 *
 * ONE SESSION PER TEST, WHICH IS WHY THE FIXTURES BUILD ROWS BY HAND. A feature test
 * has a single session, so "a Game somebody else is already playing" cannot be built
 * by making a second request — that would put the other Player's token in the very
 * session under test. The helpers below mint a token, write only its hash to the row
 * and never call `remember()`, which is exactly "this Game has a Player, and it is not
 * you".
 */

uses(RefreshDatabase::class);

/**
 * A saved `games` row with a known Join_Code and no Player_Token in this session.
 *
 * `$code` is the STORED form — ten characters, no hyphen — because that is what the
 * column holds and what a lookup compares.
 */
function entryGame(GameState $state = GameState::WaitingForOpponent, string $code = '10ABCDEFGH'): Game
{
    $game = new Game;
    $game->id = Str::uuid7()->toString();
    $game->join_code = $code;
    $game->state = $state;
    // The CHECK on `games` pairs `state = 'won'` with a non-null `winning_mark` in the
    // same row, so it is set here rather than in a second UPDATE the insert would have
    // rejected first.
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
 * The Inertia page a response carries: the component name and the props, read from
 * the real payload rather than through a test macro.
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

/*
 * `GET /` — the entry page.
 */
it('renders the entry page', function () {
    get('/')->assertOk();

    entryPage(get('/'))->component('Home');
});

/*
 * `POST /games` → 303 → `GET /games/{game}`, AND THE JOIN_CODE ARRIVES ON THE GAME
 * PAGE (Req 1.6, 1.7).
 *
 * The create path is asserted end to end because that is the shape of the requirement:
 * a visitor submits the create action and the Web_Client displays the Join_Code and a
 * Join_Link. Neither is in the POST response — it is a redirect — so the assertion
 * follows the redirect and reads the props the game page was given, which is where
 * `JoinCodePanel` gets them.
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

    // The Join_Link the page was handed really does open the join form with the code
    // filled in — the round trip Requirement 1.6 is about, rather than two assertions
    // about a string.
    expect(entryProps(get((string) $representation['joinUrl']))['joinCode'])
        ->toBe($code?->display(), 'following the Join_Link did not prefill the join form');
});

/*
 * THE JOIN_LINK PATH IS THE ONE `GameRepresentation` ALREADY BUILDS, and this pins it.
 *
 * Task 5.5 emitted `joinUrl` before this route existed, so the two had to agree by
 * hand; now that `joinUrlFor()` calls `route('join', ...)`, the risk moves to the route
 * being renamed or remounted, which would silently change a URL players have already
 * pasted into messages. Asserted against the literal path rather than against the route
 * name, because a pasted link does not follow a rename.
 */
it('registers the join route at the path a join link already carries', function () {
    expect(Route::has('join'))->toBeTrue('the join route is not named `join`, which `GameRepresentation::joinUrlFor()` resolves')
        ->and(route('join', '10ABC-DEFGH'))->toBe(url('/join/10ABC-DEFGH'), 'the named join route no longer resolves to /join/{join_code}')
        ->and(route('join'))->toBe(url('/join'), 'the join code segment is not optional, so a rejected join has nowhere to land');
});

/*
 * `GET /join/{join_code?}` — PREFILLED FROM A LINK, EMPTY WITHOUT ONE.
 *
 * The transcription case is the point of the middle assertion: a link written in lower
 * case with an `l` for a `1` is the same Join_Code, and it arrives on the page in the
 * display form the other player is looking at. Nothing is looked up — this is a GET
 * that renders a form — so a code that is not a Join_Code at all still renders, for the
 * player to correct.
 */
it('prefills the join form from a join link, in any transcription, and renders empty without one', function () {
    $withCode = get('/join/10abc-defgh');

    entryPage($withCode)->component('Join');

    expect(entryProps($withCode)['joinCode'])->toBe('10ABC-DEFGH', 'a lower-case Join_Link did not arrive in the display form')
        ->and(entryProps(get('/join/l0ABC-DEFGH'))['joinCode'])->toBe('10ABC-DEFGH', 'an `l` typed for a `1` was not folded (Crockford)')
        ->and(entryProps(get('/join'))['joinCode'])->toBeNull('GET /join with no code did not render an empty join form')
        ->and(entryProps(get('/join/nonsense'))['joinCode'])->toBe('nonsense', 'an unreadable code was not passed back for the player to correct');

    // And `GET /join` really is the manual-entry form rather than a 404, because it is
    // also where every rejection lands.
    get('/join')->assertOk();
});

/*
 * A REJECTED JOIN IS A 303 BACK TO `/join` WITH THE OUTCOME FLASHED — the design's
 * third transport family (Req 2.2, 2.3).
 *
 * Not a 4xx, and carrying nothing about the Game: the caller is not a Player, so there
 * is nothing to disclose. The follow-up GET is asserted as well as the redirect,
 * because the outcome reaching `Join.tsx` as a prop is the half a player can see.
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
 * A NON-STRING `join_code` IS THE SAME ANSWER, not a 422 and not a 500.
 *
 * `JoinGame::handle()` takes `mixed` for this reason and the controller hands the input
 * over untouched; a Form Request here would answer with a validation payload and give a
 * prober a second vocabulary to distinguish "wrong shape" from "no such code".
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
 * A GAME THAT ALREADY HAS TWO PLAYERS IS `game_full`, ON THE SAME TRANSPORT (Req 2.3).
 *
 * Same 303 to the same page; the value is what differs, which is the design's rule that
 * distinctness is carried by the outcome and not by the status.
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
 * AN ACCEPTED JOIN LANDS ON THE GAME PAGE AS O (Req 2.1).
 *
 * The redirect target is the game page, and reaching it at all is the authorisation
 * answer: the O token went into the session during the POST, so the following GET is row
 * 1 of the visibility table rather than `not_authorised`.
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
 * A STRANGER GETS 403 AND NO GAME DATA (Req 3.7, 9.6).
 *
 * THE ABSENCE IS ASSERTED BY SEARCHING THE RESPONSE BODY, not by listing the props this
 * controller remembered to omit — a `game` prop added by accident, or a Game_State
 * leaking through some other key, fails here either way. The Game is deliberately in a
 * terminal state with Moves and a winning Mark, so there is real state to leak.
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
 * AN UNKNOWN Game_Id IS 404 AND A SWEPT ONE IS 410, BOTH ON THE SAME PAGE.
 *
 * The visibility table itself is `GameResolverTest`'s and `ResolveActingPlayerTest`'s;
 * what is asserted here is that the application's own route reaches it, and that the
 * page is keyed by the outcome rather than by the status — which is what lets
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
 * THE ROUTE-MODEL-BINDING CONSTRAINT, ASSERTED ON THE REAL ROUTE.
 *
 * `ResolveActingPlayerTest` demonstrates what binding would cost; this asserts that this
 * task did not incur it. Both halves are needed: `Route::model()`/`Route::bind()` for the
 * name `game` (explicit binding) and the controller's own signature (implicit binding,
 * which is driven by the type-hint and by nothing else).
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
