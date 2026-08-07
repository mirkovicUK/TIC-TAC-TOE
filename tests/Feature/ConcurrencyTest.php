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
 * Requirement 14.9 forbids scheduler-dependent tests, so both races below
 * establish the state each request would observe and then submit sequentially.
 *
 * Sequential is faithful for the join race because the whole of the concurrency
 * control is the affected-row count of one guarded
 * `UPDATE ... WHERE state = 'waiting_for_opponent' AND o_token_hash IS NULL`
 * (ADR-006), and `JoinGame` never re-reads the row between the Join_Code lookup
 * and that statement. The second caller's guard evaluates against the row the
 * first left behind however the calls are spaced. Were a `SELECT` of `state` added
 * in front of the UPDATE the two shapes would diverge; the query-log test in
 * `JoinGameTest` is what fails then.
 *
 * Excluded here, covered elsewhere: the guarded statement's affected-row count and
 * the absence of a credential after a losing call live in `JoinGameTest`;
 * Requirement 5.2's Cell-index uniqueness lives in `SubmitMoveMechanismTest`;
 * Requirement 5.5 is a Web_Client rendering obligation.
 */

uses(RefreshDatabase::class);

/**
 * Suspends the current Player_Session and resumes another, which is what makes the
 * two callers below two Players rather than one Player calling twice.
 *
 * Load-bearing: `JoinGame` short-circuits when the requesting session already holds
 * a Player_Token for the Game (Req 2.4), so a second call in session A returns
 * `ResolvedPlayer` with the Mark `O` and never reaches the guarded UPDATE.
 *
 * `Session::flush()` alone is not enough — it empties the one session in place, so
 * there is no session A to return to and the winner's credential could only be
 * checked against a copy the test kept. `SESSION_DRIVER=array` retains each id's
 * payload in the handler for the lifetime of the test, so switching back resumes
 * session A as it was.
 *
 * @param  string|null  $id  An existing session id to resume, or null for a new one.
 * @return string The id now in effect.
 */
function concurrencySwitchSession(?string $id = null): string
{
    // Writes the outgoing payload through the handler, so resuming its id later
    // reads back what it held rather than an empty session.
    Session::save();

    // The order of these two lines is the whole of the switch. `Store::start()`
    // *merges* what the handler holds for the incoming id into the attributes
    // already in memory rather than replacing them, so changing only the id would
    // carry the outgoing session's `player_tokens.*` key across and every caller
    // after the first would short-circuit as a Player of the Game.
    Session::flush();

    // `Store::isValidId()` accepts 40 alphanumeric characters and silently
    // substitutes a generated id otherwise, which would make a failed switch
    // indistinguishable from a successful one.
    Session::setId($id ?? Str::random(40));
    Session::start();

    return Session::getId();
}

/**
 * A saved Game waiting for an opponent: X slot occupied, O slot free, nothing
 * written to any session.
 *
 * The X token is assigned directly rather than through `PlayerTokens::issue()`,
 * which writes whichever session is current and would trip the Req 2.4
 * short-circuit. `last_activity_at` is backdated so an accepted join visibly moves
 * it and a refused one visibly does not.
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
 * Not `Session::all()`, which is non-empty in a fresh session because
 * `Store::start()` mints a CSRF `_token`. Reads the raw store rather than
 * `PlayerTokens::heldFor()`, which reports an empty or malformed value as null by
 * design and would hide a key written with one.
 *
 * Read as a nested array, not a flat key: `PlayerTokens` writes
 * `Session::put('player_tokens.'.$gameId, ...)` and `Store::put()` treats the dot
 * as `Arr::set()`, so the store holds one top-level `player_tokens` key whose value
 * is a Game_Id-keyed array. A filter over `Session::all()`'s top-level keys for the
 * prefix `player_tokens.` matches nothing ever, including when a token is held —
 * an assertion that cannot fail.
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
 * through a model the subject returned, so a stale or hand-assigned in-memory
 * instance cannot make a row assertion pass.
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
 * The preconditions rule out passes for the wrong reason: a failed session switch
 * (B short-circuits as a Player of the Game, Req 2.4), a Game already `active` (B
 * is refused `game_full` without A having won anything), and a first join that
 * never claimed the slot.
 *
 * The final check on session A guards against more than an untidy session: an
 * unguarded second write replacing `o_token_hash` locks the real O Player out of
 * their own Game unrecoverably, since a Player_Token cannot be reissued (ADR-005,
 * Req 12.10).
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
        // Non-vacuity: the same helper that reported an empty namespace for session
        // B reports this Game, ruling out a helper that can only answer nothing.
        ->and(concurrencyTokenKeys())->toBe([$game->id], "the winner's session does not hold a Player_Token for the Game it joined")
        ->and($tokens->resolve($stored, $tokenA))->toBe(Mark::O, "the loser's join unbound the winner's Player_Token, locking the O Player out of their own Game (Req 3.1)")
        ->and($stored->x_token_hash)->toBe($before['x_token_hash'], 'the X Player was disturbed by the join race');
});

/*
 * THE MOVE CONFLICT (Req 5.1–5.5, Req 14.9, Property 14).
 *
 * `SubmitMove::handle()` is a pure function of `($observed, $actingMark,
 * $cellIndex)` and issues no `SELECT`: every guard reads the `GameSnapshot` it was
 * handed. A second call given the SAME snapshot therefore observes what a
 * concurrent second request observes — the state without the first Move — and
 * derives the same `sequence_index = n`. The first insert commits; the second
 * violates the unique index on `(game_id, sequence_index)`.
 *
 * Both calls act as `X` because Mark_To_Move is fixed by parity and only one Player
 * is authorised at a given Sequence_Index, so the realistic trigger is one Player
 * double-submitting.
 *
 * Beyond `SubmitMoveMechanismTest`, which maps both unique indexes to `conflict` by
 * writing a competing `moves` row by hand, the claim here is two calls over one
 * snapshot with the Move_List asserted to have gone from n to n + 1.
 *
 * The count assertion and the `conflict` assertion catch different failures, so
 * neither subsumes the other. A dropped `moves_game_sequence_unique` is what the
 * count catches — and it must be read as a list of pairs, because both calls then
 * commit at Sequence_Index 2 and a Sequence_Index-keyed map would collapse them
 * into the three entries the assertion wants to see. A `$game->refresh()` added
 * inside `handle()` is what the `conflict` outcome and the observed model's
 * unchanged Version_Counter catch: the second call would then see the committed
 * first Move and answer `not_your_turn`, moving Requirement 5.3's exclusivity out
 * of the unique index, while the count still reads n + 1. That Version_Counter
 * assertion can only bite here — in a single-call test the refresh reads the row
 * before the write and leaves the value unchanged.
 */

/**
 * A saved `active` Game with both token slots occupied and `$cellIndices` recorded
 * as a contiguous Move_List from zero, the way a join followed by that many
 * accepted Moves would leave the two tables.
 *
 * The Version_Counter is `1 + count($cellIndices)`: one for the join (Req 2.6) and
 * one per accepted Move (Req 4.7), so "it moved exactly once more" below is
 * asserted against a value a real Game would carry rather than a round number.
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
 * A list and not a `[sequence_index => cell_index]` map, because a map cannot
 * count: two Moves committed at the same Sequence_Index — what a lost
 * `moves_game_sequence_unique` allows — occupy one key in a map and two entries
 * here. `orderBy('id')` breaks the tie by insertion order.
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
 * The preconditions rule out passes for the wrong reason: a non-`active` Game or an
 * observed Move_List other than n = 2 (both calls refused by a state guard, no Move
 * accepted), `O` as Mark_To_Move (both refused as `not_your_turn`), and target Cells
 * that are occupied or equal (the second call refused by the Cell rather than by the
 * Sequence_Index it shares with the first, which would leave this test passing if
 * the sequence index stopped being derived from the observed list).
 */
it('accepts exactly one of two moves submitted from one snapshot and refuses the second with conflict', function () {
    $submit = new SubmitMove;

    $game = concurrencyActiveGame(0, 3);

    // ---- One read. This snapshot, and only this snapshot, is handed to both calls
    // below, which is what makes them a model of two concurrent requests.
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
    // sequence_index = 2.
    $first = $submit->handle($observed, Mark::X, 4);

    $afterFirst = concurrencyRowOf($game->id);

    $second = $submit->handle($observed, Mark::X, 6);

    $movesAfter = concurrencyMoveRowsOf($game->id);
    $after = concurrencyRowOf($game->id);

    expect($first)->toBeInstanceOf(MoveAccepted::class, 'the first of two Moves from one snapshot was refused, so there is no winner and the loser path below means nothing (Req 5.3)')
        ->and($first instanceof MoveAccepted ? $first->sequenceIndex : null)->toBe(2, 'the accepted Move was not recorded at the length of the observed Move_List (Req 4.2)')
        // Requirement 5.3 read from the table rather than from a return value: the
        // Move_List went from n to n + 1.
        ->and($movesAfter)->toHaveCount(3, 'the Move_List did not go from n = 2 to n + 1 = 3, so it is not true that exactly one of the two Moves was accepted (Req 5.1, 5.3)')
        ->and($movesAfter)->toBe([
            ['sequence_index' => 0, 'cell_index' => 0],
            ['sequence_index' => 1, 'cell_index' => 3],
            ['sequence_index' => 2, 'cell_index' => 4],
        ], "the persisted Move_List is not the observed list with the winner's Cell appended at n (Req 4.2, 5.1)")
        ->and($second)->toBe(MoveOutcome::Conflict, 'the second Move from the same snapshot was not refused with conflict, so the collision was not settled by the unique index on (game_id, sequence_index) (Req 5.1, 5.4)')
        // One committed state-changing operation, so one increment (Property 12).
        // `n + 2` would mean both Moves were accepted, `n` that neither was.
        ->and($after['version_counter'])->toBe($before['version_counter'] + 1, 'the Version_Counter moved other than exactly once, so it is not true that exactly one Move was committed (Req 4.7, 5.3, Property 12)')
        ->and($after)->toBe($afterFirst, 'the refused Move changed the Game row, so its transaction did not roll back (Req 5.4, Property 9)')
        ->and($after['last_activity_at'])->toBeGreaterThan($before['last_activity_at'], 'the accepted Move did not move last_activity_at, so nothing was committed at all')
        // The no-re-query invariant from the other side: the model inside the
        // snapshot still reads what it read.
        ->and($observed->game->version_counter)->toBe($before['version_counter'], 'SubmitMove refreshed the observed Game model, so the second call did not see the state a concurrent second request would see (Req 5.3)');
});
