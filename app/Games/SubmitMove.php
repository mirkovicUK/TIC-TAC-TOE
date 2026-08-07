<?php

declare(strict_types=1);

namespace App\Games;

use App\Domain\TicTacToe\InvalidMoveList;
use App\Domain\TicTacToe\Mark;
use App\Domain\TicTacToe\MoveList;
use App\Domain\TicTacToe\RulesEngine;
use App\Models\Game;
use App\Models\Move;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Records one Move, or refuses it: four guards over an observed snapshot, then an
 * insert and a state transition in one transaction (Req 4.2–4.7, 5.3, 5.4, 6.2,
 * 6.4).
 *
 * **INVARIANT: `handle()` IS A PURE FUNCTION OF `($observed, $actingMark,
 * $cellIndex)` AND ISSUES NO `SELECT` FOR GAME STATE.** Every guard reads
 * `$observed` only. Nothing between the first guard and the insert reads the
 * database — in fact nothing anywhere in this class does, so the absence is total
 * rather than merely well placed, and a test can assert it by counting `SELECT`s
 * in the query log (there are none).
 *
 * THIS IS LOAD-BEARING TWICE OVER, AND IT IS EASY TO "IMPROVE" INTO USELESSNESS.
 *
 * *In production*, two competing requests each read their own snapshot; under
 * contention both read the same state, both pass every guard, both derive
 * `sequence_index = count($observed->moveList)`, and the collision is settled by
 * the unique index on `(game_id, sequence_index)` — the only place it *can* be
 * settled, because any read-then-write has a window between the read and the
 * write. That is what makes Requirement 5.3's "exactly one of two concurrent
 * Moves is accepted" a persisted invariant rather than a checked-then-hoped-for
 * one.
 *
 * *In the test suite*, task 6.8 passes one `GameSnapshot` to two successive calls.
 * Because no guard re-reads, the second call sees exactly what a genuinely
 * concurrent second request would see, so the sequential test of Requirement 14.9
 * is a faithful model of the concurrent case rather than a simulation of it. It is
 * also the only mechanical guard on this invariant: every single-request test in
 * the suite still passes when a re-read returns the state the snapshot already
 * holds.
 *
 * A RE-READ WOULD BREAK BOTH, SILENTLY. The second of two competing calls would
 * observe the first's committed Move, fail the `markToMove` guard and return
 * `not_your_turn`. The insert would never be attempted, the `conflict` path would
 * stop being exercised while its test carried on passing, and Requirement 5.3's
 * exclusivity would move out of the unique index and into application code that
 * cannot enforce it. So: no `$game->refresh()`, no second `GameSnapshot::of()`, no
 * `lockForUpdate()`, and no transaction wrapped around the guards that re-reads
 * anything.
 *
 * AND THE INVARIANT IS NOT STRUCTURAL — IT IS A CONVENTION THIS CLASS KEEPS AND
 * TWO TESTS GUARD. Taking `GameSnapshot` as a parameter makes the invariant
 * *stateable*; it does not make a breach impossible, and nothing in the type
 * system will stop one. `$observed->game` is a live Eloquent model on a live
 * connection: `$game->refresh()`, `$game->moves`, `Game::find($game->id)` are each
 * one call away, and `GameSnapshot` being `final readonly` does not help, because
 * readonly pins the reference and not the model's state. Adding `$game->refresh()`
 * to the top of `handle()` compiles, runs, and changes no *outcome* in any
 * single-request scenario — it was tried, and every rejection test that caught it
 * caught it on the statement assertions rather than on the answer returned.
 *
 * The guards are therefore exactly two, and both must survive any future edit:
 * `SubmitMoveMechanismTest`'s query-log test, which asserts an accepted Move
 * issues one INSERT and one UPDATE and no `SELECT` at all, and every rejection test
 * in that file asserting the statement log is empty; and task 6.8, which passes one
 * snapshot to two calls and asserts the Move_List went from n to n+1. Delete or
 * loosen those and this docblock is the only thing left, which is not a guard.
 *
 * THE ACTING MARK ARRIVES AS A PARAMETER, FROM THE PLAYER_TOKEN AND FROM NOTHING
 * ELSE (Req 3.2, 3.6). There is deliberately nothing in this class that could read
 * a Mark from anywhere: no request, no payload, no session, and no `mark` on the
 * `moves` table for one to be written to even by accident. A `mark` field in a
 * body is not "validated and rejected" — it is unreachable from here, which is
 * what "ignored outright" should mean.
 *
 * WHAT IS DELIBERATELY NOT HERE.
 *
 *   - **Authorisation.** Settled by `GameResolver` before this class is called,
 *     and `not_authorised` is the only outcome reported for a request that fails
 *     it (Req 3.9). `$actingMark` being a `Mark` rather than a `?Mark` is how that
 *     ordering is carried in the signature: there is no "no Player" value to pass.
 *   - **Logging.** The design's class table lists this class as "…, log" and cites
 *     Requirement 10.3, but `GameEventLogger` is task 10.x. The `move.accepted`
 *     and `move.rejected` records belong here when that class arrives, as does the
 *     `game.invariant_violation` record for the corruption path below, and
 *     `game.finished` when `$outcome->isTerminal()`. They are left out rather than
 *     approximated, because the design gives `GameEventLogger` the role of sole
 *     writer of lifecycle records exclusively (Req 10.4's redaction is why).
 *     `MoveAccepted` and `MoveOutcome` are shaped to give that task everything it
 *     needs without a re-read.
 *   - **Anything about the response.** No redirect, no status, no representation.
 *     The 303 to the game page that carries every rejection, and the fresh GET
 *     that delivers the current state with it (Req 5.5), are task 6.2's. Nothing
 *     in `App\Games` knows what an HTTP status is.
 *   - **A rate limit.** `throttle:move` is route middleware keyed on the presented
 *     token's hash (Req 10.7), applied at task 10.x.
 *   - **Validation of `cell_index` by a Form Request.** The design is explicit
 *     that a non-integer or out-of-range Cell must produce `invalid_move` rather
 *     than a 422 validation payload, which is why the parameter is `mixed` and the
 *     check is here.
 */
final class SubmitMove
{
    /**
     * Attempts `$cellIndex` for `$actingMark` against the state `$observed`
     * records, and either commits it or returns one of five refusals.
     *
     * The return type is the design's `MoveResult`: `MoveAccepted|MoveOutcome`,
     * a genuine union with no common supertype, so a caller must narrow with
     * `instanceof` before it can read anything. See `MoveOutcome`'s docblock for
     * why the rejection half carries no state even though Requirement 5.5 requires
     * a rejection to reach the client *with* the current state — the short version
     * is that the state a `conflict` must be accompanied by is precisely the state
     * this method does not hold, and the redirect is what supplies it.
     *
     * NO CONSTRUCTOR, NO DEPENDENCIES. There is nothing to inject: the Rules_Engine
     * is a static pure function, and the two writes go through the models. A
     * container-bound collaborator would be ceremony, and — more to the point —
     * anything injected here would be something a future edit could read state
     * through.
     *
     * QUERIES. Zero on every rejection path, and exactly two on the accepted path:
     * the `INSERT` into `moves` and the `UPDATE` of `games`. No `SELECT`, ever.
     * The transaction's `BEGIN`/`COMMIT` are issued through PDO rather than as
     * logged statements.
     *
     * @param  GameSnapshot  $observed  The state this request read, and the only
     *                                  state any guard below consults.
     * @param  Mark  $actingMark  From `PlayerTokens::resolve()` by way of
     *                            `ResolvedPlayer`, and from nothing else.
     * @param  mixed  $cellIndex  The Cell as the request carried it, of any type:
     *                            `mixed` because "not an integer" is one of the
     *                            conditions this method must turn into an outcome
     *                            (Req 4.4), not a `TypeError` for a value a user
     *                            supplied.
     *
     * @throws CorruptMoveListException if the Move_List including this Move is
     *                                  rejected by the Rules_Engine — corruption,
     *                                  not a user error. See below.
     */
    public function handle(GameSnapshot $observed, Mark $actingMark, mixed $cellIndex): MoveAccepted|MoveOutcome
    {
        $game = $observed->game;

        // GUARD 1 (Req 4.5). The persisted Game_State, because "waiting for an
        // opponent" is exactly the fact a Move_List cannot express — an empty list
        // is a Game with no second Player and a Game whose second Player has not
        // moved, indistinguishably (see `GameState`'s docblock). This must come
        // before the turn guard rather than after it: the Mark_To_Move on an empty
        // Move_List is `X`, so the Creator moving into their own waiting Game would
        // otherwise pass guard 3 and reach the insert.
        if ($game->state === GameState::WaitingForOpponent) {
            return MoveOutcome::GameNotStarted;
        }

        // GUARD 2 (Req 4.6). Also the persisted state, as the design's step 3
        // specifies, and not `$observed->analysis->isTerminal()`. The two agree by
        // construction — the transaction below writes the state from the very
        // `Analysis` a later request will re-derive — and the row is the cheaper
        // and more direct reading of "the Game is over". Where they *disagree* the
        // Game is corrupt, and that is caught below by the engine rejecting the
        // appended list rather than papered over by a second opinion here.
        if ($game->state->isTerminal()) {
            return MoveOutcome::GameEnded;
        }

        // GUARD 3 (Req 3.5). Turn ownership, and it is checked BEFORE cell
        // validity on purpose. The requirements do not order the two, and the
        // design records the choice: a Player who is not to move learns that first,
        // which is the more useful message, and it avoids telling a Player who
        // cannot act whether a Cell is occupied. Reversing the two would leak
        // occupancy to the waiting Player through the outcome they receive.
        //
        // `$actingMark` is compared against the derived Mark_To_Move and against
        // nothing else. There is no payload in scope to have taken a Mark from
        // (Req 3.6).
        if ($observed->analysis->markToMove !== $actingMark) {
            return MoveOutcome::NotYourTurn;
        }

        // GUARD 4 (Req 4.3, 4.4). Three conditions, one outcome: not an integer,
        // outside 0..8, or already occupied in the observed Board. The design keeps
        // one vocabulary for one condition, so nothing here distinguishes them —
        // and a Player who typed nothing at all, a prober sending `{"cell_index":
        // ["4"]}` and a Player clicking a taken Cell get the same answer.
        //
        // `is_int()` is STRICT, and deliberately: `'4'` is not an integer and is
        // refused. Inertia posts JSON, so a Cell clicked in `Board.tsx` arrives as
        // an `int` (task 6.3), and task 6.2 must therefore hand over the decoded
        // value rather than a cast or a `->integer()` read — a cast would make
        // `'banana'` into `0`, which is a legal Cell, and turn a malformed payload
        // into a Move in the top-left corner.
        if (! is_int($cellIndex) || $cellIndex < 0 || $cellIndex > 8) {
            return MoveOutcome::InvalidMove;
        }

        if ($observed->analysis->board->isOccupied($cellIndex)) {
            return MoveOutcome::InvalidMove;
        }

        // Guards 1 to 4 have written nothing, which is Requirements 4.3–4.6's "SHALL
        // leave the Move_List unchanged" and half of Property 9 — delivered by there
        // being no write above this line rather than by a rollback.

        try {
            return DB::transaction(
                fn (): MoveAccepted => $this->commit($game, $actingMark, $cellIndex, $observed->moveList),
            );
        } catch (UniqueConstraintViolationException) {
            // Req 5.4. Both unique indexes on `moves` land here, and both mean the
            // same thing — another Move landed first — so both map to one outcome:
            // `(game_id, sequence_index)` when the other Move took this turn, and
            // `(game_id, cell_index)` when it took this Cell. The design's failure
            // table says so outright.
            //
            // CAUGHT RATHER THAN CHECKED FOR. A `SELECT` asking whether the
            // Sequence_Index is free would be a read — forbidden by the invariant —
            // and it would race: the answer could stop being true between the
            // question and the insert. The exception is the answer arriving from
            // the only component that can give it without a window.
            //
            // The transaction has already rolled back by the time this runs, so the
            // Version_Counter increment and the state transition below did not
            // happen. That is the other half of Property 9 for this outcome, and it
            // is why the catch sits OUTSIDE the transaction rather than inside it:
            // catching within the closure would let `DB::transaction` commit a
            // transaction whose insert had failed.
            return MoveOutcome::Conflict;
        }
    }

    /**
     * The write, both halves of it, inside one transaction (Req 4.7, 6.2, 6.4).
     *
     * ONE TRANSACTION IS THE POINT, NOT A PRECAUTION. Property 12 claims the
     * Version_Counter increases by exactly one per *committed* state-changing
     * operation; if the insert and the `games` UPDATE were separate statements, a
     * failure between them would leave a Move recorded whose Version_Counter never
     * moved — so a polling client would never observe it (Req 8.3) — or a Game
     * marked `won` with no Move to have won it. The Move and the row that describes
     * it commit together or not at all.
     *
     * Private, and handed the observed Move_List rather than the `GameSnapshot`, so
     * that what it can consult is visible from its signature: a `MoveList` is nine
     * integers at most and has no connection to the database, so nothing in here
     * can re-read anything even by accident.
     *
     * @param  MoveList  $observedList  The Move_List as observed, WITHOUT this Move.
     *
     * @throws CorruptMoveListException
     */
    private function commit(Game $game, Mark $actingMark, int $cellIndex, MoveList $observedList): MoveAccepted
    {
        // Req 4.2: the length of the Move_List before acceptance. Derived from the
        // OBSERVED list, which is the whole of the concurrency story — two competing
        // requests derive the same value and one of them loses the unique index.
        // This one expression is also the sole delivery mechanism for the
        // application half of Property 10: contiguity from zero is not a persisted
        // constraint, since the schema accepts rows carrying 0, 1, 2, 4, 5.
        $sequenceIndex = $observedList->count();

        // Attributes assigned one at a time because mass assignment is closed on
        // this model, which is the point of closing it: every column written is
        // written here, visibly. There is no `mark` column to write — the Mark is
        // the parity of the Sequence_Index (Req 11.4) — and no `updated_at`, which
        // `Move::UPDATED_AT = null` handles.
        //
        // This INSERT is where a conflict is settled. It is also why the model is
        // used rather than `DB::table('moves')`: the connection layer raises
        // `UniqueConstraintViolationException` either way, but a saved model is the
        // same write path every other insert in the application takes.
        $move = new Move;
        $move->game_id = $game->id;
        $move->cell_index = $cellIndex;
        $move->sequence_index = $sequenceIndex;
        $move->save();

        // Re-analysed from the OBSERVED list plus this Cell, not from a fresh read
        // of `moves`. `MoveList::append()` numbers the appended Move at the current
        // length, which is the same `$sequenceIndex` the insert used, so the list
        // analysed here is exactly the list now persisted.
        $analysis = RulesEngine::analyse($observedList->append($cellIndex));

        if ($analysis instanceof InvalidMoveList) {
            // UNREACHABLE BY REQUIREMENT 11.6, AND THEREFORE CORRUPTION RATHER THAN
            // A USER ERROR. Every Move in the observed list was accepted by these
            // same guards, and the appended one has just been checked for range and
            // occupancy against a list the engine already declared well formed — so
            // reaching here means the persisted Move_List disagrees with the
            // persisted Game_State (a Move after a completed Winning_Line, on a row
            // still marked `active`), which no path through this class can produce.
            //
            // DELIBERATELY NOT `MoveOutcome::InvalidMove`. That would report a
            // corrupt database row to the Player as "that Cell is not available",
            // hide a defect behind an ordinary rejection, and leave the corruption
            // in place to be met again on the next request. The design's failure
            // table maps it to a 500 with a `game.invariant_violation` record
            // carrying the Game_Id and no state change; throwing from inside the
            // transaction is what delivers the "no state change" half, because the
            // insert above rolls back with it.
            //
            // Task 10.x adds the log record, keyed on this exception class — which
            // is why `CorruptMoveListException` carries the Game_Id rather than
            // being a bare `RuntimeException` with a message to match on.
            throw new CorruptMoveListException($game->id);
        }

        // ONE UPDATE, and `version_counter + 1` is an expression the DATABASE
        // evaluates (Req 4.7) — never a value read into PHP and incremented, which
        // would be a read-modify-write with a window of its own. This is the same
        // form `JoinGame` uses for the same reason; the design names exactly three
        // increment sites and requires all three to take this shape.
        //
        // `state` and `winning_mark` are the two derived facts that are persisted
        // rather than re-derived on read (Req 6.2, 6.4), and they are written from
        // the `Analysis` above and from nothing else — which is what makes Property
        // 11's "the persisted `winning_mark` equals the derived winner" a claim
        // about this line, and what lets `GameRepresentation` read the column
        // honestly instead of reconciling it. A drawn or in-progress Game writes
        // NULL, so the CHECK pairing a non-null `winning_mark` with `state = 'won'`
        // holds by construction.
        //
        // Eloquent's builder rather than `DB::table()` so `updated_at` is maintained
        // the way every other write to this row maintains it; the enums are passed
        // as their backing values because that is the stored representation.
        // Unguarded, deliberately: a `WHERE state = 'active'` would be a second
        // opinion on guard 2 evaluated against a row this transaction has not read.
        Game::query()
            ->whereKey($game->id)
            ->update([
                'state' => GameState::fromOutcome($analysis->outcome)->value,
                'winning_mark' => $analysis->winner()?->value,
                'version_counter' => DB::raw('version_counter + 1'),
                'last_activity_at' => now(),
            ]);

        // The in-memory `$game` is deliberately NOT refreshed. The caller redirects
        // and the GET that follows reads the row afresh (task 6.2), so a refresh
        // here would be a query serving nothing — and this is the write path of a
        // feature whose read path polls every two seconds.
        return new MoveAccepted(
            mark: $actingMark,
            cellIndex: $cellIndex,
            sequenceIndex: $sequenceIndex,
            outcome: $analysis->outcome,
        );
    }
}
