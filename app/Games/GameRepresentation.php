<?php

declare(strict_types=1);

namespace App\Games;

use App\Domain\TicTacToe\Mark;
use App\Domain\TicTacToe\WinningLine;
use App\Models\Game;

/**
 * The one serialiser producing `props.game`: the only place in the application
 * where a Board becomes JSON (Req 6.3, 6.7, 7.12, 8.3, 8.4, 8.7).
 *
 * The derived/persisted split is exact. `board`, `markToMove`, `winningLines` and
 * the terminal result come from the `Analysis` derived from the persisted
 * Move_List; `state`, `version`, `winningMark` and `rematchGameId` come from the
 * `games` row. Property 11 rests on that split: reading `winningMark` from
 * `Analysis::winner()` would make "the persisted `winning_mark` equals the derived
 * winner" true by construction and test nothing.
 *
 * Nothing here reconciles the two sources. A row whose `winning_mark` disagreed
 * with the derived winner serialises the disagreement — no `??`, no assert, no
 * "prefer the derived one". The disagreement is already impossible (`SubmitMove`
 * writes the column only from `Analysis::winner()`, and the CHECK on `games` pairs
 * a non-null `winning_mark` with `state = 'won'`), so a fallback here would turn a
 * loud test failure into a board that quietly renders the wrong winner.
 *
 * `markToMove` is total, including in a Terminal_State (Req 4.1): on a board X
 * won, `markToMove` is `O` and `isYourTurn` is true for the O Player. Do not null
 * it there — `Board.tsx` keeps a finished board inert with `!isYourTurn || state
 * !== 'active'`, and nulling it would move a UI decision into the serialiser and
 * take Requirement 6.7's `active`-state display with it.
 *
 * No token value appears in the output (Req 8.7): neither hash column is read.
 * `yourMark` arrives as a `Mark` from `PlayerTokens::resolve()` by way of
 * `ResolvedPlayer`, never from a request payload (Req 3.2, 3.6).
 *
 * The prop's absence for a refused request is structural (Req 3.10): every
 * `GameResolver` rejection is a fieldless `VisibilityOutcome` case, so a refused
 * request holds no Game to build a `GameSnapshot` from and cannot reach this class.
 * There is no conditional-request path either (Req 8.4) — every state
 * request gets the whole representation, with the Version_Counter sent every time
 * (Req 8.3) as the client's change detector.
 *
 * No HTTP status, prop name or `Inertia::render` here; task 5.6 owns those.
 */
final class GameRepresentation
{
    /** `board` is always this long, and `WinningLine::cells()` always this wide. */
    private const int CELLS = 9;

    /**
     * The `game` prop for `$observed`, as seen by the Player holding `$yourMark`.
     *
     * Takes a `GameSnapshot`, not a `Game`: the snapshot's private constructor
     * guarantees its `Analysis` was derived from the Move_List it carries, so
     * `board` and `moves` cannot describe different boards, and the polling path
     * gains no second `RulesEngine::analyse()` call site.
     *
     * Two queries, both single-row reads on the polling path (Req 8.1). `rematch`
     * is a back-reference — `rematch_of_game_id` lives on the *rematch* row
     * (unique index, Req 7.8) — read through the `HasOne`, so `with('rematch')`
     * makes it free. `lastMoveAt` is a *second* read of `moves` after
     * `GameSnapshot::of()`'s, kept separate because a persistence timestamp is not
     * part of the snapshot's domain Move_List.
     *
     * @param  Mark  $yourMark  The Mark bound to the Player_Token in the requesting
     *                          session, from `ResolvedPlayer` and nothing else.
     * @return array{
     *     id: string,
     *     state: string,
     *     version: int,
     *     board: list<string|null>,
     *     moves: list<array{cell: int, sequence: int, mark: string}>,
     *     markToMove: string,
     *     yourMark: string,
     *     isYourTurn: bool,
     *     winningMark: string|null,
     *     winningLines: list<array{int, int, int}>,
     *     joinCode: string|null,
     *     joinUrl: string|null,
     *     rematchGameId: string|null,
     *     lastMoveAt: string|null,
     * }
     */
    public static function of(GameSnapshot $observed, Mark $yourMark): array
    {
        $game = $observed->game;
        $analysis = $observed->analysis;

        // `occupantOf()` per index rather than `array_values(Board::cells())`: the
        // result is a nine-entry list by construction, so `json_encode` cannot emit
        // an object for a sparse or reordered cell map, and the enums go out as
        // their backing values, which is the client's contract.
        $board = [];

        for ($cellIndex = 0; $cellIndex < self::CELLS; $cellIndex++) {
            $board[] = $analysis->board->occupantOf($cellIndex)?->value;
        }

        // `mark` has no column behind it and must not acquire one: it is the parity
        // of `sequence_index` (Req 11.4), derived by the domain `Move`. The unique
        // index on `(game_id, sequence_index)` already fixes parity for every row,
        // so a stored mark could only agree with it or corrupt it.
        $moves = [];

        foreach ($observed->moveList as $move) {
            $moves[] = [
                'cell' => $move->cellIndex,
                'sequence' => $move->sequenceIndex,
                'mark' => $move->mark()->value,
            ];
        }

        // Only while `waiting_for_opponent`: a code on screen for an `active` Game
        // invites a third party to type it. The gate is the persisted Game_State,
        // because "waiting" is the one fact the Move_List cannot express.
        $code = $game->state === GameState::WaitingForOpponent && $game->join_code !== null
            ? JoinCode::parse($game->join_code)
            : null;

        return [
            'id' => $game->id,
            // From the row. Backing value, not the enum: `state` is the four-value
            // union the client switches on.
            'state' => $game->state->value,
            'version' => $game->version_counter,
            // From the analysis.
            'board' => $board,
            'moves' => $moves,
            'markToMove' => $analysis->markToMove->value,
            // From the token.
            'yourMark' => $yourMark->value,
            // Requirement 6.7's "whether that Mark belongs to the viewing Player"
            // is exactly this comparison — no state check, no terminal special
            // case. The client combines it with `state` where that matters.
            'isYourTurn' => $analysis->markToMove === $yourMark,
            // From the row, deliberately: see the class docblock on why this is not
            // read from `Analysis::winner()` and not reconciled against it.
            'winningMark' => $game->winning_mark?->value,
            // Every completed line rather than the first (Req 6.3): a double win is
            // reachable in legal play, and each line is its three cell indices,
            // which is what Requirement 6.5's highlight flattens.
            'winningLines' => array_map(
                static fn (WinningLine $line): array => $line->cells(),
                $analysis->winningLines,
            ),
            // The hyphenated display form, `XXXXX-XXXXX`; the column holds the
            // unhyphenated ten characters.
            'joinCode' => $code?->display(),
            'joinUrl' => $code === null ? null : self::joinUrlFor($code),
            // From the row's back-reference. Present whenever a rematch exists
            // (Req 7.12), null otherwise.
            'rematchGameId' => $game->rematch?->id,
            'lastMoveAt' => self::lastMoveAtOf($game),
        ];
    }

    /**
     * The Join_Link: an absolute URL a Player can paste, opening the application at
     * the join action with the code prefilled (Req 1.6). Absolute, not relative — a
     * Join_Link is pasted into another browser, where a path means nothing.
     *
     * `GameRepresentationTest` pins the *path* (`url('/join/10ABC-DEFGH')`), not the
     * route name, because a link already pasted into a message does not follow a
     * route rename. Renaming `join` to a route that resolves elsewhere is the edit
     * that test catches.
     *
     * `route()` resolves against the current request's root, falling back to
     * `APP_URL`, and encodes the parameter itself — a no-op for Crockford base32 and
     * the hyphen, but no longer this method's business if `JoinCode::ALPHABET`
     * changes.
     */
    private static function joinUrlFor(JoinCode $code): string
    {
        return route('join', $code->display());
    }

    /**
     * When the most recent Move of `$game` was recorded, as ISO 8601 in UTC with a
     * `Z` designator — `2026-08-07T13:14:00Z` — or null when the Move_List is empty.
     *
     * Explicitly Zulu because the client does arithmetic with it: `useOpponentIdle`
     * compares `now - lastMoveAt` against 60 seconds (Req 9.4), and `Date.parse` of
     * a date-time with no zone designator is implementation-defined. `->utc()` is
     * applied rather than assumed, because the column's timezone is the
     * application's.
     *
     * Not `games.last_activity_at`, the nearby column that would look right and be
     * wrong: it is also set at creation and moved by an accepted join, so a Game
     * with no Moves would report a `lastMoveAt` and the idle clock would start from
     * the join.
     *
     * Ordered by `sequence_index` descending, not `created_at`, so the read is
     * served by the unique index on `(game_id, sequence_index)` and the answer is
     * the *last* Move rather than the latest-stamped one.
     */
    private static function lastMoveAtOf(Game $game): ?string
    {
        $last = $game->moves()->orderByDesc('sequence_index')->first();

        return $last?->created_at->utc()->toIso8601ZuluString();
    }
}
