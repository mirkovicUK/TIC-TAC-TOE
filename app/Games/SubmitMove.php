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
 * `handle()` is a pure function of `($observed, $actingMark, $cellIndex)` and
 * issues no `SELECT` for Game state. That is what makes Requirement 5.3's "exactly
 * one of two concurrent Moves is accepted" a persisted invariant: two competing
 * requests derive the same `sequence_index` from their own snapshot, and the unique
 * index on `(game_id, sequence_index)` settles the collision. It also lets task 6.8
 * model concurrency by passing one snapshot to two successive calls.
 *
 * Do not add `$game->refresh()`, a second `GameSnapshot::of()`, `lockForUpdate()`,
 * or a transaction around the guards that re-reads. No single-request outcome
 * changes, but the second of two competing calls would then observe the first's
 * Move, fail the `markToMove` guard and return `not_your_turn` — so the `conflict`
 * path would stop being exercised while its test carried on passing. The only
 * mechanical guards are `SubmitMoveMechanismTest`'s query-log assertions (one
 * INSERT, one UPDATE, no `SELECT`; empty log on every rejection) and task 6.8's
 * two-call test.
 *
 * `$actingMark` arrives as a parameter, from the Player_Token and from nothing else
 * (Req 3.2, 3.6): no request, payload or session is in scope here, and `moves` has
 * no `mark` column for one to be written to by accident.
 *
 * Lifecycle logging goes through `GameEventLogger`, the sole writer, and every
 * record is emitted OUTSIDE the transaction below (Req 10.3): a record written
 * inside it would survive a rollback that undid the Move it describes, and the
 * closure may run more than once. An accepted Move that ends the Game emits two
 * records — `move.accepted` and `game.finished` — because Requirement 10.3 lists
 * the two events separately.
 *
 * Absent by design: everything about the response including the 303 that carries
 * every rejection (task 6.2), and `throttle:move` (task 9.4). `cell_index` is
 * checked here rather than by a Form Request because a non-integer or out-of-range
 * Cell must produce `invalid_move` rather than a 422 payload.
 */
final class SubmitMove
{
    /**
     * `GameEventLogger` needs no constructor arguments of its own, so the default
     * is exactly the instance the container would inject and this class stays
     * constructible without one.
     */
    public function __construct(
        private readonly GameEventLogger $events = new GameEventLogger,
    ) {}

    /**
     * Attempts `$cellIndex` for `$actingMark` against the state `$observed`
     * records, and either commits it or returns one of five refusals.
     *
     * The union is the design's `MoveResult` and has no common supertype, so a
     * caller must narrow with `instanceof`. The rejection half carries no state:
     * Requirement 5.5's "with the current state" is supplied by the redirect and
     * the fresh GET, not by the return value.
     *
     * Zero queries on every rejection path, exactly two on the accepted path — the
     * `INSERT` into `moves` and the `UPDATE` of `games`, never a `SELECT`.
     *
     * @param  GameSnapshot  $observed  The state this request read, and the only
     *                                  state any guard below consults.
     * @param  Mark  $actingMark  From `PlayerTokens::resolve()` by way of
     *                            `ResolvedPlayer`, and from nothing else.
     * @param  mixed  $cellIndex  The Cell as the request carried it, of any type:
     *                            `mixed` because "not an integer" is a condition
     *                            this method must turn into an outcome (Req 4.4),
     *                            not a `TypeError` for a user-supplied value.
     *
     * @throws CorruptMoveListException if the Move_List including this Move is
     *                                  rejected by the Rules_Engine — corruption,
     *                                  not a user error. See below.
     */
    public function handle(GameSnapshot $observed, Mark $actingMark, mixed $cellIndex): MoveAccepted|MoveOutcome
    {
        $game = $observed->game;

        // Guard 1 (Req 4.5). The persisted Game_State, because "waiting for an
        // opponent" is the one fact a Move_List cannot express — an empty list is a
        // Game with no second Player and a Game whose second Player has not moved,
        // indistinguishably (see `GameState`). This must precede the turn guard:
        // Mark_To_Move on an empty Move_List is `X`, so the Creator moving into
        // their own waiting Game would otherwise pass guard 3 and reach the insert.
        if ($game->state === GameState::WaitingForOpponent) {
            return $this->refuse($observed, $actingMark, $cellIndex, MoveOutcome::GameNotStarted);
        }

        // Guard 2 (Req 4.6). Also the persisted state, not
        // `$observed->analysis->isTerminal()`. The two agree by construction — the
        // transaction below writes the state from the very `Analysis` a later
        // request re-derives. Where they disagree the Game is corrupt, which the
        // engine catches below rather than a second opinion here.
        if ($game->state->isTerminal()) {
            return $this->refuse($observed, $actingMark, $cellIndex, MoveOutcome::GameEnded);
        }

        // Guard 3 (Req 3.5). Turn ownership, checked BEFORE cell validity on
        // purpose: the requirements do not order the two, and reversing them would
        // leak Cell occupancy to a Player who cannot act.
        //
        // `$actingMark` is compared against the derived Mark_To_Move and nothing
        // else. There is no payload in scope to have taken a Mark from (Req 3.6).
        if ($observed->analysis->markToMove !== $actingMark) {
            return $this->refuse($observed, $actingMark, $cellIndex, MoveOutcome::NotYourTurn);
        }

        // Guard 4 (Req 4.3, 4.4). Three conditions, one outcome: not an integer,
        // outside 0..8, or already occupied in the observed Board. One vocabulary
        // for one condition, so nothing here distinguishes them.
        //
        // `is_int()` is STRICT, so `'4'` is refused. Task 6.2 must therefore hand
        // over the decoded JSON value rather than a cast or a `->integer()` read: a
        // cast turns `'banana'` into `0`, a legal Cell, making a malformed payload
        // into a Move in the top-left corner.
        if (! is_int($cellIndex) || $cellIndex < 0 || $cellIndex > 8) {
            return $this->refuse($observed, $actingMark, $cellIndex, MoveOutcome::InvalidMove);
        }

        if ($observed->analysis->board->isOccupied($cellIndex)) {
            return $this->refuse($observed, $actingMark, $cellIndex, MoveOutcome::InvalidMove);
        }

        // Guards 1 to 4 have written nothing, which is Requirements 4.3–4.6's "SHALL
        // leave the Move_List unchanged" and half of Property 9 — delivered by there
        // being no write above this line rather than by a rollback.

        try {
            $accepted = DB::transaction(
                fn (): MoveAccepted => $this->commit($game, $actingMark, $cellIndex, $observed->moveList),
            );
        } catch (UniqueConstraintViolationException) {
            // Req 5.4. Both unique indexes on `moves` mean the same thing — another
            // Move landed first — so both map to one outcome:
            // `(game_id, sequence_index)` when the other Move took this turn,
            // `(game_id, cell_index)` when it took this Cell.
            //
            // Caught rather than checked for: a `SELECT` asking whether the
            // Sequence_Index is free would be a read, forbidden by the invariant
            // above, and it would race.
            //
            // The catch sits OUTSIDE the transaction, so the rollback has already
            // undone the Version_Counter increment and the state transition (the
            // other half of Property 9 for this outcome). Catching inside the
            // closure would let `DB::transaction` commit a transaction whose insert
            // had failed.
            return $this->refuse($observed, $actingMark, $cellIndex, MoveOutcome::Conflict);
        }

        // Both records describe committed facts: the transaction has returned, so
        // there is no path from here on which the Move is undone (Req 10.3).
        $this->events->moveAccepted(
            $game->id,
            $accepted->mark,
            $accepted->cellIndex,
            $accepted->sequenceIndex,
        );

        // The Terminal_State transition is a second event, not a field of the first
        // (Req 10.3), so a winning or drawing Move emits two records. `Outcome`
        // carries both `result` and `winning_mark`, which is why the pair on the
        // record cannot disagree with the pair the UPDATE above wrote.
        if ($accepted->outcome->isTerminal()) {
            $this->events->gameFinished($game->id, $accepted->outcome);
        }

        return $accepted;
    }

    /**
     * Emits the `move.rejected` record for `$outcome` and returns it unchanged, so
     * that a refusal is one expression at each guard and no guard can return without
     * its record (Req 10.3, 10.4).
     *
     * The Sequence_Index is the length of the OBSERVED Move_List — the position this
     * Move would have taken — which is defined for every rejection, including those
     * refused before a Move was derived. The Mark is the acting Mark, from the
     * Player_Token, never the Mark_To_Move.
     *
     * Writes nothing and reads nothing, so the "zero queries on every rejection
     * path" invariant above still holds: `GameEventLogger` goes to a stream.
     */
    private function refuse(GameSnapshot $observed, Mark $actingMark, mixed $cellIndex, MoveOutcome $outcome): MoveOutcome
    {
        $this->events->moveRejected(
            $observed->game->id,
            $actingMark,
            $cellIndex,
            $observed->moveList->count(),
            $outcome,
        );

        return $outcome;
    }

    /**
     * The write, both halves of it, inside one transaction (Req 4.7, 6.2, 6.4).
     *
     * The insert and the `games` UPDATE must commit together or not at all. Property
     * 12 claims the Version_Counter increases by exactly one per *committed*
     * state-changing operation, so as separate statements a failure between them
     * would leave either a Move whose counter never moved — invisible to a polling
     * client (Req 8.3) — or a Game marked `won` with no Move to have won it.
     *
     * Handed the observed Move_List rather than the `GameSnapshot` so that what it
     * can consult is visible from its signature: a `MoveList` has no connection to
     * the database, so nothing in here can re-read anything even by accident.
     *
     * @param  MoveList  $observedList  The Move_List as observed, WITHOUT this Move.
     *
     * @throws CorruptMoveListException
     */
    private function commit(Game $game, Mark $actingMark, int $cellIndex, MoveList $observedList): MoveAccepted
    {
        // Req 4.2: the length of the OBSERVED Move_List before acceptance, which is
        // the whole of the concurrency story — two competing requests derive the
        // same value and one loses the unique index. This one expression is also the
        // sole delivery mechanism for the application half of Property 10: the
        // schema accepts rows carrying 0, 1, 2, 4, 5, so contiguity from zero is not
        // a persisted constraint.
        $sequenceIndex = $observedList->count();

        // Attributes assigned one at a time because mass assignment is closed on
        // this model: every column written is written here, visibly. There is no
        // `mark` column — the Mark is the parity of the Sequence_Index (Req 11.4) —
        // and no `updated_at`, which `Move::UPDATED_AT = null` handles.
        //
        // This INSERT is where a conflict is settled. The model rather than
        // `DB::table('moves')` so it is the same write path every other insert
        // takes; either raises `UniqueConstraintViolationException`.
        $move = new Move;
        $move->game_id = $game->id;
        $move->cell_index = $cellIndex;
        $move->sequence_index = $sequenceIndex;
        $move->save();

        // Re-analysed from the OBSERVED list plus this Cell, not from a fresh read
        // of `moves`. `MoveList::append()` numbers the appended Move at the current
        // length, the same `$sequenceIndex` the insert used, so the list analysed
        // here is exactly the list now persisted.
        $analysis = RulesEngine::analyse($observedList->append($cellIndex));

        if ($analysis instanceof InvalidMoveList) {
            // Unreachable by Requirement 11.6, and therefore corruption rather than
            // a user error: every Move in the observed list was accepted by these
            // same guards and the appended one has just been range- and
            // occupancy-checked, so reaching here means the persisted Move_List
            // disagrees with the persisted Game_State.
            //
            // Deliberately NOT `MoveOutcome::InvalidMove`, which would report a
            // corrupt row to the Player as "that Cell is not available" and leave
            // the corruption in place. The design maps this to a 500 with a
            // `game.invariant_violation` record and no state change; throwing from
            // inside the transaction is what delivers the "no state change" half,
            // because the insert above rolls back with it.
            //
            // No record is emitted here. `game.invariant_violation` is not one of the
            // six lifecycle events Requirement 10.3 enumerates, and `GameEventLogger`
            // has a method per event rather than a general one. The exception carries
            // the Game_Id so a record can be keyed on this class rather than on a
            // message, whoever adds it.
            throw new CorruptMoveListException($game->id);
        }

        // One UPDATE, and `version_counter + 1` is an expression the DATABASE
        // evaluates (Req 4.7) — never a value read into PHP and incremented, which
        // would be a read-modify-write with a window of its own. The design names
        // exactly three increment sites and requires all three to take this shape.
        //
        // `state` and `winning_mark` are the two derived facts persisted rather than
        // re-derived on read (Req 6.2, 6.4), written from the `Analysis` above and
        // from nothing else — which is what makes Property 11 a claim about this
        // line. A drawn or in-progress Game writes NULL, so the CHECK pairing a
        // non-null `winning_mark` with `state = 'won'` holds by construction.
        //
        // Eloquent's builder rather than `DB::table()` so `updated_at` is maintained
        // as every other write to this row maintains it; the enums are passed as
        // their backing values because that is the stored representation.
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

        // The in-memory `$game` is deliberately NOT refreshed: the caller redirects
        // and the GET that follows reads the row afresh (task 6.2), so a refresh
        // here would be a query serving nothing.
        return new MoveAccepted(
            mark: $actingMark,
            cellIndex: $cellIndex,
            sequenceIndex: $sequenceIndex,
            outcome: $analysis->outcome,
        );
    }
}
