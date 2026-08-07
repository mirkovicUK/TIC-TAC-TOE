<?php

declare(strict_types=1);

use App\Domain\TicTacToe\Mark;
use App\Games\GameState;
use App\Games\JoinCode;
use App\Games\JoinGame;
use App\Games\JoinOutcome;
use App\Games\PlayerTokens;
use App\Games\ResolvedPlayer;
use App\Models\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

// Feature: remote-tic-tac-toe, Property 13: Joining is exclusive
//
// Validates: Requirements 2.7, 14.9
//
/*
 * Task 5.8 — the join race, written the way Requirement 14.9 requires it to be
 * written: THE STATE EACH REQUEST WOULD OBSERVE IS ESTABLISHED FIRST, AND THE
 * REQUESTS ARE THEN SUBMITTED ONE AFTER ANOTHER. No process is spawned, no second
 * connection is opened, nothing sleeps, and no assertion depends on an ordering
 * the scheduler chooses — so the test either always passes or always fails.
 *
 * WHY A SEQUENTIAL TEST IS A FAITHFUL MODEL OF THE CONCURRENT CASE HERE. The
 * whole of the concurrency control is the affected-row count of one guarded
 * `UPDATE ... WHERE state = 'waiting_for_opponent' AND o_token_hash IS NULL`
 * (ADR-006). Nothing in `JoinGame` re-reads the row between the Join_Code lookup
 * and that statement, so the second caller's guard evaluates against the row the
 * first caller left behind whether the two calls are microseconds or minutes
 * apart. Calling in sequence therefore does not simulate the loser path — IT
 * TAKES IT, by the same mechanism and through the same branch a genuinely
 * concurrent loser takes. Were a `SELECT` of `state` ever added in front of the
 * UPDATE, the two shapes would diverge, and the query-log test in
 * `JoinGameTest` is what fails then.
 *
 * WHAT THIS FILE ADDS THAT `JoinGameTest` DOES NOT. That file covers the
 * mechanism at close range: the affected-row count observed to be zero when the
 * guarded statement is run by hand against a claimed slot, and the absence of any
 * credential after a losing call whose session was flushed. Neither is the claim
 * Requirement 2.7 makes. This is the BEHAVIOURAL claim over TWO DISTINCT
 * PLAYER_SESSIONS THAT BOTH REMAIN LIVE: session A is assigned `O`, session B is
 * refused `game_full`, and A's credential is verified afterwards from A's own
 * session rather than from a value the test held on to.
 *
 * Task 6.8 appends the move-conflict half to this file (Property 14). The
 * helpers below are named for the file rather than for the join path so that half
 * can reuse the session switch without renaming anything.
 */

uses(RefreshDatabase::class);

/**
 * Suspends the current Player_Session and resumes another, which is the whole of
 * what makes the two callers below two *Players* rather than one Player calling
 * twice.
 *
 * THIS IS LOAD-BEARING, AND A TEST WITHOUT IT WOULD ASSERT SOMETHING ELSE
 * ENTIRELY. `JoinGame` short-circuits when the requesting session already holds a
 * Player_Token for the Game (Req 2.4): a second call in session A's session
 * returns `ResolvedPlayer` with the Mark `O` — the Player being handed back their
 * own Game — and never reaches the guarded UPDATE at all. That is a correct
 * answer to a different question, and Requirement 2.7 is about two sessions.
 *
 * `Session::flush()` is not the same thing and is not enough. It empties the one
 * session in place, so afterwards there is no session A to return to and the
 * winner's credential can only be checked against a copy the test kept. Saving to
 * the handler and switching the id keeps BOTH sessions intact — which is what the
 * `player_tokens.*` key means: one browser, one server-side session, its own
 * tokens. `SESSION_DRIVER=array` retains each id's payload in the handler for the
 * lifetime of the test, so switching back resumes session A exactly as it was.
 *
 * @param  string|null  $id  An existing session id to resume, or null for a new one.
 * @return string The id now in effect.
 */
function concurrencySwitchSession(?string $id = null): string
{
    // Writes the outgoing session's payload through the handler, so resuming its
    // id later reads back what it held rather than an empty session.
    Session::save();

    // THEN CLEARS THE IN-MEMORY ATTRIBUTES, AND THE ORDER OF THESE TWO LINES IS
    // THE WHOLE OF THE SWITCH. `Store::start()` *merges* what the handler holds
    // for the incoming id into the attributes already in memory — it does not
    // replace them — so a switch that only changed the id would carry the
    // outgoing session's `player_tokens.*` key into the incoming one, and every
    // caller after the first would short-circuit as a Player of the Game. The
    // save above is what makes this safe: the outgoing payload is already through
    // the handler, so clearing memory loses nothing and resuming the id restores
    // it. Verified by the precondition assertions in the test below, which fail
    // if the incoming session is not empty.
    Session::flush();

    // 40 alphanumeric characters is what `Store::isValidId()` accepts; anything
    // else would be silently replaced by a generated id, and two calls would then
    // be indistinguishable from two calls that failed to switch.
    Session::setId($id ?? Str::random(40));
    Session::start();

    return Session::getId();
}

/**
 * A saved Game waiting for an opponent: the X slot occupied, the O slot free, and
 * NOTHING written to any session.
 *
 * The X token is minted and assigned directly rather than through
 * `PlayerTokens::issue()`, because `issue()` writes the session — and the session
 * it would write is whichever one happens to be current, which is exactly the
 * short-circuit this file must not trip. `last_activity_at` is backdated so an
 * accepted join visibly moves it and a refused one visibly does not.
 */
function concurrencyWaitingGame(): Game
{
    $game = new Game;
    $game->id = Str::uuid7()->toString();
    $game->join_code = JoinCode::generate()->stored;
    $game->state = GameState::WaitingForOpponent;
    $game->version_counter = 0;
    $game->x_token_hash = (new PlayerTokens)->mint()->hash;
    $game->last_activity_at = now()->subMinutes(5);
    $game->save();

    return $game;
}

/**
 * Every Game_Id the current session holds a Player_Token for.
 *
 * Asserted against instead of `Session::all()`, which is not empty in a fresh
 * session: `Store::start()` mints a CSRF `_token`, and that is not a credential
 * for anything. Restricting the assertion to the `player_tokens` namespace is also
 * the stronger claim — a losing join must leave NO Player_Token behind, not merely
 * none for the Game it was refused — and it reads the raw store rather than
 * `PlayerTokens::heldFor()`, which reports an empty or malformed value as null by
 * design and would therefore hide a key written with one.
 *
 * READ AS A NESTED ARRAY, NOT AS A FLAT KEY, and that is not a stylistic choice.
 * `PlayerTokens` writes `Session::put('player_tokens.'.$gameId, ...)`, and
 * `Store::put()` interprets the dot as `Arr::set()` does: the store holds one
 * top-level `player_tokens` key whose value is a Game_Id-keyed array. A filter over
 * `Session::all()`'s top-level keys for the prefix `player_tokens.` therefore
 * matches nothing ever — including when a token *is* held — which is an assertion
 * that cannot fail. This helper was written that way first and the vacuity was
 * caught by fixture: with a token deliberately remembered, the flat filter still
 * reported an empty list.
 *
 * @return list<string>
 */
function concurrencyTokenKeys(): array
{
    $held = Session::get('player_tokens', []);

    if (! is_array($held)) {
        return ['player_tokens (not an array of Game_Ids)'];
    }

    return array_map(strval(...), array_keys($held));
}

/**
 * The columns a join is allowed to move, read straight from the table rather than
 * through any model the subject returned, so a stale or hand-assigned in-memory
 * instance cannot make an assertion about the row pass.
 *
 * @return array{state: string, x_token_hash: string|null, o_token_hash: string|null, version_counter: int, last_activity_at: string}
 */
function concurrencyRowOf(string $gameId): array
{
    $row = (array) DB::table('games')->where('id', $gameId)->first();

    return [
        'state' => (string) $row['state'],
        'x_token_hash' => is_string($row['x_token_hash']) ? $row['x_token_hash'] : null,
        'o_token_hash' => is_string($row['o_token_hash']) ? $row['o_token_hash'] : null,
        'version_counter' => (int) $row['version_counter'],
        'last_activity_at' => (string) $row['last_activity_at'],
    ];
}

/*
 * THE JOIN RACE (Req 2.7, Req 14.9, Property 13).
 *
 * Two distinct Player_Sessions submit the same Join_Code for the same
 * `waiting_for_opponent` Game, one after another. Exactly one is assigned `O`; the
 * other is refused with `game_full` and holds no Player_Token afterwards.
 *
 * The preconditions are asserted, not assumed, because every one of them is a way
 * this test could pass for the wrong reason:
 *
 *   - The two session ids differ, and session B holds nothing at all. If the
 *     switch failed, B's call would short-circuit and return `ResolvedPlayer`,
 *     which fails the outcome assertion rather than passing it — but the
 *     precondition says *why*, instead of leaving a reader to work out that a
 *     `game_full` from a Player of the Game would be a different bug.
 *   - The Game really is waiting with a free O slot before A joins. A Game that
 *     was already `active` would answer `game_full` to B without A having won
 *     anything, and every assertion below it would still hold.
 *   - A's join really did claim the slot, incrementing the Version_Counter from 0
 *     to 1. `game_full` for B is only Requirement 2.7's loser path if there was a
 *     winner.
 *
 * The loser's refusal is then asserted from both ends. Nothing was written — the
 * row is compared column by column against the state A left, so a second UPDATE
 * that happened to be reported as a refusal fails here — and nothing was
 * credentialled: B's session is empty, and A's own session still holds the same
 * token, which still resolves to `O` against a freshly read row. That last
 * assertion is the one that matters most. The failure it guards against is not an
 * untidy session; it is an unguarded second write replacing `o_token_hash` and
 * locking the real O Player out of their own Game, unrecoverably, since a
 * Player_Token cannot be reissued (ADR-005, Req 12.10).
 */
it('assigns O to the first of two distinct sessions and refuses the second with game_full', function () {
    $tokens = new PlayerTokens;
    $join = new JoinGame($tokens);

    $game = concurrencyWaitingGame();
    $code = JoinCode::parse((string) $game->join_code)?->display();

    $before = concurrencyRowOf($game->id);

    $sessionA = concurrencySwitchSession();

    expect($before['state'])->toBe(GameState::WaitingForOpponent->value, 'the fixture Game is not waiting_for_opponent, so no join race is being tested (Req 2.7)')
        ->and($before['o_token_hash'])->toBeNull('the fixture Game already has an O Player, so B would be refused whatever A did')
        ->and($before['x_token_hash'])->not->toBeNull('the fixture Game has no X Player')
        ->and($before['version_counter'])->toBe(0)
        ->and($tokens->heldFor($game->id))->toBeNull('session A already holds a Player_Token, so its join would short-circuit (Req 2.4)');

    // ---- Session A joins. ----
    $a = $join->handle($code);

    $claimed = concurrencyRowOf($game->id);
    $tokenA = (string) $tokens->heldFor($game->id);

    expect($a)->toBeInstanceOf(ResolvedPlayer::class, 'the first of two joins was refused, so there is no winner and the loser path below means nothing (Req 2.7)')
        ->and($a instanceof ResolvedPlayer ? $a->mark : null)->toBe(Mark::O, 'the first join was not assigned the Mark O (Req 2.1, 2.7)')
        ->and($claimed['state'])->toBe(GameState::Active->value, 'the first join did not activate the Game')
        ->and($claimed['version_counter'])->toBe(1, 'the first join did not increment the Version_Counter, so it did not claim the slot (Req 2.6)')
        ->and($claimed['o_token_hash'])->toBe(hash('sha256', $tokenA), 'the persisted O hash is not the digest of the token in session A')
        ->and($tokenA)->not->toBe('', 'session A holds no Player_Token after winning the race');

    // ---- Session B, a different browser, submits the same code. ----
    $sessionB = concurrencySwitchSession();

    expect($sessionB)->not->toBe($sessionA, 'the two joins share one Player_Session, so this is Requirement 2.4 and not Requirement 2.7')
        ->and($tokens->heldFor($game->id))->toBeNull('session B holds a Player_Token for the Game, so its join would short-circuit rather than race (Req 2.4)')
        ->and(concurrencyTokenKeys())->toBe([], 'session B carries Player_Tokens from session A, so the two callers are not two distinct Players');

    $b = $join->handle($code);

    $accepted = array_filter([$a, $b], static fn (mixed $result): bool => $result instanceof ResolvedPlayer);

    expect($b)->toBe(JoinOutcome::GameFull, 'the second of two joins for one waiting Game was not refused with game_full (Req 2.7)')
        ->and($accepted)->toHaveCount(1, 'the two joins did not resolve to exactly one Player assigned O (Property 13)')
        ->and(concurrencyRowOf($game->id))->toBe($claimed, 'the refused join wrote to the row (Req 2.7)')
        ->and(concurrencyRowOf($game->id)['version_counter'])->toBe(1, 'the Version_Counter moved twice, so both joins were accepted (Req 2.6, 2.7)')
        ->and($tokens->heldFor($game->id))->toBeNull('the refused join left a Player_Token in session B')
        ->and(Session::has('player_tokens.'.$game->id))->toBeFalse('the refused join wrote a session key for the Game it did not join')
        ->and(concurrencyTokenKeys())->toBe([], 'the refused join left a Player_Token in session B: '.implode(', ', concurrencyTokenKeys()));

    // ---- Back in session A: the winner's credential is untouched. ----
    concurrencySwitchSession($sessionA);

    $stored = Game::query()->findOrFail($game->id);

    expect(Session::getId())->toBe($sessionA)
        ->and($tokens->heldFor($game->id))->toBe($tokenA, "the loser's join replaced the Player_Token in the winner's session")
        // The same helper that reported an empty namespace for session B, now
        // reporting this Game — so those two empty-list assertions are a genuine
        // absence rather than a helper that can only ever answer nothing.
        ->and(concurrencyTokenKeys())->toBe([$game->id], "the winner's session does not hold a Player_Token for the Game it joined")
        ->and($tokens->resolve($stored, $tokenA))->toBe(Mark::O, "the loser's join unbound the winner's Player_Token, locking the O Player out of their own Game (Req 3.1)")
        ->and($stored->x_token_hash)->toBe($before['x_token_hash'], 'the X Player was disturbed by the join race');
});
