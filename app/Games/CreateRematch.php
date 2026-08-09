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
 * and mints the requesting session's Player_Token for it (Req 7.2–7.11, 7.14, 7.15).
 *
 * The server cannot write a token into the absent Player's browser, so a Rematch is
 * inserted with BOTH token slots NULL and each Player's token is minted when that
 * Player's own session next presents a valid token for the preceding Game (Req 7.6,
 * 7.7). Do not fold the minting back into the insert: issuing tokens to both
 * Players at creation is unsatisfiable, and that is the defect this shape exists to
 * avoid.
 *
 * Idempotence is the unique index `games_rematch_of_unique`, not a check (Req 7.8):
 * requests from either Player in any order converge on one row (Req 7.9, 7.15). The
 * `SELECT` before the insert is the fast path; the `catch` is what makes the claim
 * hold for simultaneous clicks, since a `SELECT` alone has a window between the
 * question and the insert.
 *
 * Two writes the preceding Game does not receive, both load-bearing.
 *
 *   - `last_activity_at` is not touched. Requirement 13.2 makes expiry eligibility
 *     a function of the most recent accepted Move or Game_State change; a Rematch is
 *     neither, and bumping the column would keep a finished Game alive past the
 *     stated threshold.
 *   - `version_counter` is not incremented for the minting, which changes no part
 *     of the representation a client receives. Creation does increment, and it
 *     increments the **preceding** Game (Req 7.5) because that is the row the
 *     opponent is polling.
 *
 * Nothing here names the `moves` table, so the preceding Move_List is retained (Req
 * 7.14) by there being no such statement.
 *
 * The `rematch.created` record goes through `GameEventLogger`, the sole writer
 * (Req 10.3), and is emitted by the request that inserted the row and by no other:
 * a request that finds the existing Rematch, including the loser of a race, created
 * nothing to report, and minting a token is not an event.
 *
 * Absent by design: any rate limit (task 9.4 owns the four that exist), and
 * everything about the response — `CreateRematchController` maps the two halves of
 * the return type onto the two 303s.
 */
final class CreateRematch
{
    /**
     * `GameEventLogger` needs no constructor arguments of its own, so the default
     * is exactly the instance the container would inject and this class stays
     * constructible without one.
     */
    public function __construct(
        private readonly PlayerTokens $tokens,
        private readonly GameEventLogger $events = new GameEventLogger,
    ) {}

    /**
     * Resolves `$preceding` to its one Rematch — creating it if this is the first
     * request — and issues this session a Player_Token for it.
     *
     * `ResolvedPlayer` rather than the design's `array{Game, Mark}`: by the time one
     * is constructed below the hash is on the Rematch row and the raw value is in the
     * session, so the claim it makes is true. A tuple would be destructured by
     * position, one transposition away from pairing a Game with the wrong Mark.
     *
     * `$precedingMark` is the Mark this session held in the preceding Game, arriving
     * from `PlayerTokens::resolve()` and never from a payload (Req 3.2, 3.6, 7.7). It
     * is a `Mark` rather than a `?Mark` because authorisation is settled before this
     * method is called, which is how Requirement 7.11's `not_authorised` is carried
     * in the signature rather than in a branch.
     *
     * `$preceding` is a `Game` and not a `GameSnapshot` deliberately: no part of the
     * answer uses the Move_List, so `GameSnapshot::of()` would issue an unused query.
     *
     * @throws UniqueConstraintViolationException if an insert collides on
     *                                            something other than
     *                                            `rematch_of_game_id` — see
     *                                            `createRematchOf()`
     */
    public function handle(Game $preceding, Mark $precedingMark): ResolvedPlayer|RematchOutcome
    {
        // Req 7.10, and the only guard in this class. A Game still
        // `waiting_for_opponent` and a Game still `active` are refused by the same
        // value, because Requirement 7.10 draws no distinction between them and
        // neither does the outcome vocabulary.
        //
        // Being the first statement, nothing can have been written — Requirement
        // 7.10's half of Property 9 by construction rather than by a rollback.
        if (! $preceding->state->isTerminal()) {
            return RematchOutcome::InvalidState;
        }

        $rematch = $this->existingRematchOf($preceding) ?? $this->createRematchOf($preceding);

        // Req 7.3: the swap, derived and never stored. There must be no column
        // recording which Mark a Player takes in the Rematch — the Mark is computed
        // at every request from the token that session presented for the preceding
        // Game. That is what makes the swap correct for the second Player however
        // long after the first they arrive, and irrespective of which of them
        // requested the Rematch.
        //
        // Storing the swap would mean committing, at creation, which slot the absent
        // Player will own — the same impossible commitment as minting their token.
        $mark = $precedingMark->opponent();

        $this->mintFor($rematch, $mark);

        return new ResolvedPlayer($rematch, $mark);
    }

    /**
     * The Rematch already recorded against `$preceding`, or null.
     *
     * Through the `rematch` relationship, a `HasOne` precisely because the unique
     * index makes at most one Rematch a persisted fact — the schema's guarantee read
     * back rather than a `first()` over an undefined ordering.
     *
     * `$preceding->rematch()->first()` and NOT `$preceding->rematch`: the dynamic
     * property memoises, and this method is called twice on the losing path of a
     * concurrent pair, once before the insert and once after the unique index
     * refuses it. A memoised null would be returned again, so the loser of the race
     * would see no Rematch at all.
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
     * The two statements are inseparable: Requirement 7.5's increment is what a
     * polling opponent observes and the row it describes is the Rematch, so committed
     * separately a failure between them would leave either a Rematch nobody's poll
     * reports or an increment describing a Rematch that does not exist. Property 12
     * requires the operation to be one commit.
     *
     * The insert comes first because it is the statement that can fail, so the
     * increment is only ever reached by a request that has already won rather than
     * written and rolled back.
     *
     * The violation is caught OUTSIDE the transaction, as `SubmitMove` catches its
     * conflict outside: catching inside the closure would let `DB::transaction()`
     * commit a transaction whose insert had failed, and outside the loser has written
     * nothing at all, so the winner's increment is the only one. Caught rather than
     * checked for (Req 7.8, 7.9) because a second `existingRematchOf()` would only
     * narrow the window, never close it.
     *
     * The re-read is allowed to fail. `games_rematch_of_unique` is not the only unique
     * index on this table — a primary-key collision on the generated UUIDv7 arrives as
     * the same exception class — so on that path the re-read finds nothing and `??
     * throw` rethrows, a 500 for a defect rather than a lost race, without inspecting
     * a driver-specific message.
     *
     * @throws UniqueConstraintViolationException if the collision was not on
     *                                            `rematch_of_game_id`
     */
    private function createRematchOf(Game $preceding): Game
    {
        try {
            $rematch = DB::transaction(function () use ($preceding): Game {
                // Attributes assigned one at a time because mass assignment is
                // closed on this model: every column written at creation is written
                // here, visibly. The columns NOT written are as much of the
                // specification as the ones that are, each left unset so the insert
                // omits it and it defaults to NULL:
                //
                //   - `join_code` — a Rematch is reached by navigation and has
                //     nothing to join (Req 7.2). The CHECK requiring one of the two
                //     reachability columns is satisfied by `rematch_of_game_id`
                //     below. SQLite treats NULLs as distinct in a unique index, so
                //     every Rematch may carry a NULL Join_Code.
                //   - `x_token_hash` and `o_token_hash` — both NULL, and no CHECK
                //     requires otherwise, because the swap means the first requester
                //     may fill `o_token_hash` while `x_token_hash` stays NULL until
                //     the other Player arrives.
                //   - `winning_mark` — NULL, which the CHECK pairing it with
                //     `state = 'won'` requires of an `active` row.
                //
                // `state` is `active` directly (Req 7.2): both Players are already
                // established, so `waiting_for_opponent` would be a state no join
                // could leave — there is no Join_Code to join with. It also makes
                // the antecedent of the waiting-state CHECK false, so the NULL
                // `o_token_hash` is unconstrained.
                //
                // An empty Move_List (Req 7.2) needs no statement: a Game with no
                // `moves` rows is a Game with an empty Move_List.
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
                // `last_activity_at` is absent from this UPDATE and must stay absent
                // (Req 13.2). Nothing else about the preceding row changes: its
                // `state`, `winning_mark` and Move_List are left exactly as the
                // final Move left them (Req 7.14).
                Game::query()
                    ->whereKey($preceding->id)
                    ->update([
                        'version_counter' => DB::raw('version_counter + 1'),
                    ]);

                return $rematch;
            });
        } catch (UniqueConstraintViolationException $lost) {
            // No record on this path: the Rematch returned here was created by
            // another request, which emitted its own (Req 10.3).
            return $this->existingRematchOf($preceding) ?? throw $lost;
        }

        // After the commit and outside the closure, which a retry could run twice.
        // `game_id` is the PRECEDING Game — the row the increment above described and
        // the one an opponent is polling — and `rematch_game_id` the Game just
        // inserted.
        $this->events->rematchCreated($preceding->id, $rematch->id);

        return $rematch;
    }

    /**
     * Mints this session's Player_Token for `$rematch`, binds it to `$mark` by
     * storing its hash in that Mark's slot, and puts the raw value in the session.
     *
     * Outside the transaction above and not part of the creation, because it runs on
     * every request: one that finds an existing Rematch must still issue *its* session
     * a token (Req 7.6, 7.9, 7.15), and the creating request is only ever one of the
     * two Players.
     *
     * `mint()` and `remember()`, not `issue()`, because `issue()` writes the session
     * while leaving persistence to the caller: the row may be one this request just
     * lost the race to insert, so the hash must be persisted before the browser is
     * told it holds a credential. Ordering is `mint` → persist → `remember`, which is
     * `remember()`'s documented "call this last".
     *
     * The `UPDATE` touches neither `version_counter` nor `last_activity_at` on either
     * row: the token is never a prop and never a response body (Req 8.7), so minting
     * changes no part of the representation a client receives. `updated_at` moves
     * because `save()` maintains it; no criterion reads it.
     *
     * Minting replaces whatever hash was in the slot, which is not an escalation: the
     * only caller who can reach this line for a given Mark is the holder of the
     * preceding Game's token for the opposite Mark, precisely the identity that owns
     * the slot (Req 7.7). It cannot hand a slot to the other Player, because `$mark`
     * is derived from the caller's own preceding Mark. Replacement is how a Player who
     * lost their Rematch token but kept the preceding one recovers.
     */
    private function mintFor(Game $rematch, Mark $mark): void
    {
        $token = $this->tokens->mint();

        // The slot IS the binding (Req 3.1): nothing in the token names the Game or
        // the Mark, so `x_token_hash` versus `o_token_hash` is the entire record of
        // which Player this credential belongs to. The `match` is exhaustive over
        // `Mark`, mirroring `PlayerTokens::issue()`.
        match ($mark) {
            Mark::X => $rematch->x_token_hash = $token->hash,
            Mark::O => $rematch->o_token_hash = $token->hash,
        };

        $rematch->save();

        $this->tokens->remember($rematch->id, $token);
    }
}
