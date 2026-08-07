<?php

declare(strict_types=1);

use App\Domain\TicTacToe\Mark;
use App\Games\GameSnapshot;
use App\Games\GameState;
use App\Games\JoinCode;
use App\Games\JoinGame;
use App\Games\JoinOutcome;
use App\Games\MoveAccepted;
use App\Games\MoveOutcome;
use App\Games\PlayerTokens;
use App\Games\ResolvedPlayer;
use App\Games\SubmitMove;
use App\Models\Game;
use App\Models\Move;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

// Feature: remote-tic-tac-toe, Property 13: Joining is exclusive
// Feature: remote-tic-tac-toe, Property 14: A Move conflict resolves to one Move
//
// Validates: Requirements 2.7, 5.1, 5.2, 5.3, 5.4, 5.5, 14.9
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

/*
 * Task 6.8 — THE MOVE CONFLICT (Req 5.1–5.5, Req 14.9, Property 14).
 *
 * Written under the same constraint as the join race above, and faithful for the
 * same kind of reason — but the mechanism is a different one, so the argument has
 * to be made again rather than inherited.
 *
 * WHY TWO SEQUENTIAL CALLS ARE THE CONCURRENT CASE HERE. `SubmitMove::handle()` is
 * a pure function of `($observed, $actingMark, $cellIndex)` and issues no `SELECT`
 * at all: every guard reads the `GameSnapshot` it was handed. So a second call
 * given the SAME snapshot observes precisely what a genuinely concurrent second
 * request observes — the state as it was read, without the first Move — and derives
 * the same `sequence_index = n` from it. The first insert commits; the second
 * violates the unique index on `(game_id, sequence_index)` and is refused. The
 * loser path is taken by the same mechanism and through the same branch, not
 * simulated: nothing is spawned, nothing sleeps, and no assertion depends on an
 * ordering the scheduler chooses.
 *
 * This is also the realistic production trigger, since Mark_To_Move is fixed by
 * parity and only one Player is ever authorised at a given Sequence_Index: it is
 * one Player double-submitting, which is why both calls act as `X`.
 *
 * WHAT THIS ADDS THAT `SubmitMoveMechanismTest` DOES NOT. That file already maps
 * BOTH unique indexes to `conflict`, by writing a competing `moves` row by hand
 * after the snapshot is taken. That is the narrow claim about the mechanism. The
 * claim here is the one it cannot make: TWO CALLS OVER ONE SNAPSHOT, with the
 * Move_List asserted to have gone from n to n+1.
 *
 * TWO OF THE CRITERIA IN THIS FILE'S `Validates` LINE ARE NOT EXERCISED BELOW, and
 * saying so is cheaper than letting a reader assume otherwise. Requirement 5.2's
 * Cell-index uniqueness is deliberately kept OUT of the way here — the two calls
 * target different Cells precisely so that the Sequence_Index is the only thing
 * that collides — and it is asserted by `SubmitMoveMechanismTest`'s cell-index
 * case. Requirement 5.5 is a Web_Client obligation: the `conflict` is delivered
 * with the current state by the 303 and the fresh GET that follows it (task 6.2),
 * and the rendering of that state is a client test's claim, not this file's.
 *
 * THE MOVE-COUNT ASSERTION IS THE POINT, AND IT IS THE MORE IMPORTANT OF THE TWO.
 * "Exactly one of the two is accepted" is the whole of Requirement 5.3, and it is a
 * claim about the table rather than about a return value — it holds whichever
 * rejection path the second call takes, so it survives a change of rejection
 * vocabulary and it catches a lost unique index, which a `conflict`-only assertion
 * would too but for the wrong reason. It is asserted on ROWS READ BACK AS A LIST OF
 * PAIRS rather than as a Sequence_Index-keyed map, because a map cannot count: with
 * `moves_game_sequence_unique` dropped, both calls commit a row at Sequence_Index 2
 * and a keyed map collapses the two into one, reporting the three entries the
 * assertion wants to see. That was verified by dropping the index: as a list the
 * assertion fails with four rows, as a map it passed.
 *
 * AND THE SNAPSHOT IS ASSERTED TO HAVE STAYED PUT. `$observed->game` is a live
 * Eloquent model, so `$game->refresh()` inside `handle()` is one line away and
 * changes no outcome in any single-request test — the re-read returns the state the
 * snapshot already holds. It is the one edit that would retire this whole path: the
 * second call would then observe the committed first Move, fail the turn guard and
 * answer `not_your_turn`, and Requirement 5.3's exclusivity would move out of the
 * unique index and into application code that cannot enforce it. Two assertions
 * below catch it — the `conflict` outcome itself, and the observed model's
 * Version_Counter still reading its pre-Move value. Both were verified by making
 * that edit, and the second is worth a word: it bites HERE and could not bite in a
 * single-call test, because a refresh at the top of `handle()` reads the row before
 * the write and so leaves the value unchanged. It is the SECOND call refreshing,
 * after the first has committed, that moves it — verified both ways round.
 *
 * WHAT THE MOVE-COUNT ASSERTION DOES *NOT* CATCH, since the distinction is easy to
 * misplace. Under that same re-read the Move_List still goes from n to n + 1: one
 * Move is still accepted and the second still refused, only with the wrong
 * vocabulary and by the wrong mechanism. So the count assertion is what catches a
 * LOST UNIQUE INDEX, and the outcome assertion is what catches the re-read; neither
 * subsumes the other, which is why both are here and why the count assertion is not
 * the sole guard. The re-read also reddens the query-log and empty-statement-log
 * assertions in `SubmitMoveMechanismTest` — eighteen of its cases, verified — that
 * being the other of the two guards `SubmitMove`'s docblock names.
 */

/**
 * A saved `active` Game with both token slots occupied and `$cellIndices` recorded
 * as a contiguous Move_List from zero, the way a join followed by that many
 * accepted Moves would leave the two tables.
 *
 * Built on `concurrencyWaitingGame()` rather than beside it, so there is one place
 * in this file that knows how to make a Game row. The Version_Counter is
 * `1 + count($cellIndices)`: one for the join (Req 2.6) and one per accepted Move
 * (Req 4.7), so "it moved exactly once more" below is asserted against a value a
 * real Game would carry rather than against a round number.
 */
function concurrencyActiveGame(int ...$cellIndices): Game
{
    $game = concurrencyWaitingGame();
    $game->state = GameState::Active;
    $game->o_token_hash = (new PlayerTokens)->mint()->hash;
    $game->version_counter = 1 + count($cellIndices);
    $game->save();

    foreach (array_values($cellIndices) as $position => $cellIndex) {
        $move = new Move;
        $move->game_id = $game->id;
        $move->cell_index = $cellIndex;
        $move->sequence_index = $position;
        $move->save();
    }

    return $game;
}

/**
 * The persisted Move_List as a LIST of `(sequence_index, cell_index)` pairs, in
 * insertion order within a Sequence_Index.
 *
 * A list and not a `[sequence_index => cell_index]` map, and that is the difference
 * between an assertion that counts and one that cannot. Two Moves committed at the
 * same Sequence_Index — what happens if `moves_game_sequence_unique` is ever lost —
 * occupy one key in a map and two entries here. `orderBy('id')` breaks the tie by
 * insertion order so the duplicate pair is reported in the order it was written.
 *
 * @return list<array{sequence_index: int, cell_index: int}>
 */
function concurrencyMoveRowsOf(string $gameId): array
{
    $rows = DB::table('moves')
        ->where('game_id', $gameId)
        ->orderBy('sequence_index')
        ->orderBy('id')
        ->get();

    return array_values(array_map(
        static fn (object $row): array => [
            'sequence_index' => (int) $row->sequence_index,
            'cell_index' => (int) $row->cell_index,
        ],
        $rows->all(),
    ));
}

/*
 * Preconditions are asserted rather than assumed, because each is a way this test
 * could pass while testing nothing:
 *
 *   - The Game is `active` and its observed Move_List holds exactly n = 2 Moves. A
 *     waiting or terminal Game would refuse both calls at guard 1 or 2, and the
 *     Move_List would go from n to n with no Move accepted at all — which
 *     `toHaveCount(3)` catches, but only after a reader has worked out why.
 *   - `X` is the Mark_To_Move on the observed snapshot, so both calls are made by
 *     the Player entitled to Sequence_Index 2. If `O` were to move, both would
 *     answer `not_your_turn` and the second's refusal would say nothing about
 *     Requirement 5.3.
 *   - Both target Cells are free and distinct, so the second call is refused by the
 *     Sequence_Index it shares with the first and not by the Cell — the Cell-index
 *     violation is a real path (Req 5.2) but it is `SubmitMoveMechanismTest`'s, and
 *     conflating the two here would leave this test passing if the sequence index
 *     stopped being derived from the observed list.
 */
it('accepts exactly one of two moves submitted from one snapshot and refuses the second with conflict', function () {
    $submit = new SubmitMove;

    $game = concurrencyActiveGame(0, 3);

    // ---- ONE READ. This snapshot, and only this snapshot, is handed to both calls
    // below — which is what makes them a model of two concurrent requests.
    $observed = GameSnapshot::of($game);

    $before = concurrencyRowOf($game->id);
    $movesBefore = concurrencyMoveRowsOf($game->id);

    expect($before['state'])->toBe(GameState::Active->value, 'the fixture Game is not active, so both Moves would be refused by a state guard and no conflict is being tested (Req 5.3)')
        ->and($movesBefore)->toBe([
            ['sequence_index' => 0, 'cell_index' => 0],
            ['sequence_index' => 1, 'cell_index' => 3],
        ], 'the fixture Move_List is not the two Moves this test reasons about')
        ->and($observed->moveList->count())->toBe(2, 'the observed Move_List does not hold n = 2 Moves, so the Sequence_Index the two calls derive is not the one asserted below')
        ->and($observed->analysis->markToMove)->toBe(Mark::X, 'X is not the Mark_To_Move, so both calls would be refused as not_your_turn and neither would reach the insert (Req 3.5)')
        ->and($observed->analysis->board->isOccupied(4))->toBeFalse('cell 4 is occupied, so the first call would be refused as invalid_move')
        ->and($observed->analysis->board->isOccupied(6))->toBeFalse('cell 6 is occupied, so the second call would be refused as invalid_move rather than as a conflict (Req 5.4)');

    // ---- Two Moves from that one snapshot, different Cells, both deriving
    // sequence_index = 2. One Player double-submitting, which is the realistic
    // trigger: the Mark_To_Move at Sequence_Index 2 is X and nobody else is
    // authorised to take it.
    $first = $submit->handle($observed, Mark::X, 4);

    $afterFirst = concurrencyRowOf($game->id);

    $second = $submit->handle($observed, Mark::X, 6);

    $movesAfter = concurrencyMoveRowsOf($game->id);
    $after = concurrencyRowOf($game->id);

    expect($first)->toBeInstanceOf(MoveAccepted::class, 'the first of two Moves from one snapshot was refused, so there is no winner and the loser path below means nothing (Req 5.3)')
        ->and($first instanceof MoveAccepted ? $first->sequenceIndex : null)->toBe(2, 'the accepted Move was not recorded at the length of the observed Move_List (Req 4.2)')
        // THE ASSERTION THIS TASK EXISTS FOR: the Move_List went from n to n+1.
        // Requirement 5.3 is "exactly one of the two is accepted", and this is that
        // claim, read from the table.
        ->and($movesAfter)->toHaveCount(3, 'the Move_List did not go from n = 2 to n + 1 = 3, so it is not true that exactly one of the two Moves was accepted (Req 5.1, 5.3)')
        ->and($movesAfter)->toBe([
            ['sequence_index' => 0, 'cell_index' => 0],
            ['sequence_index' => 1, 'cell_index' => 3],
            ['sequence_index' => 2, 'cell_index' => 4],
        ], "the persisted Move_List is not the observed list with the winner's Cell appended at n (Req 4.2, 5.1)")
        ->and($second)->toBe(MoveOutcome::Conflict, 'the second Move from the same snapshot was not refused with conflict, so the collision was not settled by the unique index on (game_id, sequence_index) (Req 5.1, 5.4)')
        // Property 12 and Requirement 5.3 together: one committed state-changing
        // operation, so one increment. `n + 2` would mean both Moves were accepted;
        // `n` would mean neither was.
        ->and($after['version_counter'])->toBe($before['version_counter'] + 1, 'the Version_Counter moved other than exactly once, so it is not true that exactly one Move was committed (Req 4.7, 5.3, Property 12)')
        ->and($after)->toBe($afterFirst, 'the refused Move changed the Game row, so its transaction did not roll back (Req 5.4, Property 9)')
        ->and($after['last_activity_at'])->toBeGreaterThan($before['last_activity_at'], 'the accepted Move did not move last_activity_at, so nothing was committed at all')
        // The no-re-query invariant, from the other side: the model inside the
        // snapshot still reads what it read. A `$game->refresh()` in `handle()` —
        // one line, no outcome changed in any single-request test — makes this and
        // the `conflict` assertion above fail together, and it is the edit that
        // would retire this path entirely.
        ->and($observed->game->version_counter)->toBe($before['version_counter'], 'SubmitMove refreshed the observed Game model, so the second call did not see the state a concurrent second request would see (Req 5.3)');
});
