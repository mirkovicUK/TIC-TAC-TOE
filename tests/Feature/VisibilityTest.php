<?php

declare(strict_types=1);

use App\Domain\TicTacToe\Mark;
use App\Games\GameState;
use App\Games\JoinCode;
use App\Games\MintedToken;
use App\Games\PlayerTokens;
use App\Http\Middleware\ResolveActingPlayer;
use App\Models\Game;
use App\Models\Move;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\Response as StatusCodes;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

// Feature: remote-tic-tac-toe, Property 7: Authorisation precedes validity and denies all visibility
//
// Validates: Requirements 3.3, 3.4, 3.7, 3.9, 3.10, 8.7, 9.6, 14.6
//
/*
 * The matrix Requirement 14.6 mandates: every Game_State against every route naming a
 * Game_Id, for a session holding no Player_Token and a session holding one bound to a
 * different Game. Each cell asserts the `not_authorised` outcome and that the response
 * carries none of the five things Property 7 names — no Board, no Move_List, no
 * Game_State, no Mark_To_Move, no Player_Token value.
 *
 * Every assertion here is negative, and a negative assertion is the kind that passes
 * against nothing. So each cell carries three guards: the fixture is read back from the
 * two tables to show the Game really is in the state the cell claims and really has a
 * Move_List to withhold, the response body and the props are asserted non-empty, and
 * every value searched for is asserted to be a real, well-formed value before it is
 * searched for. The falsification test at the foot of the file points the same detector
 * at a session that IS a Player and asserts that it fires, which is what shows the
 * cells would notice a leak.
 *
 * `str_contains` and `in_array` with an explicit message throughout, never
 * `not->toContain($needle, $message)`: that expectation takes variadic needles and no
 * message argument, so the message is silently asserted as a second needle and the
 * whole expectation passes unconditionally.
 *
 * What is deliberately left elsewhere. The seven-row visibility table row by row, and
 * the three `not_authorised` modes compared at the resolver, are `GameResolverTest`'s;
 * the short-circuit observed through a handler that records whether it ran, and the
 * route-model-binding hazard, are `ResolveActingPlayerTest`'s; that the eleven
 * rejection values are pairwise distinct is `OutcomeVocabularyTest`'s — this file makes
 * the complementary claim, that a refusal discloses nothing; that a refused request
 * changes no state is Property 9's. The cross-site request forgery rejection is
 * excluded by Requirement 14.3 and could not be exercised anyway, since
 * `PreventRequestForgery::handle()` proceeds when `runningUnitTests()` holds.
 */

uses(RefreshDatabase::class);

/*
 * The rate limiters count into the default cache store, and two of the three routes
 * below carry one. `phpunit.xml` sets `CACHE_STORE=array`, but
 * `SqliteConnectionSettingsTest` clears environment variables mid-run and the `.env`
 * values behind them can take over for every test that follows, which would make the
 * window shared rather than per-test. No cell issues more than two requests, so this is
 * insurance rather than a load-bearing step.
 */
beforeEach(function (): void {
    config(['cache.default' => 'array', 'cache.limiter' => null]);
});

/**
 * The three routes of the design's HTTP surface that name a Game_Id, spelled as
 * `METHOD /uri`. The route axis of the matrix.
 *
 * Hardcoded rather than read from the router because a dataset is resolved before the
 * application boots. `it('sweeps every game state against every route naming a game
 * id')` asserts this list against the registered routes, so a fourth game-scoped route
 * fails there rather than quietly escaping the sweep.
 *
 * @return list<string>
 */
function visibilityRoutes(): array
{
    return [
        'GET /games/{game}',
        'POST /games/{game}/moves',
        'POST /games/{game}/rematch',
    ];
}

/**
 * The two unauthorised session shapes of the matrix, and the third failure mode
 * Requirement 9.6 names, which is compared against them for indistinguishability
 * rather than swept.
 */
const VISIBILITY_NO_TOKEN = 'a session holding no Player_Token';

const VISIBILITY_BOUND_ELSEWHERE = 'a session holding a Player_Token bound to another Game';

const VISIBILITY_UNRECOGNISED = 'a session holding an unrecognised Player_Token';

/**
 * The Move_List each Game_State is built with: contiguous from Sequence_Index zero,
 * with the Mark of each Move given by the parity of its Sequence_Index (Req 4.1, 11.4).
 *
 * `won` is X's top row completed at Sequence_Index 4 with nothing after it, and `drawn`
 * is nine Moves completing no Winning_Line, so both are Well_Formed_Move_Lists and the
 * authorised control at the foot of the file can analyse them.
 *
 * @return list<int>
 */
function visibilityCellsFor(GameState $state): array
{
    return match ($state) {
        GameState::WaitingForOpponent => [],
        GameState::Active => [0, 3],
        GameState::Won => [0, 3, 1, 4, 2],
        GameState::Drawn => [0, 1, 2, 4, 3, 5, 7, 6, 8],
    };
}

/**
 * The Cell_Indexes of a Game's persisted Move_List, in Sequence_Index order.
 *
 * Read from the table rather than from the array the fixture was built with, so a
 * guard asserting "there is a Move_List here to withhold" is about the state the
 * request will meet.
 *
 * @return list<int>
 */
function visibilityPersistedCells(string $gameId): array
{
    return array_values(
        DB::table('moves')
            ->where('game_id', $gameId)
            ->orderBy('sequence_index')
            ->pluck('cell_index')
            ->map(static fn (mixed $cell): int => (int) $cell)
            ->all()
    );
}

/**
 * One cell's worth of adversarial state: a Game in `$state` with both Player_Tokens
 * bound to it, a Move_List, a Rematch where the state permits one, a second Game whose
 * Player_Token the elsewhere-bound session will present, and the needles a refusal must
 * not contain.
 *
 * Attributes are assigned one by one because mass assignment is closed on the model, and
 * every value is what a schema CHECK requires: a `winning_mark` on `won` and none
 * elsewhere, an empty O slot while a Game waits for an opponent, and a `join_code` on
 * every Game that is not a Rematch. `version_counter` is one for the join (Req 2.6) plus
 * one per accepted Move (Req 4.7).
 *
 * The tokens are minted and their hashes assigned directly rather than issued through
 * `PlayerTokens::issue()`: a feature test has one session, and `issue()` writes it, so it
 * would hand the requesting browser the very credential the cell asserts it does not
 * hold.
 *
 * `bodyNeedles` are high-entropy values searched for in the whole response, and their
 * well-formedness is asserted here — a search for a value that was never generated finds
 * nothing whatever the subject does. `payloadNeedles` are the Game_State and the key
 * names of the representation, searched for in the props alone: a Vite asset filename in
 * the HTML shell is a hash, and a short lower-case needle could match one, which would
 * be a failure nobody could reproduce.
 *
 * @return array{
 *     game: Game,
 *     otherGame: Game,
 *     cells: list<int>,
 *     freeCell: int|null,
 *     rematchId: string|null,
 *     x: MintedToken,
 *     o: MintedToken|null,
 *     unrecognised: MintedToken,
 *     elsewhere: MintedToken,
 *     bodyNeedles: array<string, string>,
 *     payloadNeedles: array<string, string>,
 * }
 */
function visibilityFixture(GameState $state): array
{
    $tokens = new PlayerTokens;

    $x = $tokens->mint();
    $o = $tokens->mint();
    $unrecognised = $tokens->mint();
    $elsewhere = $tokens->mint();

    $cells = visibilityCellsFor($state);
    $waiting = $state === GameState::WaitingForOpponent;

    $game = new Game;
    $game->id = Str::uuid7()->toString();
    $game->join_code = JoinCode::generate()->stored;
    $game->state = $state;
    $game->winning_mark = $state === GameState::Won ? Mark::X : null;
    $game->x_token_hash = $x->hash;
    $game->o_token_hash = $waiting ? null : $o->hash;
    $game->version_counter = $waiting ? 0 : 1 + count($cells);
    $game->last_activity_at = now()->subMinutes(5);
    $game->save();

    foreach ($cells as $sequence => $cell) {
        $move = new Move;
        $move->game_id = $game->id;
        $move->cell_index = $cell;
        $move->sequence_index = $sequence;
        $move->save();
    }

    // The Game the elsewhere-bound token genuinely belongs to, built the way that
    // failure mode really arises: a Player of this Game pointing a request at the one
    // above.
    $otherGame = new Game;
    $otherGame->id = Str::uuid7()->toString();
    $otherGame->join_code = JoinCode::generate()->stored;
    $otherGame->state = GameState::Active;
    $otherGame->winning_mark = null;
    $otherGame->x_token_hash = $elsewhere->hash;
    $otherGame->version_counter = 1;
    $otherGame->last_activity_at = now()->subMinutes(5);
    $otherGame->save();

    // A Rematch wherever one is reachable (Req 7.2), because `rematchGameId` is a
    // field of the representation and therefore a fifth thing a refusal could leak.
    $rematchId = null;

    if ($state->isTerminal()) {
        $rematch = new Game;
        $rematch->id = Str::uuid7()->toString();
        $rematch->join_code = null;
        $rematch->state = GameState::Active;
        $rematch->winning_mark = null;
        $rematch->version_counter = 0;
        $rematch->rematch_of_game_id = $game->id;
        $rematch->last_activity_at = now();
        $rematch->save();

        $rematchId = $rematch->id;
    }

    $free = array_values(array_diff(range(0, 8), $cells));

    $bodyNeedles = [
        'the Join_Code' => (string) $game->join_code,
        'the Join_Code in its display form' => (string) JoinCode::parse((string) $game->join_code)?->display(),
        'the X Player_Token' => $x->raw,
        'the X Player_Token hash' => $x->hash,
        'a Player_Token bound to nothing' => $unrecognised->raw,
        'the Player_Token bound to the other Game' => $elsewhere->raw,
        'the Game_Id of the other Game' => $otherGame->id,
        'the Move_List by its columns' => 'cell_index',
        'the Sequence_Indexes by their column' => 'sequence_index',
    ];

    if (! $waiting) {
        $bodyNeedles['the O Player_Token'] = $o->raw;
        $bodyNeedles['the O Player_Token hash'] = $o->hash;
    }

    if ($rematchId !== null) {
        $bodyNeedles['the Game_Id of the Rematch'] = $rematchId;
    }

    foreach (['the X Player_Token' => $x, 'a Player_Token bound to nothing' => $unrecognised, 'the Player_Token bound to the other Game' => $elsewhere] as $label => $token) {
        expect((bool) preg_match('/^[0-9a-f]{64}$/', $token->raw))->toBeTrue("{$label} is not 64 hex characters, so searching a response for it proves nothing")
            ->and((bool) preg_match('/^[0-9a-f]{64}$/', $token->hash))->toBeTrue("the hash of {$label} is not a sha256 digest, so searching a response for it proves nothing");
    }

    foreach ($bodyNeedles as $label => $needle) {
        expect(strlen($needle))->toBeGreaterThanOrEqual(10, "{$label} is too short to be looked for in a response body without matching something incidental");
    }

    return [
        'game' => $game,
        'otherGame' => $otherGame,
        'cells' => $cells,
        'freeCell' => $free[0] ?? null,
        'rematchId' => $rematchId,
        'x' => $x,
        'o' => $waiting ? null : $o,
        'unrecognised' => $unrecognised,
        'elsewhere' => $elsewhere,
        'bodyNeedles' => $bodyNeedles,
        'payloadNeedles' => [
            'the Game_State' => $state->value,
            'the Board' => '"board"',
            'the Move_List' => '"moves"',
            'the Mark_To_Move' => '"markToMove"',
            'the viewing Player\'s own Mark' => '"yourMark"',
            'whose turn it is' => '"isYourTurn"',
            'the winning Mark' => '"winningMark"',
            'the completed Winning_Lines' => '"winningLines"',
            'the Join_Code as a prop' => '"joinCode"',
            'the Version_Counter' => '"version"',
            'the Game_Id of the Rematch as a prop' => '"rematchGameId"',
            'when the last Move was accepted' => '"lastMoveAt"',
        ],
    ];
}

/**
 * The Player_Token `$shape` presents, or null for the shape that presents none.
 *
 * Throws on an unknown shape rather than falling back to null: a mistyped dataset key
 * would otherwise turn an elsewhere-bound cell into a second tokenless one, halving the
 * matrix silently.
 */
function visibilityTokenFor(string $shape, MintedToken $unrecognised, MintedToken $elsewhere): ?MintedToken
{
    return match ($shape) {
        VISIBILITY_NO_TOKEN => null,
        VISIBILITY_UNRECOGNISED => $unrecognised,
        VISIBILITY_BOUND_ELSEWHERE => $elsewhere,
        default => throw new RuntimeException("there is no session shape named {$shape}"),
    };
}

/**
 * Starts a fresh Player_Session holding `$token` for `$gameId`, or holding nothing.
 *
 * Fresh rather than cleared, so a value another shape put in the session cannot survive
 * into this one. Session attributes persist across requests within a test —
 * `Store::start()` merges what the handler returns into what the store already holds —
 * which is what makes the shapes below reach the requests at all, and also what makes
 * this flush necessary between them.
 */
function visibilitySession(string $gameId, ?MintedToken $token): void
{
    Session::flush();

    // `Store::isValidId()` accepts 40 alphanumeric characters and silently replaces
    // anything else with a generated id, which would make a failed switch look like a
    // successful one.
    Session::setId(Str::random(40));
    Session::start();

    if ($token !== null) {
        (new PlayerTokens)->remember($gameId, $token);
    }
}

/**
 * The request `$route` names, for `$gameId`.
 *
 * `$cellIndex` is sent only on the move route. It is not validated by a Form Request
 * anywhere in the application (Req 4.4), so it arrives uncast and a value outside the
 * Board is a payload the move route would otherwise refuse.
 *
 * @return TestResponse<Response>
 */
function visibilityRequest(string $route, string $gameId, int $cellIndex = 0): TestResponse
{
    return match ($route) {
        'GET /games/{game}' => get('/games/'.$gameId),
        'POST /games/{game}/moves' => post('/games/'.$gameId.'/moves', ['cell_index' => $cellIndex]),
        'POST /games/{game}/rematch' => post('/games/'.$gameId.'/rematch'),
        default => throw new RuntimeException("there is no game-scoped route named {$route}"),
    };
}

/**
 * The Inertia props a response carries, read from the real payload.
 *
 * @param  TestResponse<Response>  $response
 * @return array<string, mixed>
 */
function visibilityPropsOf(TestResponse $response): array
{
    $page = AssertableInertia::fromTestResponse($response)->toArray();

    return is_array($page['props'] ?? null) ? $page['props'] : [];
}

/**
 * Everything `$response` discloses, as a label-to-explanation map. Empty is the answer
 * Property 7 requires of a refusal.
 *
 * Three independent searches, because a leak could arrive by three routes: a prop the
 * refusal is not entitled to, whatever its name; a high-entropy fixture value anywhere
 * in the body or the headers; and a representation key name in the props. The prop audit
 * is an allow-list of `errors` — Inertia's own shared prop — and `outcome`, so a `game`
 * prop, or a Board smuggled under an innocent name, is reported either way.
 *
 * @param  TestResponse<Response>  $response
 * @param  array<string, string>  $inBody
 * @param  array<string, string>  $inPayload
 * @return array<string, string>
 */
function visibilityDisclosuresIn(TestResponse $response, array $inBody, array $inPayload): array
{
    $props = visibilityPropsOf($response);
    $payload = (string) json_encode($props);
    $body = (string) $response->getContent().' '.(string) json_encode($response->headers->all());

    $found = [];

    foreach (array_keys($props) as $prop) {
        if (! in_array((string) $prop, ['errors', 'outcome'], true)) {
            $found['a prop named `'.$prop.'`'] = 'the response carries a prop named `'.$prop.'`';
        }
    }

    foreach ($inBody as $label => $needle) {
        if (str_contains($body, $needle)) {
            $found[$label] = 'the response body or its headers disclose '.$label;
        }
    }

    foreach ($inPayload as $label => $needle) {
        if (str_contains($payload, $needle)) {
            $found[$label] = 'the response props disclose '.$label;
        }
    }

    return $found;
}

/**
 * Asserts that the Game the fixture built is the Game the cell reasons about: the state
 * it claims, the Move_List it claims, and a Rematch where it claims one.
 *
 * Read from the tables rather than from the fixture's own arrays, so this cannot pass
 * against a row that was never written or a Move_List that never landed.
 *
 * @param  list<int>  $cells
 */
function visibilityFixtureHolds(string $gameId, GameState $state, array $cells, ?string $rematchId): void
{
    expect(DB::table('games')->where('id', $gameId)->value('state'))->toBe($state->value, 'the fixture Game is not in the Game_State this cell claims, so the cell is not the one it names')
        ->and(visibilityPersistedCells($gameId))->toBe($cells, 'the fixture Move_List is not the one this cell reasons about, so there may be no Board and no Move_List to withhold')
        ->and(DB::table('games')->where('rematch_of_game_id', $gameId)->count())->toBe(
            $rematchId === null ? 0 : 1,
            'the fixture Rematch is not what this cell claims, so the response is not being searched for a Rematch Game_Id that exists',
        );
}

/**
 * Asserts that `$response` refused with `not_authorised` and disclosed none of the five
 * things Property 7 names.
 *
 * The status and the outcome prop are asserted before the searches, and they are also
 * the non-vacuity guard on them: a response with an empty body or no props could not
 * satisfy them, so the searches below run against a payload that is really there.
 *
 * @param  TestResponse<Response>  $response
 * @param  array<string, string>  $inBody
 * @param  array<string, string>  $inPayload
 */
function visibilityRefusalDisclosesNothing(TestResponse $response, array $inBody, array $inPayload, string $cell): void
{
    $props = visibilityPropsOf($response);
    $body = (string) $response->getContent();
    $found = visibilityDisclosuresIn($response, $inBody, $inPayload);

    expect($response->getStatusCode())->toBe(StatusCodes::HTTP_FORBIDDEN, "{$cell} was not refused with 403 (Req 3.3, 3.4, 9.6)")
        ->and($props['outcome'] ?? null)->toBe('not_authorised', "{$cell} did not report the not_authorised outcome (Req 3.3, 3.4, 3.9, 9.6)")
        ->and($body)->not->toBe('', "{$cell} answered with an empty body, so the searches over it would prove nothing")
        ->and(array_key_exists('game', $props))->toBeFalse("{$cell} carries a game prop (Req 3.10)")
        ->and($found)->toBe([], "{$cell} disclosed what Requirement 3.10 excludes: ".implode('; ', $found));
}

/**
 * The matrix: every Game_State × every route naming a Game_Id × both unauthorised
 * session shapes, one dataset case each so a failure names its own cell.
 *
 * @return array<string, array{GameState, string, string}>
 */
function visibilityCells(): array
{
    $cells = [];

    foreach (GameState::cases() as $state) {
        foreach (visibilityRoutes() as $route) {
            foreach ([VISIBILITY_NO_TOKEN, VISIBILITY_BOUND_ELSEWHERE] as $shape) {
                $cells[$state->value.' · '.$route.' · '.$shape] = [$state, $route, $shape];
            }
        }
    }

    return $cells;
}

/*
 * ─────────────────────────────────────────────────────────────────────────────
 * THE MATRIX (Req 3.3, 3.4, 3.7, 3.9, 3.10, 8.7, 9.6, 14.6).
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Twenty-four cells. Each drives one real route against one Game_State with one
 * unauthorised session, and asserts the `not_authorised` outcome together with the
 * absence of the Board, the Move_List, the Game_State, the Mark_To_Move and every
 * Player_Token value.
 *
 * The move route is sent two payloads: a Cell that would have been a valid Move for
 * the Mark to move, and a Cell_Index outside the Board. That pair is what makes
 * "authorisation precedes validity" observable (Req 3.9) rather than asserted — a
 * validity check running first would distinguish them — so the two responses are
 * compared to each other as well as refused. On the drawn board there is no free Cell at
 * all, so the first payload is Cell 0, which is occupied; the `active` cell is where the
 * pair is genuinely (valid, invalid), which is why the guard below asserts the free Cell
 * is free in the persisted Move_List.
 *
 * The Game_Id is deliberately not among the needles: it is not game state under
 * Requirement 3.10, and it arrives in the URL of the request being refused.
 */
it('refuses every game state on every route naming a game id, disclosing nothing', function (GameState $state, string $route, string $shape) {
    $fixture = visibilityFixture($state);
    $game = $fixture['game'];
    $cell = $state->value.' · '.$route.' · '.$shape;

    visibilityFixtureHolds($game->id, $state, $fixture['cells'], $fixture['rematchId']);

    $presented = visibilityTokenFor($shape, $fixture['unrecognised'], $fixture['elsewhere']);

    // Non-vacuity on the elsewhere-bound shape: the token really is bound to the other
    // Game, so the shape is what it claims rather than a second unrecognised token.
    if ($shape === VISIBILITY_BOUND_ELSEWHERE) {
        expect((new PlayerTokens)->resolve($fixture['otherGame'], $fixture['elsewhere']->raw))
            ->toBe(Mark::X, 'the token this session presents is not bound to the other Game, so this cell is not the bound-elsewhere shape (Req 3.4)');
    }

    visibilitySession($game->id, $presented);

    $needles = $fixture['bodyNeedles'];

    if ($presented !== null) {
        $needles['the Player_Token the session presented'] = $presented->raw;
    }

    $wouldBeValid = $fixture['freeCell'] ?? 0;

    expect($fixture['freeCell'] === null || ! in_array($fixture['freeCell'], visibilityPersistedCells($game->id), true))
        ->toBeTrue('the Cell this cell calls free is occupied in the persisted Move_List, so the move payload below would have been refused as invalid whatever the credential (Req 3.9)');

    if ($route !== 'POST /games/{game}/moves') {
        visibilityRefusalDisclosesNothing(visibilityRequest($route, $game->id), $needles, $fixture['payloadNeedles'], $cell);

        return;
    }

    $valid = visibilityRequest($route, $game->id, $wouldBeValid);
    $invalid = visibilityRequest($route, $game->id, 99);

    visibilityRefusalDisclosesNothing($valid, $needles, $fixture['payloadNeedles'], $cell.' · a Cell that would have been a valid Move');
    visibilityRefusalDisclosesNothing($invalid, $needles, $fixture['payloadNeedles'], $cell.' · a Cell_Index outside the Board');

    expect((string) $invalid->getContent())->toBe(
        (string) $valid->getContent(),
        $cell.': a Move that would have been valid and one that would not were answered differently, so a validity condition was evaluated before authorisation was settled (Req 3.9)',
    );
})->with(fn (): array => visibilityCells());

/*
 * ─────────────────────────────────────────────────────────────────────────────
 * THE THREE FAILURE MODES ARE INDISTINGUISHABLE (Req 9.6), ON EVERY ROUTE AND IN
 * EVERY GAME_STATE.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Absent, unrecognised and bound elsewhere, compared as an equivalence between the
 * three responses rather than as three expectations naming one constant, so an edit
 * giving one mode its own answer fails here even if that mode's own cell above was
 * updated to match.
 *
 * Status and body are compared byte for byte. Headers are excluded and only headers: the
 * `Set-Cookie` for the Player_Session differs by construction, since each mode starts a
 * session of its own, and so does the `XSRF-TOKEN` cookie the framework issues with it.
 * Nothing else varies — the Inertia page object carries the component, the props, the
 * request URL and the asset version, and all four are the same for three requests to one
 * URL.
 *
 * `GameResolverTest` and `ResolveActingPlayerTest` compare the same three modes at the
 * resolver and on a stand-in route; what is new here is the application's own routes,
 * every Game_State, and a Game with a Rematch.
 */
it('answers the three failure modes with identical responses on every route naming a game id', function (GameState $state, string $route) {
    $fixture = visibilityFixture($state);
    $game = $fixture['game'];
    $tokens = new PlayerTokens;

    visibilityFixtureHolds($game->id, $state, $fixture['cells'], $fixture['rematchId']);

    $cellIndex = $fixture['freeCell'] ?? 0;

    /** @var array<string, TestResponse<Response>> $responses */
    $responses = [];

    foreach ([VISIBILITY_NO_TOKEN, VISIBILITY_UNRECOGNISED, VISIBILITY_BOUND_ELSEWHERE] as $shape) {
        $presented = visibilityTokenFor($shape, $fixture['unrecognised'], $fixture['elsewhere']);

        visibilitySession($game->id, $presented);

        $responses[$shape] = visibilityRequest($route, $game->id, $cellIndex);
    }

    // The two modes that are not "no token at all" are what they claim: one bound to
    // nothing, one genuinely bound to the other Game. Without this, both would be mode
    // one again and the equality below would be three identical requests.
    expect($tokens->resolve($game, $fixture['unrecognised']->raw))->toBeNull('the unrecognised token resolves against the target Game, so it is not unrecognised')
        ->and($tokens->resolve($fixture['otherGame'], $fixture['unrecognised']->raw))->toBeNull('the unrecognised token is bound to the other Game, so it is the bound-elsewhere mode rather than the unrecognised one')
        ->and($tokens->resolve($fixture['otherGame'], $fixture['elsewhere']->raw))->toBe(Mark::X, 'the elsewhere-bound token is not bound to the other Game, so that mode is not what it claims (Req 3.4)')
        ->and($tokens->resolve($game, $fixture['elsewhere']->raw))->toBeNull('the elsewhere-bound token also matches a slot on the target Game, so it is not bound elsewhere');

    $absent = $responses[VISIBILITY_NO_TOKEN];
    $reference = (string) $absent->getContent();

    expect($reference)->not->toBe('', 'the refusal has an empty body, so comparing the three modes byte for byte would prove nothing')
        ->and(str_contains($reference, 'not_authorised'))->toBeTrue('the refusal body does not carry the outcome, so it is not the refused response the three modes are being compared through');

    foreach ([VISIBILITY_UNRECOGNISED, VISIBILITY_BOUND_ELSEWHERE] as $shape) {
        expect($responses[$shape]->getStatusCode())->toBe(
            $absent->getStatusCode(),
            $state->value.' · '.$route.': '.$shape.' was answered with a different status than a session holding no token, so the two failure modes are distinguishable (Req 9.6)',
        )->and((string) $responses[$shape]->getContent())->toBe(
            $reference,
            $state->value.' · '.$route.': '.$shape.' was answered with a different body than a session holding no token, so the two failure modes are distinguishable (Req 3.4, 9.6)',
        );
    }
})->with(function (): array {
    $cases = [];

    foreach (GameState::cases() as $state) {
        foreach (visibilityRoutes() as $route) {
            $cases[$state->value.' · '.$route] = [$state, $route];
        }
    }

    return $cases;
});

/*
 * ─────────────────────────────────────────────────────────────────────────────
 * THE MATRIX COVERS WHAT IT CLAIMS TO COVER.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * The two axes are asserted against the application rather than against this file's own
 * lists: the four Game_States come from the enum, and the routes naming a Game_Id are
 * collected from the registered routes. A fifth Game_State or a fourth game-scoped route
 * fails here, which is the only reason the datasets above may be written out by hand.
 */
it('sweeps every game state against every route naming a game id', function () {
    $registered = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! str_contains($route->uri(), '{'.ResolveActingPlayer::ROUTE_PARAMETER.'}')) {
            continue;
        }

        foreach ($route->methods() as $method) {
            if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                continue;
            }

            $registered[] = $method.' /'.$route->uri();
        }
    }

    sort($registered);

    $swept = visibilityRoutes();
    sort($swept);

    expect($registered)->toBe($swept, 'the routes naming a Game_Id are not the ones this file sweeps: '.implode(', ', $registered))
        ->and(GameState::cases())->toHaveCount(4, 'there are no longer four Game_States (Req 6.1), so the state axis of the matrix is incomplete')
        ->and(visibilityCells())->toHaveCount(24, 'the matrix is not four Game_States by three routes by two session shapes');
});

/*
 * ─────────────────────────────────────────────────────────────────────────────
 * THE DETECTOR FIRES WHEN THERE IS SOMETHING TO FIND.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * The same fixture, the same searches, and a session that IS a Player of the Game: the
 * one cell of the matrix that is authorised. Every disclosure the cells above assert the
 * absence of is asserted present here, so those assertions are known to be capable of
 * failing rather than merely passing.
 *
 * The Join_Code is expected only while `waiting_for_opponent`, because the
 * representation omits it once a Game has two Players, and the Rematch Game_Id only in a
 * Terminal_State, because that is where a Rematch can exist.
 *
 * One family cannot be shown to fire this way, and that is not a gap in the searches: no
 * legitimate response carries a Player_Token value (Req 8.7), so the authorised response
 * is asserted to disclose none either. What that leaves is the searches themselves, which
 * are the same `str_contains` over the same haystack as the twelve that do fire.
 */
it('reports every disclosure it searches for when the session is a player of the game', function (GameState $state) {
    $fixture = visibilityFixture($state);
    $game = $fixture['game'];

    visibilityFixtureHolds($game->id, $state, $fixture['cells'], $fixture['rematchId']);

    visibilitySession($game->id, $fixture['x']);

    $response = get('/games/'.$game->id);

    $response->assertOk();

    $found = visibilityDisclosuresIn(
        $response,
        [...$fixture['bodyNeedles'], 'the Player_Token the session presented' => $fixture['x']->raw],
        $fixture['payloadNeedles'],
    );

    $expected = [
        'a prop named `game`',
        'the Board',
        'the Move_List',
        'the Game_State',
        'the Mark_To_Move',
        'the viewing Player\'s own Mark',
        'whose turn it is',
        'the winning Mark',
        'the completed Winning_Lines',
        'the Version_Counter',
        'the Game_Id of the Rematch as a prop',
        'when the last Move was accepted',
    ];

    // The display form and not the stored one: the column holds ten characters and the
    // representation carries the hyphenated form, so the stored value appears in no
    // response and only serves as a needle.
    if ($state === GameState::WaitingForOpponent) {
        $expected[] = 'the Join_Code in its display form';
        $expected[] = 'the Join_Code as a prop';
    }

    if ($state->isTerminal()) {
        $expected[] = 'the Game_Id of the Rematch';
    }

    $missed = array_values(array_diff($expected, array_keys($found)));

    expect($missed)->toBe(
        [],
        $state->value.': the searches the matrix relies on found nothing in a response that carries it, so those cells prove nothing about: '.implode(', ', $missed),
    );

    // And the one family a legitimate response may never carry, which is why it is not
    // in the list above (Req 8.7).
    $tokens = array_values(array_filter(
        array_keys($found),
        static fn (string $label): bool => str_contains($label, 'Player_Token'),
    ));

    expect($tokens)->toBe([], 'a response to a Player of the Game disclosed a Player_Token value (Req 8.7): '.implode(', ', $tokens));
})->with(function (): array {
    $cases = [];

    foreach (GameState::cases() as $state) {
        $cases[$state->value] = [$state];
    }

    return $cases;
});
