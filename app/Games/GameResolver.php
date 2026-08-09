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
 * Rows 3 and 6 are the same database state — no Game row, tombstone present —
 * and answer differently on the single question of whether this session holds a
 * token for the id. The tombstone tells the player who was there that their Game
 * is gone (Req 13.6) and tells everyone else nothing (Req 13.8), so it never
 * becomes an oracle for which Game_Ids ever existed. Nothing in the
 * `expiry_records` DDL enforces that and nothing there could, since a bare SELECT
 * reveals every row: it is enforced here or not at all.
 *
 * Rows 6 and 7 share one `return`, not two branches returning the same case, so a
 * tokenless caller cannot separate "is or was a Game" from "never was" by value,
 * query count or timing. Giving row 6 its own answer would require adding a
 * branch — a visible change rather than an accidental one.
 *
 * `not_authorised` is one value for all three failure modes of Requirement 9.6 —
 * no token, unrecognised token, token bound to another Game — because
 * `PlayerTokens::resolve()` answers null to all of them. Rows 2 and 5 reach the
 * same `return`.
 *
 * Does not render and does not throw; `ResolveActingPlayer` translates a rejection
 * into 403/404/410. It also performs no lifecycle check: `waiting_for_opponent` and the terminal states
 * resolve exactly as `active` does, because a Player of a finished Game is still a
 * Player of it (Req 9.2, 9.5) and authorisation is settled first (Req 3.9).
 */
final class GameResolver
{
    public function __construct(
        private readonly PlayerTokens $tokens,
    ) {}

    /**
     * The table above, evaluated for one Game_Id.
     *
     * Takes a string, not a `Game`: four of the seven rows are reached when no
     * `games` row exists, so a `Game` parameter would make the expired and
     * unrecognised rows unreachable. It also means the caller cannot have looked
     * the Game up first — see `ResolveActingPlayer` for why route-model binding
     * stays away from these routes.
     *
     * The id's shape is not validated. A non-UUID string matches no row and no
     * tombstone and falls to row 7, which is what Requirement 13.8 asks for; a
     * format check would be a fourth answer for a case the table covers.
     *
     * `expiry_records` is read last and only on the branch that needs it, so rows
     * 1, 2, 5, 6 and 7 never touch that table. That is a read pattern matching the
     * table and one fewer query on the polling path, not a timing defence — the
     * rows that must be indistinguishable are so because they share a `return`.
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

        // Rows 6 and 7, together, before `expiry_records` is looked at — which
        // makes the tombstone invisible to a tokenless caller rather than merely
        // unreported to one.
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
