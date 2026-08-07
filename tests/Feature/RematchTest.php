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
 * THIS IS THE ONLY COVERAGE THE REMATCH PATH HAS. Task 7.1 shipped the service, the
 * controller and the route with no committed test of any kind, so nothing below
 * inherits a claim from another file — each of the seven claims this file makes is
 * established here or nowhere.
 *
 * WHY THROUGH HTTP RATHER THAN AGAINST THE SERVICE. Two of the claims are not
 * expressible at the service level at all, and a third is weaker there.
 *
 *   - **`not_authorised` (Req 7.11)** is `GameResolver`'s answer, delivered by the
 *     `acting.player` middleware. `CreateRematch::handle()` takes a `Mark` rather
 *     than a `?Mark` precisely so that there is no "not a Player" value to pass, so
 *     a tokenless request cannot be *expressed* as a service call: the refusal is
 *     observable only as a request that never reaches the service.
 *   - **The accepted redirect leaves the Game the request named**, which is unique
 *     in this application and is where "both requests return the same Rematch"
 *     becomes visible to a Player. `CreateRematch` returns a `ResolvedPlayer`; only
 *     the controller turns it into a location.
 *   - **The per-request minting of Requirement 7.6** is a claim about a *session*.
 *     A service call takes whatever session happens to be current; a request
 *     presents a credential. The distinction is the whole of ADR-010.
 *
 * WHY IT SUSPENDS AND RESUMES SESSIONS RATHER THAN STARTING CLEAN ONES.
 * `SubmitMoveTest` starts a fresh session per request and says why: none of its
 * claims is about a stored credential surviving anything, only about which token a
 * request presents. Both of this file's central claims are the other kind.
 * Requirement 7.6 is that each Player is issued a token AT ITS OWN REQUEST — which
 * is a claim about the second Player's slot having been NULL until they asked, and
 * about the first Player's credential still resolving afterwards. That needs BOTH
 * sessions to still exist at the end, so `ConcurrencyTest`'s save-and-resume shape
 * is the faithful one here, with one addition it does not need: a request rewrites
 * the session id (`StartSession` re-reads it from a cookie the test client does not
 * send), so the id to resume by is re-captured AFTER every POST rather than once.
 *
 * WHAT IS DELIBERATELY NOT HERE. Requirement 7.1 and 7.13 are `RematchControl.tsx`,
 * which is task 7.2 and does not exist yet. Requirement 7.12 — `rematchGameId` in
 * the representation — is `GameRepresentation`'s and is asserted in
 * `GameRepresentationTest`. Requirement 7.7's negative half, that a session holding
 * no preceding token establishes no continuity, is the `not_authorised` case below;
 * its positive half is the swap. The `catch` branch of `createRematchOf()` is a
 * concurrency claim about two requests interleaved around one insert, which is
 * task 12.3's shape and not this file's.
 */

uses(RefreshDatabase::class);

/**
 * Suspends the Player_Session in effect and resumes another, so that two callers
 * below are two *Players* rather than one Player posting twice.
 *
 * The mechanism, and why each line is needed, is `concurrencySwitchSession()`'s: the
 * outgoing payload is written through the handler first, because `Store::start()`
 * MERGES what the handler holds for the incoming id into the attributes already in
 * memory rather than replacing them — so a switch that only changed the id would
 * carry the outgoing session's `player_tokens.*` key into the incoming one, and the
 * second Player would arrive already holding the first Player's credentials.
 *
 * WHAT IS DIFFERENT HERE, AND IT IS NOT OPTIONAL. This file makes real requests, and
 * `StartSession` sets the store's id from the session cookie on every one of them —
 * a cookie the test client does not send, so each request replaces the id with a
 * freshly generated one. The id a session must be resumed by is therefore the id in
 * effect AFTER its last request, not the one this function returned before it, and
 * every caller below re-reads `Session::getId()` once the POST has been made.
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
 * zero, together with the two Player_Tokens bound to it — and NOTHING in any
 * session.
 *
 * The tokens are minted and their hashes assigned directly rather than issued
 * through a real create-plus-join, because `PlayerTokens::issue()` writes whichever
 * session happens to be current and there are two Players here; and because the
 * Game_State is a parameter, which no sequence of real requests can produce for
 * `won` without playing a Game first. Both `MintedToken`s come back so a test can
 * decide which session presents which.
 *
 * `version_counter` is `1 + count($cells)` for a Game that has an opponent — one for
 * the join (Req 2.6) and one per accepted Move (Req 4.7) — so "it moved exactly
 * once" below is asserted against a value a real Game would carry rather than
 * against a round number. `last_activity_at` is backdated: creating a Rematch must
 * NOT move it (Req 13.2), and a column already at `now()` could not show that.
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
 * Every column of a `games` row that a Rematch request must leave alone, read
 * straight from the table rather than through any model the subject returned, so a
 * stale or hand-assigned in-memory instance cannot make an assertion pass.
 *
 * `version_counter` IS DELIBERATELY ABSENT, and `rematchVersionOf()` reads it
 * instead. It is the one column creation is *required* to move on the preceding row
 * (Req 7.5), so keeping it out of this array is what lets a single comparison say
 * "everything else is identical" — including `last_activity_at`, which Requirement
 * 13.2 forbids this operation from touching, and `winning_mark` and `state`, which
 * Requirement 7.14's neighbourhood requires to be exactly as the final Move left
 * them.
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
 * A LIST, AND NOT A COUNT OR A `sole()`. Requirement 7.8 is "at most one", so the
 * assertion has to be able to report two: `sole()` would throw before an expectation
 * could name the failure, and a count says nothing about *which* row survived when
 * the answer is one. Ordered by `id` — UUIDv7, so insertion order — which makes a
 * two-row failure legible rather than arbitrary.
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
 * READ AS A NESTED ARRAY, NOT AS A FLAT KEY, for the reason
 * `concurrencyTokenKeys()` records: `PlayerTokens` writes
 * `Session::put('player_tokens.'.$gameId, ...)`, and `Store::put()` interprets the
 * dot as `Arr::set()` does, so the store holds ONE top-level `player_tokens` key
 * whose value is a Game_Id-keyed array. A filter over `Session::all()`'s top-level
 * keys for the prefix `player_tokens.` matches nothing ever — including when a token
 * is held — which is an assertion that cannot fail.
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
 * `POST /games/{preceding}/rematch` in the session in effect, with no body — which is
 * the whole of the request shape the design gives this endpoint.
 *
 * IT DOES NOT SWITCH SESSIONS, unlike `submitMovePost()`. Every caller below has
 * already established which Player it is, and the rejection cases need the flashed
 * outcome to survive into the assertion, which a switch would discard.
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
 * BOTH HALVES ARE READ RATHER THAN REMEMBERED, and that is what makes this the
 * assertion Requirement 7.6 needs. The token comes out of the session by way of
 * `PlayerTokens::heldFor()`, so it is the credential the browser actually holds and
 * not a copy the test kept; the row is re-read from the database, so a hash the
 * subject assigned in memory but never persisted resolves to nothing. A session
 * entry whose hash was never written, or a hash written for a slot the session was
 * not told about, each fail here — and either would leave a Player permanently
 * unable to play their own Rematch, since a Player_Token cannot be reissued
 * (ADR-005, Req 12.10).
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
 * Player asks, asks again, and then the second Player asks. All three converge on
 * one Rematch row.
 *
 * THE DATASET RUNS IT IN BOTH ORDERS, and the two runs are not the same test with the
 * letters exchanged. Requirement 7.3 says the swap holds "irrespective of which
 * Player requested the Rematch first", and the two orders exercise genuinely
 * different rows of the table: when X asks first, the CREATING request populates
 * `o_token_hash` and leaves `x_token_hash` NULL — the asymmetry an earlier draft's
 * CHECK would have rejected outright, and the one a reader is most likely to think
 * impossible. When O asks first it is the other way round, which is the shape that
 * would still pass a test written only for the first.
 *
 * THE ASSERTION THIS TASK EXISTS FOR is the one made twice before each Player's
 * request: the slot that request will fill IS NULL BEFORE IT. That is the difference
 * between minting per request (ADR-010, Req 7.6) and minting both tokens at creation
 * — the impossible behaviour an earlier draft of Requirement 7 specified, recorded in
 * `docs/ai-direction.md`. An implementation that folded the minting back into the
 * insert would populate both slots at the first request and pass every other
 * assertion here.
 *
 * The preconditions are asserted rather than assumed, because each is a way this
 * could pass for the wrong reason: the Game really is terminal with a five-Move
 * Move_List, no Rematch exists yet, and each session holds the preceding token for
 * the Mark it claims to and nothing else.
 */
it('converges on one rematch with the marks swapped, minting each session its own token at its own request', function (Mark $asksFirst) {
    $fixture = rematchFixture();
    $preceding = $fixture['game'];

    // The Mark each session held in the preceding Game, and the Mark each must
    // therefore be assigned on the Rematch — DERIVED HERE THE WAY REQUIREMENT 7.3
    // derives it, from the token that session presents and from nothing else.
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
        // THE REDIRECT LEAVES THE GAME THE REQUEST NAMED, which happens nowhere else
        // in the application, and it is how a Player reaches a Rematch at all.
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
        // Req 7.3 and Req 7.6, in one pair of assertions: the requester's own slot
        // holds the digest of the token now in their session, and THE ABSENT
        // PLAYER'S SLOT IS STILL NULL.
        ->and($rematchRow[rematchSlotOf($firstOnRematch)])->toBe(rematchHeldHashFor($rematchId), 'the slot for the swapped Mark does not hold the digest of the Player_Token in the requesting session (Req 7.3, 7.6)')
        ->and($rematchRow[rematchSlotOf($firstOnRematch)])->not->toBeNull('the requesting session was issued no Player_Token for the Rematch (Req 7.6)')
        ->and($rematchRow[rematchSlotOf($secondOnRematch)])->toBeNull('the absent Player\'s Rematch token was minted before that Player asked for it, which is the behaviour ADR-010 exists to replace (Req 7.6)')
        ->and(rematchResolvedMark($rematchId))->toBe($firstOnRematch, 'the requesting session\'s Rematch token does not resolve to the opposite of the Mark it held in the preceding Game (Req 7.3, 7.6)')
        // Req 7.5 and Req 7.14: the preceding Game moved its Version_Counter once
        // and nothing else about it moved at all.
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
        // Re-minting replaces the hash in the requester's own slot, which ADR-010
        // names as a consequence worth having — it is how a Player who lost their
        // Rematch token but kept the preceding one recovers. What it must NOT do is
        // reach the other slot.
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
        // THE SAME ASSERTION AS BEFORE THE FIRST REQUEST, NOW FOR THE OTHER PLAYER,
        // AND AT THE MOMENT IT MATTERS MOST: the Rematch has existed for two
        // requests and this Player's slot is STILL empty.
        ->and(rematchRowOf($rematchId)[rematchSlotOf($secondOnRematch)])->toBeNull('the second Player\'s Rematch token existed before the second Player asked for it (Req 7.6, ADR-010)');

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
        // THE WHOLE OF REQUIREMENT 7.5 OVER THREE REQUESTS: one increment, not
        // three, because only one of them created anything.
        ->and(rematchVersionOf($preceding->id))->toBe($versionBefore + 1, 'the preceding Game\'s Version_Counter moved other than exactly once across three Rematch requests (Req 7.5, 7.9, Property 12)')
        ->and(rematchRowOf($preceding->id))->toBe($before, 'three Rematch requests changed a column of the preceding Game other than its Version_Counter (Req 7.14, 13.2)')
        ->and(rematchMoveListOf($preceding->id))->toBe($movesBefore, 'the preceding Game\'s Move_List did not survive three Rematch requests (Req 7.14)');

    // ---- Back in session one: the first Player's credential is untouched. ----
    rematchSwitchSession($sessionOne);

    expect(Session::getId())->toBe($sessionOne)
        ->and(rematchTokenKeys())->toEqualCanonicalizing([$preceding->id, $rematchId], 'the first Player\'s session does not hold Player_Tokens for both the preceding Game and the Rematch')
        ->and(rematchHeldHashFor($rematchId))->toBe($firstPlayersToken, "the second Player's request replaced the Player_Token in the first Player's session")
        // The assertion that matters most, and the one the resume exists for: the
        // second Player's arrival did not take the first Player's slot. It could
        // not be made against a copy the test kept, because the copy would still
        // hash to a value on a row that had been rewritten.
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
 * Both non-terminal states, because Requirement 7.10 draws no distinction between
 * them and neither does the outcome vocabulary: a Game still `waiting_for_opponent`
 * and a Game still `active` are refused by the same value. The requester is the X
 * Player in both cases, since the waiting state means there is no O Player to be.
 *
 * THE REFUSAL IS ASSERTED FROM THREE SIDES. The transport is the design's — a 303
 * back to the PRECEDING game page, the one the request named, with `invalid_state`
 * flashed, never a 4xx. Nothing was created: no row records the Game as its
 * predecessor. And nothing was credentialled: the session holds no Player_Token it
 * did not already hold, which is what would remain if the guard ran after the minting
 * rather than before it.
 *
 * THE CONTRAST AT THE END IS WHAT MAKES THIS AN ASSERTION ABOUT THE STATE rather than
 * about a route that refuses everything: the same session posts to the same endpoint
 * again with only the Game_State changed to `drawn`, and is accepted. Without it,
 * every assertion above would also hold for a broken endpoint.
 */
it('refuses a rematch of a game that is not in a terminal state and writes nothing', function (GameState $state, array $cells) {
    // The dataset's Cells arrive as a bare `array`, since a Pest dataset carries no
    // type information; they are narrowed here rather than at the fixture, which
    // takes the `list<int>` its Sequence_Indexes depend on.
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
    // `drawn` rather than `won`, so no `winning_mark` is needed to satisfy the CHECK
    // that pairs the two, and so the only difference from the refusal above is the
    // one column Requirement 7.10 turns on.
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
 * OBSERVABLE ONLY THROUGH HTTP, WHICH IS WHY IT IS HERE. This is `GameResolver`'s
 * answer, thrown by the `acting.player` middleware before `CreateRematch` is
 * constructed, let alone called. `CreateRematch::handle()` takes a `Mark` rather than
 * a `?Mark` precisely so that there is no "not a Player" value to pass it, so the
 * refusal cannot be expressed as a service call at all: it is a request that never
 * reaches the service.
 *
 * The Game is finished, has both Players and has a Move_List, so a request that got
 * through would have something real to create and something real to disclose. That
 * `POST /games/{game}/rematch` carries the middleware at all is the claim — a route
 * that forgot it would answer 500 from `ResolveActingPlayer::resolved()`, or worse,
 * mint a stranger a token — and it is asserted through a request rather than by
 * reading the route table.
 *
 * The contrast at the end is the same one as above: the same POST, from a session
 * holding the X Player's preceding token, is accepted. Without it the 403 could
 * equally be a broken endpoint.
 */
it('refuses a tokenless session with not_authorised and creates no rematch', function () {
    $fixture = rematchFixture();
    $preceding = $fixture['game'];

    $before = rematchRowOf($preceding->id);
    $versionBefore = rematchVersionOf($preceding->id);
    $movesBefore = rematchMoveListOf($preceding->id);

    // A session that holds nothing at all: not a token for another Game, not an
    // expired one. Requirement 9.6's first failure mode.
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
