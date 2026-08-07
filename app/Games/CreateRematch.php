<?php

declare(strict_types=1);

namespace App\Games;

use App\Domain\TicTacToe\Mark;
use App\Models\Game;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates the Rematch of a finished Game, or returns the one that already exists,
 * and mints the requesting session's Player_Token for it (Req 7.2–7.11, 7.14,
 * 7.15, ADR-010).
 *
 * THE ONE FACT THAT SHAPES EVERY LINE BELOW: THE SERVER CANNOT WRITE A TOKEN INTO
 * THE ABSENT PLAYER'S BROWSER. Only the requesting session is present in a
 * request, so a Rematch is inserted with BOTH token slots NULL and each Player's
 * token is minted when that Player's own session next presents a valid token for
 * the preceding Game (Req 7.6, 7.7). An earlier draft of Requirement 7 had this
 * service issue tokens to *both* Players at creation; no implementation could
 * satisfy it, and the correction is recorded in `docs/ai-direction.md` under
 * "Requirements that specified impossible or vacuous behaviour". This class is the
 * rewritten requirement made real, so the per-request minting is not an
 * optimisation to be folded back into the insert — folding it back is the defect
 * that was already found once.
 *
 * IDEMPOTENT BY THE UNIQUE INDEX, NOT BY A CHECK. `games_rematch_of_unique` makes
 * "at most one Rematch per Game" a persisted fact (Req 7.8), so any number of
 * requests from either Player in any order converge on the one row (Req 7.9,
 * 7.15). The `SELECT` before the insert is the fast path for the ordinary case —
 * one Player clicking after the other — and the `catch` below is what makes the
 * claim true when the two clicks are simultaneous. A `SELECT` alone could not: it
 * has a window between the question and the insert, and the loser of that window
 * would otherwise be a 500 on a request the requirements say must return a
 * Rematch.
 *
 * TWO WRITES THE PRECEDING GAME DOES **NOT** RECEIVE, and both absences are
 * load-bearing.
 *
 *   - **`last_activity_at` is not touched.** Requirement 13.2 makes expiry
 *     eligibility a function of the most recent accepted Move or Game_State
 *     change; a Rematch is neither, and bumping the column here would keep a
 *     finished Game alive past the stated threshold. The design's column table
 *     says so outright.
 *   - **`version_counter` is not incremented for the minting.** The design names
 *     exactly three increment sites, and minting a token writes to a row while
 *     changing no part of the representation a client receives, so it is not a
 *     state-changing operation for versioning purposes. Creation *is*, and it
 *     increments the **preceding** Game (Req 7.5) rather than the Rematch — which
 *     is the whole point of that increment: the opponent is polling the preceding
 *     Game, and the increment is what their next poll observes.
 *
 * THE PRECEDING MOVE_LIST IS NEVER TOUCHED (Req 7.14). Nothing in this class
 * names the `moves` table, deletes a row, or writes to one — the retention is a
 * consequence of there being no such statement rather than of a decision to keep
 * them.
 *
 * WHAT IS DELIBERATELY NOT HERE.
 *
 *   - **Logging.** The design lists a `rematch.created` record with a
 *     `rematch_game_id` field (Req 10.3), and `GameEventLogger` is task 10.2's
 *     sole writer of lifecycle records — exclusively, because Requirement 10.5's
 *     redaction is why it is the only writer. The record belongs here when that
 *     class arrives and is left out rather than approximated, exactly as
 *     `CreateGame` and `JoinGame` leave theirs out. This task's criteria carry no
 *     logging criterion among them.
 *   - **A rate limit.** The design's limiter table applies no named limiter to
 *     this route, and task 9.4 owns the four that do exist. A limiter inside a
 *     service would fire on a path no HTTP request took.
 *   - **Anything about the response.** No redirect, no status, no representation.
 *     `CreateRematchController` maps the two halves of the return type onto the
 *     two 303s the design's outcome table specifies. Nothing in `App\Games` knows
 *     what an HTTP status is.
 */
final class CreateRematch
{
    public function __construct(
        private readonly PlayerTokens $tokens,
    ) {}

    /**
     * Resolves `$preceding` to its one Rematch — creating it if this is the first
     * request — and issues this session a Player_Token for it.
     *
     * THE RETURN TYPE REUSES `ResolvedPlayer`, AS `JoinGame` DOES, and the reuse
     * is honest for the same reason it is honest there. An instance of that class
     * is the statement "this session is an authorised Player of this Game, holding
     * a Player_Token bound to this Mark", and by the time one is constructed below
     * the hash is on the Rematch row and the raw value is in the session — so a
     * `GameResolver::resolve()` call made immediately afterwards, in this session,
     * would construct an *equal* instance from the persisted row. The design
     * writes the signature as `array{Game, Mark}`; a two-element tuple is
     * pseudocode for the pair, and the pair already has a name in this namespace,
     * with a docblock explaining what holding one means. A `list{Game, Mark}` would
     * also be destructured at the call site by position, which is one transposition
     * away from pairing a Game with the wrong Mark.
     *
     * `$precedingMark` IS THE MARK THIS SESSION HELD IN THE PRECEDING GAME, and it
     * arrives from `PlayerTokens::resolve()` by way of `ResolvedPlayer` — never
     * from a payload (Req 3.2, 3.6, 7.7). It is a `Mark` rather than a `?Mark`
     * because authorisation is settled before this method is called: there is no
     * "not a Player" value to pass, which is how Requirement 7.11's
     * `not_authorised` is carried in the signature rather than in a branch.
     *
     * `$preceding` IS A `Game` AND NOT A `GameSnapshot`, deliberately. The guard
     * below reads one column of the row; the Move_List is not consulted, and
     * `GameSnapshot::of()` would issue a query no part of the answer uses — the
     * third bullet of `ResolvedPlayer`'s docblock names this route as the reason a
     * snapshot is not built during resolution in the first place.
     *
     * @throws UniqueConstraintViolationException if an insert collides on
     *                                            something other than
     *                                            `rematch_of_game_id` — see
     *                                            `createRematchOf()`
     */
    public function handle(Game $preceding, Mark $precedingMark): ResolvedPlayer|RematchOutcome
    {
        // Req 7.10. The persisted Game_State, and the only guard in this class.
        // `isTerminal()` is `GameState`'s, whose docblock names this call site: a
        // Game still `waiting_for_opponent` and a Game still `active` are refused
        // by the same value, because Requirement 7.10 draws no distinction between
        // them and neither does the outcome vocabulary.
        //
        // Nothing has been written at this point and nothing can have been, since
        // this is the first statement — which is Requirement 7.10's half of
        // Property 9 delivered by construction rather than by a rollback.
        if (! $preceding->state->isTerminal()) {
            return RematchOutcome::InvalidState;
        }

        $rematch = $this->existingRematchOf($preceding) ?? $this->createRematchOf($preceding);

        // Req 7.3: the swap, DERIVED AND NEVER STORED. There is no column
        // recording which Mark a Player takes in the Rematch and there must not be
        // one: the Mark is `$precedingMark->opponent()` computed at every request,
        // from the token the requesting session presented for the preceding Game
        // and from nothing else. That is what makes the swap correct for the second
        // Player however long after the first they arrive, and irrespective of
        // which of them requested the Rematch (Req 7.3's "irrespective of the
        // connection state of either Player" and "irrespective of which Player
        // requested the Rematch first").
        //
        // Storing the swap would mean writing, at creation, which of two slots the
        // absent Player will eventually own — the same impossible commitment as
        // minting their token, one step removed.
        $mark = $precedingMark->opponent();

        $this->mintFor($rematch, $mark);

        return new ResolvedPlayer($rematch, $mark);
    }

    /**
     * The Rematch already recorded against `$preceding`, or null.
     *
     * Through the `rematch` relationship, which `Game`'s docblock declares a
     * `HasOne` precisely because the unique index makes at most one Rematch a
     * persisted fact — so this is the schema's guarantee read back rather than a
     * `first()` over an ordering nobody defined.
     *
     * `$preceding->rematch()->first()` AND NOT `$preceding->rematch`. The dynamic
     * property memoises, and this method is called twice on the losing path of a
     * concurrent pair: once before the insert is attempted and once after the
     * unique index refuses it. A memoised null from the first call would be
     * returned again by the second, and the loser of the race would see no Rematch
     * at all — the one path this method exists to serve.
     */
    private function existingRematchOf(Game $preceding): ?Game
    {
        return $preceding->rematch()->first();
    }

    /**
     * Inserts the Rematch and increments the preceding Game's Version_Counter, in
     * one transaction — or, having lost a race, returns the Rematch the winner
     * inserted.
     *
     * ONE TRANSACTION, AND THE TWO STATEMENTS INSIDE IT ARE INSEPARABLE. Requirement
     * 7.5's increment is what a polling opponent observes; the row it describes is
     * the Rematch. Committed separately, a failure between them would leave either
     * a Rematch nobody's poll reports or an increment describing a Rematch that does
     * not exist. Property 12 claims the counter moves exactly once per *committed*
     * state-changing operation, so the operation has to be one commit.
     *
     * THE INSERT COMES FIRST, AND THE ORDER IS THE POINT. It is the statement that
     * can fail, so putting it first means the increment is only ever reached by a
     * request that has already won. Reversed, the loser would increment the
     * preceding Game's counter and then roll it back — correct, but resting on the
     * rollback rather than on never having written.
     *
     * THE VIOLATION IS CAUGHT OUTSIDE THE TRANSACTION, as `SubmitMove` catches its
     * conflict outside. Catching inside the closure would let `DB::transaction()`
     * commit a transaction whose insert had failed, and would leave the increment's
     * fate to the database's statement-level rollback semantics rather than to
     * something visible here. Outside, the whole transaction has rolled back before
     * the `catch` body runs, so the loser has written nothing at all: the winner's
     * single increment is the only one, which is Requirement 7.5 holding for a
     * concurrent pair and not merely for a lone request.
     *
     * CAUGHT RATHER THAN CHECKED FOR (Req 7.8, 7.9). `existingRematchOf()` was
     * already consulted before this method was called and answered null; asking
     * again in a tighter loop would only narrow the window, never close it. The
     * exception is the answer arriving from the only component that can give it
     * without a window.
     *
     * AND THE RE-READ IS ALLOWED TO FAIL. `games_rematch_of_unique` is not the only
     * unique index on this table: a primary-key collision on the freshly generated
     * UUIDv7 would arrive here as the same exception class. On that path the re-read
     * finds nothing, and the original exception is rethrown rather than swallowed —
     * a 500, which is the honest answer, because ~74 random bits do not collide and
     * a Rematch that cannot be found after a violation is a defect rather than a
     * lost race. `?? throw` is what keeps the two cases apart without inspecting a
     * driver-specific message.
     *
     * @throws UniqueConstraintViolationException if the collision was not on
     *                                            `rematch_of_game_id`
     */
    private function createRematchOf(Game $preceding): Game
    {
        try {
            return DB::transaction(function () use ($preceding): Game {
                // Attributes assigned one at a time because mass assignment is
                // closed on this model, which is the point of closing it: every
                // column written at creation is written here, visibly. The columns
                // NOT written are as much of the specification as the ones that
                // are, and each is left unset so the insert omits it and it
                // defaults to NULL:
                //
                //   - `join_code` — a Rematch is reached by navigation and has
                //     nothing to join (Req 7.2). The CHECK requiring one of the two
                //     reachability columns is satisfied by `rematch_of_game_id`
                //     below. SQLite treats NULLs as distinct in a unique index, so
                //     every Rematch may carry a NULL Join_Code.
                //   - `x_token_hash` and `o_token_hash` — BOTH NULL, and no CHECK
                //     requires otherwise. One requiring `x_token_hash IS NOT NULL`
                //     was present in an earlier draft of the schema and is recorded
                //     as removed in both the design and the migration, because the
                //     swap means the first requester may fill `o_token_hash` while
                //     `x_token_hash` stays NULL until the other Player arrives.
                //   - `winning_mark` — NULL, which the CHECK pairing it with
                //     `state = 'won'` requires of an `active` row.
                //
                // `state` is `active` directly (Req 7.2): both Players are already
                // established by the preceding Game, so there is nobody to wait
                // for, and `waiting_for_opponent` would be a state no join could
                // ever leave — there is no Join_Code to join with. It also makes
                // the antecedent of the waiting-state CHECK false, so the NULL
                // `o_token_hash` is unconstrained.
                //
                // An empty Move_List (Req 7.2) needs no statement: a Move_List is
                // the `moves` rows of a Game, so a Game with no rows *is* a Game
                // with an empty Move_List.
                $rematch = new Game;
                $rematch->id = Str::uuid7()->toString();
                $rematch->state = GameState::Active;
                $rematch->version_counter = 0;
                $rematch->rematch_of_game_id = $preceding->id;
                $rematch->last_activity_at = now();
                $rematch->save();

                // Req 7.5, and it increments the PRECEDING Game. `version_counter
                // + 1` is an expression the database evaluates, never a value read
                // into PHP and incremented — the form the design requires of all
                // three increment sites, because a read-modify-write has a window
                // of its own.
                //
                // `last_activity_at` IS ABSENT FROM THIS UPDATE AND MUST STAY
                // ABSENT (Req 13.2). Nothing else about the preceding row changes:
                // its `state`, its `winning_mark` and its Move_List are all left
                // exactly as the final Move left them (Req 7.14).
                Game::query()
                    ->whereKey($preceding->id)
                    ->update([
                        'version_counter' => DB::raw('version_counter + 1'),
                    ]);

                return $rematch;
            });
        } catch (UniqueConstraintViolationException $lost) {
            return $this->existingRematchOf($preceding) ?? throw $lost;
        }
    }

    /**
     * Mints this session's Player_Token for `$rematch`, binds it to `$mark` by
     * storing its hash in that Mark's slot, and puts the raw value in the session.
     *
     * OUTSIDE THE TRANSACTION ABOVE, AND NOT PART OF THE CREATION. This runs on
     * every request, including the fifth request from a Player who already holds a
     * token — because a request that finds an existing Rematch must still issue
     * *its* session a token (Req 7.6, 7.9, 7.15), and because the request that
     * created the Rematch is only ever one of the two Players. The two steps are
     * separate in the design for exactly this reason.
     *
     * `mint()` AND `remember()`, NOT `issue()`. `issue()` writes the session as
     * part of minting and leaves persistence to the caller, which is right for
     * `CreateGame`'s single fresh insert and wrong here: the row this writes to may
     * be one this request just lost the race to insert, so the hash must be
     * persisted before the browser is told it holds a credential. The ordering
     * below is `mint` → persist → `remember`, which is `remember()`'s documented
     * "call this last" and `JoinGame`'s ordering for the same reason.
     *
     * ONE `UPDATE`, WHICH TOUCHES NEITHER `version_counter` NOR
     * `last_activity_at` ON EITHER ROW. Minting changes no part of the
     * representation a client receives — the token is never a prop, never a
     * response body (Req 8.7) — so it is not a state-changing operation for
     * versioning purposes, and the design's three increment sites remain three.
     * `updated_at` moves, because `save()` maintains it the way every other write
     * to this table does; it is not `last_activity_at` and no criterion reads it.
     *
     * MINTING REPLACES WHATEVER HASH WAS IN THE SLOT, AND THAT IS NOT AN
     * ESCALATION. The only caller who can reach this line for a given Mark is the
     * holder of the preceding Game's token for the opposite Mark — precisely the
     * identity that owns the slot (Req 7.7). Replacement is how a Player who lost
     * their Rematch token but kept the preceding one recovers, which ADR-010 names
     * as a consequence worth having rather than a hole to close. Note what it is
     * not: it cannot hand a slot to the *other* Player, because `$mark` is derived
     * from that caller's own preceding Mark.
     */
    private function mintFor(Game $rematch, Mark $mark): void
    {
        $token = $this->tokens->mint();

        // The slot IS the binding (Req 3.1): there is nothing in the token naming
        // the Game or the Mark, so `x_token_hash` versus `o_token_hash` is the
        // entire record of which Player this credential belongs to. The `match` is
        // exhaustive over `Mark`, mirroring `PlayerTokens::issue()`.
        match ($mark) {
            Mark::X => $rematch->x_token_hash = $token->hash,
            Mark::O => $rematch->o_token_hash = $token->hash,
        };

        $rematch->save();

        $this->tokens->remember($rematch->id, $token);
    }
}
