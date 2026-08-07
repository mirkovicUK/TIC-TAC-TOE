<?php

declare(strict_types=1);

namespace App\Games;

use App\Models\ExpiryRecord;
use App\Models\Game;

/**
 * Turns `(Game_Id, Player_Session)` into either an acting player or a refusal:
 * the seven-row visibility table of the design, and nothing else.
 *
 * Every request naming a Game_Id goes through this, before any move-validity or
 * lifecycle check (Req 3.9). It reads; it never writes.
 *
 * | Session holds token for id | Game row | Expiry_Record | Result |
 * | --- | --- | --- | --- |
 * | yes | yes, hash matches | – | acting player resolved; full representation |
 * | yes | yes, hash does not match | – | `not_authorised` (403) |
 * | yes | no | yes | `game_expired` (410) — Req 13.6 |
 * | yes | no | no | `not_recognised` (404) — Req 13.8 |
 * | no | yes | – | `not_authorised` (403) — Req 3.10, 9.6 |
 * | no | no | yes | `not_recognised` (404) |
 * | no | no | no | `not_recognised` (404) — Req 13.8 |
 *
 * THE ASYMMETRY BETWEEN ROWS 3 AND 6 IS THE POINT OF THE TABLE. They are the
 * *same database state* — no Game row, a tombstone present — and they answer
 * differently, on the single question of whether this session holds a token for
 * the id. The tombstone tells the player who was there that their Game is gone
 * (Req 13.6) and tells everyone else nothing (Req 13.8), so it never becomes an
 * oracle for which Game_Ids ever existed. The migration that creates
 * `expiry_records` says the same thing from the other side: nothing in that DDL
 * enforces this, and nothing there could, because a bare SELECT reveals every
 * row. It is enforced here or not at all.
 *
 * ROWS 6 AND 7 ARE ONE BRANCH WITH ONE `return`, not two branches that happen to
 * return the same case. That is deliberate and worth keeping: it means a
 * tokenless caller cannot separate "is or was a Game" from "never was" by the
 * value, by the number of queries issued, or by how long the answer took, because
 * there is no second code path to separate. A later edit that gave row 6 its own
 * answer would have to add a branch, which is a visible change rather than an
 * accidental one.
 *
 * `not_authorised` IS ONE VALUE FOR ALL THREE FAILURE MODES of Requirement 9.6 —
 * no token, unrecognised token, token bound to another Game. Rows 2 and 5 both
 * reach it, and they reach the *same* `return`: modes one and three arrive as
 * `heldFor()` returning null or returning a value whose digest is on no slot of
 * this row, and `PlayerTokens::resolve()` answers null to all of them. There is
 * nothing here that could tell them apart even if a caller wanted to.
 *
 * WHAT THIS CLASS DOES NOT DO. It does not know what an HTTP status is, does not
 * render, and does not throw: it answers with a value. `ResolveActingPlayer`
 * translates a rejection into the 403/404/410 the transport uses, and task 5.6
 * renders it. It also performs no lifecycle check — `waiting_for_opponent` and
 * the terminal states resolve exactly as `active` does, because a Player of a
 * finished Game is still a Player of it (Req 9.2, 9.5), and because Requirement
 * 3.9 requires authorisation to be settled before anything else is looked at.
 */
final class GameResolver
{
    public function __construct(
        private readonly PlayerTokens $tokens,
    ) {}

    /**
     * The table above, evaluated for one Game_Id.
     *
     * TAKES A STRING, NOT A `Game`. Four of the seven rows are reached when no
     * `games` row exists, so there is no model to be passed; a `Game` parameter
     * would make the expired and unrecognised rows unreachable. It also means the
     * caller cannot have looked the Game up first — see `ResolveActingPlayer` for
     * why route-model binding must be kept away from these routes, which is the
     * same fact from the HTTP side.
     *
     * NO VALIDATION OF THE ID'S SHAPE. A caller may pass any string at all; one
     * that is not a UUID simply matches no row and no tombstone and falls to row
     * 7, which is what Requirement 13.8 asks for. A format check would be a
     * fourth answer for a case the table already covers.
     *
     * ORDER OF THE THREE READS, AND WHAT IS AND IS NOT CLAIMED ABOUT IT. The
     * session is consulted first because it costs no query and because every row
     * of the table needs it. The `games` row is second, and it settles rows 1, 2
     * and 5 outright. `expiry_records` is read LAST and only on the branch that
     * needs it — a token held for an id with no Game row — so rows 1, 2, 5, 6 and
     * 7 never touch that table at all. That mirrors the table's own structure:
     * the tombstone appears in exactly the two rows that consult it.
     *
     * Being honest about what that buys. It is not a defence against a timing
     * side channel, and it is not offered as one: the pairs of rows that must be
     * indistinguishable are indistinguishable because they share a `return`
     * statement, which is a stronger and simpler claim than one about query
     * counts. What the ordering buys is a Game_Id-shaped read pattern that
     * matches the table a reader is checking it against, plus one fewer query on
     * the polling path. In any case a Game_Id is not a credential — guessing one
     * correctly with no token reaches row 5 and yields `not_authorised`, since
     * authorisation comes from the Player_Token and nothing else — so there is no
     * secret here for a side channel to leak.
     */
    public function resolve(string $gameId): ResolvedPlayer|VisibilityOutcome
    {
        $presented = $this->tokens->heldFor($gameId);

        $game = Game::query()->whereKey($gameId)->first();

        // Rows 1 and 2. The Move_List is deliberately not loaded: authorisation
        // is a question about two columns of this row.
        if ($game !== null) {
            $mark = $this->tokens->resolve($game, $presented);

            return $mark === null
                ? VisibilityOutcome::NotAuthorised
                : new ResolvedPlayer($game, $mark);
        }

        // Rows 6 and 7, together, before `expiry_records` is looked at — which is
        // what makes the tombstone invisible to a caller holding no token rather
        // than merely unreported to one.
        if ($presented === null) {
            return VisibilityOutcome::NotRecognised;
        }

        // Rows 3 and 4. `exists()` rather than a fetch: the tombstone's
        // `deleted_at` is not disclosed to anyone (Req 13.6 asks only for the
        // outcome), so there is nothing to read off the row.
        return ExpiryRecord::query()->whereKey($gameId)->exists()
            ? VisibilityOutcome::GameExpired
            : VisibilityOutcome::NotRecognised;
    }
}
