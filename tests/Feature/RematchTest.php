<?php

declare(strict_types=1);

use App\Domain\TicTacToe\Mark;
use App\Games\GameState;
use App\Games\JoinCode;
use App\Games\MintedToken;
use App\Games\PlayerTokens;
use App\Games\RematchOutcome;
use App\Models\Game;
use App\Models\Move;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\Response as StatusCodes;

use function Pest\Laravel\post;

// Feature: remote-tic-tac-toe, Property 15: A Rematch is unique, swapped, and
// entered by presenting the preceding token
//
// Validates: Requirements 7.2, 7.3, 7.4, 7.5, 7.6, 7.8, 7.9, 7.14, 7.15
//
/*
 * Task 7.3 — `CreateRematch`, `CreateRematchController` and
 * `POST /games/{game}/rematch`, over the real HTTP surface.
 *
 * This is the only coverage the Rematch path has, so nothing below inherits a claim
 * from another file.
 *
 * Three claims are why it goes through HTTP rather than against the service:
 *
 *   - `not_authorised` (Req 7.11) is `GameResolver`'s answer, delivered by the
 *     `acting.player` middleware. `CreateRematch::handle()` takes a `Mark` rather
 *     than a `?Mark` so there is no "not a Player" value to pass, which makes the
 *     refusal expressible only as a request that never reaches the service.
 *   - The accepted redirect leaves the Game the request named, which happens nowhere
 *     else in this application and is where convergence becomes visible to a Player.
 *     `CreateRematch` returns a `ResolvedPlayer`; only the controller makes a location.
 *   - The per-request minting of Requirement 7.6 is a claim about a session. A
 *     service call takes whatever session is current; a request presents a
 *     credential.
 *
 * It suspends and resumes sessions rather than starting clean ones, unlike
 * `SubmitMoveTest`, because Requirement 7.6 is about the second Player's slot having
 * been NULL until they asked AND the first Player's credential still resolving
 * afterwards — which needs both sessions to exist at the end. One addition to
 * `ConcurrencyTest`'s shape: a request rewrites the session id (`StartSession`
 * re-reads it from a cookie the test client does not send), so the id to resume by is
 * re-captured after every POST rather than once.
 *
 * Deliberately not here. Requirements 7.1 and 7.13 are `RematchControl.tsx`, asserted
 * in task 6.7's Vitest suite. Requirement 7.12, `rematchGameId` in the
 * representation, is `GameRepresentationTest`'s. Requirement 7.7's negative half is
 * the `not_authorised` case below and its positive half is the swap. The `catch`
 * branch of `createRematchOf()` is a concurrency claim about two requests interleaved
 * around one insert, which is task 12.3's.
 */

uses(RefreshDatabase::class);

/**
 * Suspends the Player_Session in effect and resumes another, so the callers below are
 * two Players rather than one Player posting twice.
 *
 * The outgoing payload is written through the handler first because `Store::start()`
 * MERGES what the handler holds for the incoming id into the attributes already in
 * memory rather than replacing them: a switch that only changed the id would carry
 * the outgoing session's `player_tokens.*` key into the incoming one. Same mechanism
 * as `concurrencySwitchSession()`.
 *
 * `StartSession` sets the store's id from the session cookie on every request — a
 * cookie the test client does not send, so each request replaces the id with a freshly
 * generated one. The id a session must be resumed by is the id in effect AFTER its
 * last request, so every caller re-reads `Session::getId()` once the POST is made.
 *
 * @param  string|null  $id  An existing session id to resume, or null for a new one.
 * @return string The id now in effect.
 */
function rematchSwitchSession(?string $id = null): string
{
    Session::save();
    Session::flush();

    // 40 alphanumeric characters is what `Store::isValidId()` accepts; anything else
    // is silently replaced by a generated id, and two switches would then be
    // indistinguishable from two switches that failed.
    Session::setId($id ?? Str::random(40));
    Session::start();

    return Session::getId();
}

/**
 * A saved Game in `$state` whose Move_List is `$cells` recorded contiguously from
 * zero, together with the two Player_Tokens bound to it — and NOTHING in any session.
 *
 * The hashes are assigned directly rather than issued through a real
 * create-plus-join, because `PlayerTokens::issue()` writes whichever session happens
 * to be current and there are two Players here, and because no sequence of real
 * requests produces a `won` Game_State without playing a Game first. Both
 * `MintedToken`s come back so a test can decide which session presents which.
 *
 * `version_counter` is `1 + count($cells)` for a Game that has an opponent — one for
 * the join (Req 2.6) and one per accepted Move (Req 4.7) — so "it moved exactly once"
 * below is asserted against a value a real Game would carry rather than a round
 * number. `last_activity_at` is backdated because creating a Rematch must not move it
 * (Req 13.2), which a column already at `now()` could not show.
 *
 * @param  list<int>  $cells
 * @return array{game: Game, tokens: array{x: MintedToken, o: MintedToken}}
 */
function rematchFixture(GameState $state = GameState::Won, array $cells = [0, 3, 1, 4, 2]): array
{
    $tokens = new PlayerTokens;
    $x = $tokens->mint();
    $o = $tokens->mint();

    $game = new Game;
    $game->id = Str::uuid7()->toString();
    $game->join_code = JoinCode::generate()->stored;
    $game->state = $state;
    // The CHECK on `games` pairs `state = 'won'` with a non-null `winning_mark` in
    // the same row, and forbids one in every other state.
    $game->winning_mark = $state === GameState::Won ? Mark::X : null;
    $game->x_token_hash = $x->hash;
    // And the one-directional CHECK forbids an occupied O slot while a Game waits
    // for an opponent, which is also what that state means (Req 2.1).
    $game->o_token_hash = $state === GameState::WaitingForOpponent ? null : $o->hash;
    $game->version_counter = $state === GameState::WaitingForOpponent ? 0 : 1 + count($cells);
    $game->last_activity_at = now()->subMinutes(5);
    $game->save();

    foreach ($cells as $position => $cell) {
        $move = new Move;
        $move->game_id = $game->id;
        $move->cell_index = $cell;
        $move->sequence_index = $position;
        $move->save();
    }

    return ['game' => $game, 'tokens' => ['x' => $x, 'o' => $o]];
}

/**
 * Every column of a `games` row that a Rematch request must leave alone, read from the
 * table rather than through any model the subject returned, so a stale or
 * hand-assigned in-memory instance cannot make an assertion pass.
 *
 * `version_counter` is deliberately absent and `rematchVersionOf()` reads it instead:
 * it is the one column creation is required to move on the preceding row (Req 7.5), so
 * keeping it out is what lets a single comparison say "everything else is identical".
 *
 * @return array{state: string, winning_mark: string|null, join_code: string|null, x_token_hash: string|null, o_token_hash: string|null, rematch_of_game_id: string|null, last_activity_at: string}
 */
function rematchRowOf(string $gameId): array
{
    $row = (array) DB::table('games')->where('id', $gameId)->first();

    return [
        'state' => (string) $row['state'],
        'winning_mark' => is_string($row['winning_mark']) ? $row['winning_mark'] : null,
        'join_code' => is_string($row['join_code']) ? $row['join_code'] : null,
        'x_token_hash' => is_string($row['x_token_hash']) ? $row['x_token_hash'] : null,
        'o_token_hash' => is_string($row['o_token_hash']) ? $row['o_token_hash'] : null,
        'rematch_of_game_id' => is_string($row['rematch_of_game_id']) ? $row['rematch_of_game_id'] : null,
        'last_activity_at' => (string) $row['last_activity_at'],
    ];
}

/**
 * The Version_Counter of a row, read straight from the table.
 */
function rematchVersionOf(string $gameId): int
{
    $row = (array) DB::table('games')->where('id', $gameId)->first();

    return (int) $row['version_counter'];
}

/**
 * The token-hash column belonging to `$mark`: the whole of the record of which
 * Player a credential belongs to (Req 3.1), and therefore the column the swap of
 * Requirement 7.3 decides.
 *
 * @return 'x_token_hash'|'o_token_hash'
 */
function rematchSlotOf(Mark $mark): string
{
    return match ($mark) {
        Mark::X => 'x_token_hash',
        Mark::O => 'o_token_hash',
    };
}

/**
 * The ids of every Game recording `$precedingId` as the Game it is a Rematch of.
 *
 * A list rather than a count or a `sole()`, because Requirement 7.8 is "at most one"
 * and the assertion has to be able to report two: `sole()` would throw before an
 * expectation could name the failure, and a count says nothing about which row
 * survived. Ordered by `id`, which is UUIDv7 and so insertion order.
 *
 * @return list<string>
 */
function rematchIdsOf(string $precedingId): array
{
    $rows = DB::table('games')
        ->where('rematch_of_game_id', $precedingId)
        ->orderBy('id')
        ->pluck('id')
        ->all();

    return array_values(array_map(strval(...), $rows));
}

/**
 * The persisted Move_List of a Game as `[sequence_index => cell_index]`, ordered — so
 * one comparison pins the Cells, their Sequence_Indexes and the contiguity of those
 * indexes from zero at once (Req 7.14).
 *
 * @return array<int, int>
 */
function rematchMoveListOf(string $gameId): array
{
    $list = [];

    foreach (DB::table('moves')->where('game_id', $gameId)->orderBy('sequence_index')->get() as $row) {
        $list[(int) $row->sequence_index] = (int) $row->cell_index;
    }

    return $list;
}

/**
 * Every Game_Id the session in effect holds a Player_Token for.
 *
 * Read as a nested array, not as a flat key: `PlayerTokens` writes
 * `Session::put('player_tokens.'.$gameId, ...)` and `Store::put()` interprets the dot
 * as `Arr::set()` does, so the store holds one top-level `player_tokens` key whose
 * value is a Game_Id-keyed array. Filtering `Session::all()`'s top-level keys for the
 * prefix `player_tokens.` matches nothing ever, even when a token is held.
 *
 * @return list<string>
 */
function rematchTokenKeys(): array
{
    $held = Session::get('player_tokens', []);

    if (! is_array($held)) {
        return ['player_tokens (not an array of Game_Ids)'];
    }

    return array_map(strval(...), array_keys($held));
}

/**
 * `POST /games/{preceding}/rematch` in the session in effect, with no body — the whole
 * of the request shape the design gives this endpoint.
 *
 * It does not switch sessions, unlike `submitMovePost()`: every caller has already
 * established which Player it is, and the rejection cases need the flashed outcome to
 * survive into the assertion, which a switch would discard.
 *
 * @return TestResponse<Response>
 */
function rematchPost(string $precedingId): TestResponse
{
    return post('/games/'.$precedingId.'/rematch');
}

/**
 * The props of the Inertia page a response carries, read from the real payload.
 *
 * @param  TestResponse<Response>  $response
 * @return array<string, mixed>
 */
function rematchProps(TestResponse $response): array
{
    $page = AssertableInertia::fromTestResponse($response)->toArray();

    return is_array($page['props'] ?? null) ? $page['props'] : [];
}

/**
 * The Mark a session's stored Player_Token for `$gameId` resolves to against a FRESH
 * read of that row, or null if it resolves to neither slot.
 *
 * Both halves are read rather than remembered, which is what makes this the assertion
 * Requirement 7.6 needs: the token comes out of the session, so it is the credential
 * the browser holds and not a copy the test kept, and the row is re-read, so a hash
 * assigned in memory but never persisted resolves to nothing. Either failure would
 * leave a Player permanently unable to play their own Rematch, since a Player_Token
 * cannot be reissued (ADR-005, Req 12.10).
 */
function rematchResolvedMark(string $gameId): ?Mark
{
    $tokens = new PlayerTokens;

    $game = Game::query()->find($gameId);

    return $game === null ? null : $tokens->resolve($game, $tokens->heldFor($gameId));
}

/**
 * The SHA-256 of the Player_Token the session in effect holds for `$gameId`, which is
 * what the row's slot must contain, or null if it holds none.
 */
function rematchHeldHashFor(string $gameId): ?string
{
    $held = (new PlayerTokens)->heldFor($gameId);

    return $held === null ? null : hash('sha256', $held);
}

/*
 * ─────────────────────────────────────────────────────────────────────────────
 * 1. BOTH PLAYERS CONVERGE ON ONE REMATCH, IN EITHER ORDER, WITH THE MARKS
 *    SWAPPED AND EACH TOKEN MINTED AT ITS OWN REQUEST
 *    (Req 7.2, 7.3, 7.4, 7.5, 7.6, 7.8, 7.9, 7.14, 7.15 — Property 15).
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Three requests, in two distinct Player_Sessions, over one finished Game: the first
 * Player asks, asks again, then the second Player asks. All three converge on one
 * Rematch row.
 *
 * The dataset runs both orders because Requirement 7.3 holds "irrespective of which
 * Player requested the Rematch first", and the two orders reach genuinely different
 * rows: when X asks first, the CREATING request populates `o_token_hash` and leaves
 * `x_token_hash` NULL, and when O asks first it is the other way round.
 *
 * The central assertion is made twice, before each Player's request: the slot that
 * request will fill is NULL before it. That is the difference between minting per
 * request (Req 7.6) and minting both tokens at creation, which would
 * populate both slots at the first request and pass every other assertion here.
 *
 * The preconditions are asserted rather than assumed, because each is a way this could
 * pass for the wrong reason: the Game really is terminal with a five-Move Move_List,
 * no Rematch exists yet, and each session holds the preceding token for the Mark it
 * claims and nothing else.
 */
it('converges on one rematch with the marks swapped, minting each session its own token at its own request', function (Mark $asksFirst) {
    $fixture = rematchFixture();
    $preceding = $fixture['game'];

    // Derived the way Requirement 7.3 derives it: from the token that session
    // presents and from nothing else.
    $secondMark = $asksFirst->opponent();
    $firstOnRematch = $asksFirst->opponent();
    $secondOnRematch = $secondMark->opponent();

    $before = rematchRowOf($preceding->id);
    $versionBefore = rematchVersionOf($preceding->id);
    $movesBefore = rematchMoveListOf($preceding->id);

    // ---- Session one: the Player who held `$asksFirst`. ----
    rematchSwitchSession();
    (new PlayerTokens)->remember($preceding->id, $fixture['tokens'][$asksFirst->value]);

    expect($before['state'])->toBe(GameState::Won->value, 'the fixture Game is not in a Terminal_State, so a Rematch of it would be refused and nothing below is being tested (Req 7.2)')
        ->and($movesBefore)->toBe([0 => 0, 1 => 3, 2 => 1, 3 => 4, 4 => 2], 'the fixture Move_List is not the five Moves this test reasons about, so its survival says nothing (Req 7.14)')
        ->and($versionBefore)->toBe(6, 'the fixture Version_Counter is not the join plus five Moves, so "exactly one more" is being asserted against a round number')
        ->and(rematchIdsOf($preceding->id))->toBe([], 'a Rematch already exists for the fixture Game, so the first request below creates nothing (Req 7.2)')
        ->and(rematchResolvedMark($preceding->id))->toBe($asksFirst, 'the first session does not hold the preceding Player_Token for the Mark under test, so the swap asserted below is derived from the wrong credential (Req 7.7)')
        ->and(rematchTokenKeys())->toBe([$preceding->id], 'the first session holds Player_Tokens for something other than the preceding Game');

    // ---- The first request. ----
    $first = rematchPost($preceding->id);
    $sessionOne = Session::getId();

    $created = rematchIdsOf($preceding->id);

    expect($created)->toHaveCount(1, 'the first Rematch request did not create exactly one Game recording the preceding Game_Id (Req 7.2, 7.4, 7.8)');

    $rematchId = $created[0];

    $first->assertStatus(303)
        // The redirect leaves the Game the request named, which is how a Player
        // reaches a Rematch at all.
        ->assertRedirect(url('/games/'.$rematchId))
        ->assertSessionMissing('outcome')
        ->assertSessionHasNoErrors();

    $rematchRow = rematchRowOf($rematchId);

    expect($rematchId)->not->toBe($preceding->id, 'the Rematch is the preceding Game, so no new Game was created (Req 7.2)')
        ->and($rematchRow['state'])->toBe(GameState::Active->value, 'the Rematch was not created active (Req 7.2)')
        ->and($rematchRow['winning_mark'])->toBeNull('the Rematch was created with a winning Mark')
        ->and($rematchRow['join_code'])->toBeNull('the Rematch carries a Join_Code, which is not how a Rematch is reached (Req 7.2)')
        ->and($rematchRow['rematch_of_game_id'])->toBe($preceding->id, 'the Rematch does not record the Game_Id of the preceding Game (Req 7.4)')
        ->and(rematchVersionOf($rematchId))->toBe(0, "the Rematch's own Version_Counter did not start at 0 (Req 7.2)")
        ->and(rematchMoveListOf($rematchId))->toBe([], 'the Rematch was not created with an empty Move_List (Req 7.2)')
        // Req 7.3 and Req 7.6: the requester's own slot holds the digest of the token
        // now in their session, and the absent Player's slot is still NULL.
        ->and($rematchRow[rematchSlotOf($firstOnRematch)])->toBe(rematchHeldHashFor($rematchId), 'the slot for the swapped Mark does not hold the digest of the Player_Token in the requesting session (Req 7.3, 7.6)')
        ->and($rematchRow[rematchSlotOf($firstOnRematch)])->not->toBeNull('the requesting session was issued no Player_Token for the Rematch (Req 7.6)')
        ->and($rematchRow[rematchSlotOf($secondOnRematch)])->toBeNull('the absent Player\'s Rematch token was minted before that Player asked for it, which is the behaviour per-request minting exists to replace (Req 7.6)')
        ->and(rematchResolvedMark($rematchId))->toBe($firstOnRematch, 'the requesting session\'s Rematch token does not resolve to the opposite of the Mark it held in the preceding Game (Req 7.3, 7.6)')
        // Req 7.5 and Req 7.14.
        ->and(rematchVersionOf($preceding->id))->toBe($versionBefore + 1, "creating the Rematch did not increment the preceding Game's Version_Counter by exactly one (Req 7.5)")
        ->and(rematchRowOf($preceding->id))->toBe($before, 'creating the Rematch changed a column of the preceding Game other than its Version_Counter (Req 7.14, 13.2)')
        ->and(rematchMoveListOf($preceding->id))->toBe($movesBefore, 'the preceding Game\'s Move_List did not survive the creation of its Rematch (Req 7.14)');

    // ---- The same session asks again: idempotent (Req 7.9, 7.15). ----
    $repeat = rematchPost($preceding->id);
    $sessionOne = Session::getId();

    $repeat->assertStatus(303)
        ->assertRedirect(url('/games/'.$rematchId))
        ->assertSessionMissing('outcome');

    expect(rematchIdsOf($preceding->id))->toBe([$rematchId], 'a repeated request from the same Player created a second Rematch (Req 7.8, 7.9)')
        ->and(rematchVersionOf($rematchId))->toBe(0, 'a repeated request incremented the Rematch\'s own Version_Counter, which no criterion asks for')
        ->and(rematchVersionOf($preceding->id))->toBe($versionBefore + 1, "a repeated request incremented the preceding Game's Version_Counter a second time (Req 7.5, 7.9)")
        ->and(rematchRowOf($preceding->id))->toBe($before, 'a repeated request changed the preceding Game (Req 7.9, 7.14)')
        ->and(rematchMoveListOf($rematchId))->toBe([], 'a repeated request added a Move to the Rematch')
        // Re-minting replaces the hash in the requester's own slot, which is a
        // consequence worth having: it is how a Player who lost their
        // Rematch token but kept the preceding one recovers. It must not reach the
        // other slot.
        ->and(rematchResolvedMark($rematchId))->toBe($firstOnRematch, 'a repeated request left the session holding a Player_Token that no longer resolves to its Mark (Req 7.6, 7.9)')
        ->and(rematchRowOf($rematchId)[rematchSlotOf($secondOnRematch)])->toBeNull('a repeated request from one Player filled the ABSENT Player\'s slot (Req 7.6)');

    $firstPlayersToken = rematchHeldHashFor($rematchId);

    // ---- Session two: a different browser, holding the other preceding token. ----
    $sessionTwo = rematchSwitchSession();
    (new PlayerTokens)->remember($preceding->id, $fixture['tokens'][$secondMark->value]);

    expect($sessionTwo)->not->toBe($sessionOne, 'the two Players share one Player_Session, so nothing below is about a second Player arriving')
        ->and(rematchTokenKeys())->toBe([$preceding->id], 'the second session carries Player_Tokens from the first, so the two callers are not two distinct Players')
        ->and(rematchResolvedMark($preceding->id))->toBe($secondMark, 'the second session does not hold the preceding Player_Token for the other Mark (Req 7.7)')
        ->and(rematchResolvedMark($rematchId))->toBeNull('the second session already holds a Player_Token for the Rematch, so its request below mints nothing (Req 7.6)')
        // The same assertion as before the first request, now for the other Player:
        // the Rematch has existed for two requests and this slot is still empty.
        ->and(rematchRowOf($rematchId)[rematchSlotOf($secondOnRematch)])->toBeNull('the second Player\'s Rematch token existed before the second Player asked for it (Req 7.6)');

    // ---- The second request. ----
    $second = rematchPost($preceding->id);
    $sessionTwo = Session::getId();

    $second->assertStatus(303)
        // The convergence, as a Player observes it: the same location the first
        // Player was sent to, two requests earlier (Req 7.9, 7.15).
        ->assertRedirect(url('/games/'.$rematchId))
        ->assertSessionMissing('outcome');

    $final = rematchRowOf($rematchId);

    expect(rematchIdsOf($preceding->id))->toBe([$rematchId], 'the two Players did not converge on one Rematch (Req 7.8, 7.9, 7.15)')
        ->and($final[rematchSlotOf($secondOnRematch)])->toBe(rematchHeldHashFor($rematchId), 'the second Player\'s slot does not hold the digest of the Player_Token issued to their session at their request (Req 7.6)')
        ->and(rematchResolvedMark($rematchId))->toBe($secondOnRematch, 'the second Player\'s Rematch token does not resolve to the opposite of the Mark they held in the preceding Game (Req 7.3, 7.6)')
        ->and($final['x_token_hash'])->not->toBeNull('the Rematch has no X Player after both Players asked for it')
        ->and($final['o_token_hash'])->not->toBeNull('the Rematch has no O Player after both Players asked for it')
        ->and($final['x_token_hash'])->not->toBe($final['o_token_hash'], 'the two Players of the Rematch hold the same Player_Token, so they are not two Players (Req 3.1)')
        ->and(rematchVersionOf($rematchId))->toBe(0, "the second Player's request incremented the Rematch's own Version_Counter")
        // Requirement 7.5 over three requests: one increment, not three, because only
        // one of them created anything.
        ->and(rematchVersionOf($preceding->id))->toBe($versionBefore + 1, 'the preceding Game\'s Version_Counter moved other than exactly once across three Rematch requests (Req 7.5, 7.9, Property 12)')
        ->and(rematchRowOf($preceding->id))->toBe($before, 'three Rematch requests changed a column of the preceding Game other than its Version_Counter (Req 7.14, 13.2)')
        ->and(rematchMoveListOf($preceding->id))->toBe($movesBefore, 'the preceding Game\'s Move_List did not survive three Rematch requests (Req 7.14)');

    // ---- Back in session one: the first Player's credential is untouched. ----
    rematchSwitchSession($sessionOne);

    expect(Session::getId())->toBe($sessionOne)
        ->and(rematchTokenKeys())->toEqualCanonicalizing([$preceding->id, $rematchId], 'the first Player\'s session does not hold Player_Tokens for both the preceding Game and the Rematch')
        ->and(rematchHeldHashFor($rematchId))->toBe($firstPlayersToken, "the second Player's request replaced the Player_Token in the first Player's session")
        // The assertion the resume exists for. It could not be made against a copy the
        // test kept, because the copy would still hash to a value on a row that had
        // been rewritten.
        ->and(rematchResolvedMark($rematchId))->toBe($firstOnRematch, "the second Player's request unbound the first Player's Player_Token, locking them out of their own Rematch (Req 3.1, 7.6)");
})->with([
    'the X player asks first' => Mark::X,
    'the O player asks first' => Mark::O,
]);

/*
 * ─────────────────────────────────────────────────────────────────────────────
 * 2. A PRECEDING GAME THAT IS NOT IN A TERMINAL_STATE IS `invalid_state`, AND
 *    NOTHING IS WRITTEN (Req 7.10, Property 9).
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Both non-terminal states, since Requirement 7.10 draws no distinction between them.
 * The requester is the X Player in both cases, because the waiting state means there
 * is no O Player to be.
 *
 * Three sides to the refusal: a 303 back to the preceding game page with
 * `invalid_state` flashed and never a 4xx; nothing created; and nothing credentialled
 * — the session holds no Player_Token it did not already hold, which is what would
 * remain if the guard ran after the minting rather than before it.
 *
 * The accepted request at the end is what makes this an assertion about the state
 * rather than about a route that refuses everything.
 */
it('refuses a rematch of a game that is not in a terminal state and writes nothing', function (GameState $state, array $cells) {
    // The dataset's Cells arrive as a bare `array`, since a Pest dataset carries no
    // type information; narrowed here rather than at the fixture, which takes the
    // `list<int>` its Sequence_Indexes depend on.
    $fixture = rematchFixture($state, array_values(array_map(intval(...), $cells)));
    $preceding = $fixture['game'];

    $before = rematchRowOf($preceding->id);
    $versionBefore = rematchVersionOf($preceding->id);
    $movesBefore = rematchMoveListOf($preceding->id);

    rematchSwitchSession();
    (new PlayerTokens)->remember($preceding->id, $fixture['tokens']['x']);

    expect($before['state'])->toBe($state->value)
        ->and($state->isTerminal())->toBeFalse('the fixture Game is in a Terminal_State, so this case is not a rejection at all (Req 7.10)')
        ->and(rematchResolvedMark($preceding->id))->toBe(Mark::X, 'the session does not hold a preceding Player_Token, so the refusal below would be not_authorised rather than invalid_state (Req 7.11)')
        ->and(rematchIdsOf($preceding->id))->toBe([], 'a Rematch already exists for the fixture Game');

    rematchPost($preceding->id)
        ->assertStatus(303)
        // The PRECEDING Game, because there is no Rematch to redirect to.
        ->assertRedirect(url('/games/'.$preceding->id))
        ->assertSessionHas('outcome', RematchOutcome::InvalidState->value)
        ->assertSessionHasNoErrors();

    expect(rematchIdsOf($preceding->id))->toBe([], 'the refused request created a Rematch (Req 7.10, Property 9)')
        ->and(rematchRowOf($preceding->id))->toBe($before, 'the refused request changed the preceding Game row (Req 7.10, Property 9)')
        ->and(rematchVersionOf($preceding->id))->toBe($versionBefore, 'the refused request incremented the preceding Version_Counter (Req 7.5, 7.10, Property 12)')
        ->and(rematchMoveListOf($preceding->id))->toBe($movesBefore, 'the refused request changed the Move_List')
        ->and(rematchTokenKeys())->toBe([$preceding->id], 'the refused request left a Player_Token for a Game that was never created, so the token was minted before the state was checked (Req 7.10)');

    // ---- The same session, the same endpoint, a terminal Game: accepted. ----
    // `drawn` rather than `won` so no `winning_mark` is needed to satisfy the CHECK
    // that pairs the two, leaving the one column Requirement 7.10 turns on as the only
    // difference from the refusal above.
    DB::table('games')->where('id', $preceding->id)->update(['state' => GameState::Drawn->value]);

    rematchPost($preceding->id)
        ->assertStatus(303)
        ->assertSessionMissing('outcome');

    expect(rematchIdsOf($preceding->id))->toHaveCount(1, 'the request was refused for a Game in a Terminal_State too, so the refusal above is not about the Game_State (Req 7.10, 7.15)');
})->with([
    'still waiting for an opponent' => [GameState::WaitingForOpponent, []],
    'still active' => [GameState::Active, [0, 3]],
]);

/*
 * ─────────────────────────────────────────────────────────────────────────────
 * 3. A TOKENLESS SESSION IS `not_authorised` (Req 7.11, 9.6, 3.10).
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * The claim is that `POST /games/{game}/rematch` carries the `acting.player`
 * middleware at all: a route that forgot it would answer 500 from
 * `ResolveActingPlayer::resolved()`, or mint a stranger a token. Asserted through a
 * request rather than by reading the route table.
 *
 * The Game is finished, has both Players and has a Move_List, so a request that got
 * through would have something real to create and something real to disclose. The
 * accepted request at the end rules out a broken endpoint.
 */
it('refuses a tokenless session with not_authorised and creates no rematch', function () {
    $fixture = rematchFixture();
    $preceding = $fixture['game'];

    $before = rematchRowOf($preceding->id);
    $versionBefore = rematchVersionOf($preceding->id);
    $movesBefore = rematchMoveListOf($preceding->id);

    // A session holding nothing at all — Requirement 9.6's first failure mode.
    rematchSwitchSession();

    expect(rematchTokenKeys())->toBe([], 'the session under test holds a Player_Token, so it is not a tokenless one')
        ->and($before['x_token_hash'])->not->toBeNull('the fixture Game has no X Player, so there is no Player for the caller to be mistaken for')
        ->and($before['o_token_hash'])->not->toBeNull('the fixture Game has no O Player')
        ->and($movesBefore)->toHaveCount(5, 'the fixture Game has no Move_List, so a request that got through would have nothing to disclose');

    $refused = rematchPost($preceding->id);

    $refused->assertStatus(StatusCodes::HTTP_FORBIDDEN);

    $props = rematchProps($refused);

    expect($props['outcome'] ?? null)->toBe('not_authorised', 'a tokenless Rematch request was answered as something other than not_authorised (Req 7.11, 9.6)')
        ->and(array_key_exists('game', $props))->toBeFalse('the refusal carries a game prop (Req 3.10)')
        ->and(rematchIdsOf($preceding->id))->toBe([], 'a tokenless request created a Rematch (Req 7.11, Property 9)')
        ->and(rematchRowOf($preceding->id))->toBe($before, 'a tokenless request changed the preceding Game row (Req 7.11, Property 9)')
        ->and(rematchVersionOf($preceding->id))->toBe($versionBefore, 'a tokenless request incremented the preceding Version_Counter (Req 7.5, Property 12)')
        ->and(rematchMoveListOf($preceding->id))->toBe($movesBefore, 'a tokenless request changed the Move_List (Req 7.14)')
        ->and(rematchTokenKeys())->toBe([], 'a tokenless request was issued a Player_Token (Req 7.11)');

    // ---- The same endpoint, the same Game, a session holding the X Player's
    // preceding token: accepted. ----
    rematchSwitchSession();
    (new PlayerTokens)->remember($preceding->id, $fixture['tokens']['x']);

    rematchPost($preceding->id)
        ->assertStatus(303)
        ->assertSessionMissing('outcome');

    expect(rematchIdsOf($preceding->id))->toHaveCount(1, 'a Player of the Game was refused too, so the 403 above is not about the absent Player_Token (Req 7.11, 7.15)');
});
