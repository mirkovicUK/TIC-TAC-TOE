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
 * DERIVED VERSUS PERSISTED, AND THE SPLIT IS EXACT. `board`, `markToMove`,
 * `winningLines` and the terminal result come from the `Analysis` the Rules_Engine
 * derived from the persisted Move_List; `state`, `version`, `winningMark` and
 * `rematchGameId` come from the `games` row. That is Property 11 restated as a
 * construction rule, and it is what makes the property falsifiable: if this class
 * read `winningMark` from `Analysis::winner()` instead of from the column, the
 * property's claim that "the persisted `winning_mark` equals the derived winner"
 * would be true by construction and would test nothing.
 *
 * SO THE TWO SOURCES ARE LEFT HONEST, AND NOTHING HERE RECONCILES THEM. A row
 * whose `winning_mark` disagreed with the derived winner would serialise the
 * disagreement rather than hide it — no `??`, no assert, no "prefer the derived
 * one". The disagreement is impossible by two independent mechanisms (`SubmitMove`
 * writes the column only from `Analysis::winner()`, and the CHECK on `games` pairs
 * a non-null `winning_mark` with `state = 'won'`), it is asserted by task 12.4,
 * and a defensive fallback here would convert a corrupted row from a loud test
 * failure into a board that quietly renders the wrong winner.
 *
 * `markToMove` IS TOTAL, INCLUDING IN A TERMINAL STATE. Requirement 4.1 states it
 * unconditionally and `Analysis` carries it unconditionally, so it is emitted
 * unconditionally: on a board X won at Sequence_Index 4, `markToMove` is `O` and
 * `isYourTurn` is true for the O Player. That is not a defect to be patched here.
 * `Board.tsx`'s disabled condition is `!isYourTurn || state !== 'active'`, and the
 * second half is what keeps a finished board inert; nulling `markToMove` in a
 * Terminal_State would move a UI decision into the serialiser and take the
 * `active`-state display of Requirement 6.7 with it.
 *
 * NO TOKEN VALUE APPEARS ANYWHERE IN THE OUTPUT (Req 8.7). Neither
 * `x_token_hash` nor `o_token_hash` is read, so neither can be emitted — not even
 * as a digest. `yourMark` is the only thing the token contributes, it arrives as a
 * `Mark` parameter, and it comes from `PlayerTokens::resolve()` by way of
 * `ResolvedPlayer` and from nothing else — never from a request payload (Req 3.2,
 * 3.6).
 *
 * THE PROP IS OMITTED FOR A FAILED `GameResolver`, AND THAT IS STRUCTURAL RATHER
 * THAN A FLAG HERE (Req 3.10). Every `GameResolver` rejection is a bare
 * `VisibilityOutcome` case carrying no fields, so a refused request holds no Game
 * to build a `GameSnapshot` from and therefore cannot reach this class at all.
 * There is deliberately no nullable return and no "should I include it" parameter:
 * the omission is a consequence of the rejection type, and a parameter would make
 * it a decision this class could get wrong.
 *
 * THERE IS NO CONDITIONAL-REQUEST PATH (Req 8.4, ADR-002). `of()` takes no client
 * Version_Counter, has no early return and no notion of "unchanged", so a 304 is
 * not expressible in its signature. Every state request gets the whole
 * representation, whatever version the client holds; the Version_Counter is still
 * sent on every response (Req 8.3) as a change detector for the client and as the
 * contract for Property 12.
 *
 * WHAT IS DELIBERATELY NOT HERE. No HTTP status, no `Inertia::render`, no prop
 * name: this returns an array and task 5.6 decides what to call it and how to send
 * it. Nothing in `App\Games` knows what a response is.
 */
final class GameRepresentation
{
    /** `board` is always this long, and `WinningLine::cells()` always this wide. */
    private const int CELLS = 9;

    /**
     * The `game` prop for `$observed`, as seen by the Player holding `$yourMark`.
     *
     * STATIC, AND WITH NO CONSTRUCTOR, because there is nothing to inject: this is
     * a pure function of its two arguments plus the two relationships of the row
     * they name. `JoinGame` and `CreateGame` take a `PlayerTokens` because they
     * mint credentials; there is no equivalent collaborator here, and a
     * container-bound instance would be ceremony around a function. It also means
     * no test and no controller can substitute a fake serialiser for the real one,
     * which is the right way round for the class the design calls "exactly one
     * place where a board becomes JSON".
     *
     * TAKES A `GameSnapshot`, NOT A `Game`. The Move_List and its `Analysis` are
     * already derived together in the snapshot, and its private constructor is what
     * guarantees the `Analysis` belongs to the Move_List it was derived from — so
     * `board` and `moves` below cannot describe different boards. A `Game`
     * parameter would make this class re-derive them and would put a second
     * `RulesEngine::analyse()` call site on the polling path, where `SubmitMove`'s
     * caller has already built exactly the snapshot this needs.
     *
     * QUERIES. Two, both on the row's relationships, and both avoidable by a caller
     * that eager-loads:
     *
     *   - `rematch` — `rematch_of_game_id` lives on the *rematch* row pointing back
     *     at this one (unique index, Req 7.8), so "this Game's rematch" is a lookup
     *     by that column and not an attribute of the row in hand. It is read
     *     through the `HasOne`, so `$game->load('rematch')` or `with('rematch')`
     *     makes it free and `relationLoaded()` is honoured automatically.
     *   - the last Move's `created_at`, for `lastMoveAt`. This is a *second* read of
     *     `moves` after `GameSnapshot::of()`'s, and it is kept rather than threaded
     *     through the snapshot because the snapshot's contract is the *domain*
     *     Move_List — nine cell indices and their sequence indices — and a
     *     persistence timestamp is not part of it. It is one row, ordered by the
     *     unique index on `(game_id, sequence_index)`.
     *
     * Both are on the polling path, which is two requests every two seconds per
     * Game (Req 8.1), and both are single-row reads served by an index. Stated
     * plainly rather than buried: a poll costs three queries in `App\Games` — the
     * Move_List read, the rematch lookup and the last-Move read — and a caller that
     * wants two of them gone can `->load(['rematch', 'moves'])`, though only the
     * rematch is actually honoured from a loaded relation today.
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

        // Read through `occupantOf()` for every index in 0..8 rather than handing
        // `Board::cells()` to `array_values()`. Two reasons: the result is a
        // nine-entry list by construction, so `json_encode` cannot emit an object
        // instead of an array for a sparse or reordered cell map, and the enums go
        // out as their backing values (`'x'`, `'o'`) rather than as PHP enum
        // objects — the client's contract is a string, and a `Mark` here would
        // serialise as `{"value":"x"}` through `JsonSerializable` or not at all.
        $board = [];

        for ($cellIndex = 0; $cellIndex < self::CELLS; $cellIndex++) {
            $board[] = $analysis->board->occupantOf($cellIndex)?->value;
        }

        // `mark` has no column behind it and must not acquire one: it is the parity
        // of `sequence_index` (Req 11.4), derived by the domain `Move`. The `moves`
        // table deliberately has no `mark` column, because the unique index on
        // `(game_id, sequence_index)` already fixes parity for every row and a
        // stored mark could only agree with it or corrupt it.
        $moves = [];

        foreach ($observed->moveList as $move) {
            $moves[] = [
                'cell' => $move->cellIndex,
                'sequence' => $move->sequenceIndex,
                'mark' => $move->mark()->value,
            ];
        }

        // Only while `waiting_for_opponent` — there is nothing to join afterwards,
        // and a code still on screen for an `active` Game invites a third party to
        // type it. The gate is the persisted Game_State and not the Move_List,
        // because "waiting" is precisely the fact the Move_List cannot express.
        $code = $game->state === GameState::WaitingForOpponent && $game->join_code !== null
            ? JoinCode::parse($game->join_code)
            : null;

        return [
            'id' => $game->id,
            // From the ROW. Backing values, not enum objects: `state` is the
            // four-value union the client switches on.
            'state' => $game->state->value,
            'version' => $game->version_counter,
            // From the ANALYSIS.
            'board' => $board,
            'moves' => $moves,
            'markToMove' => $analysis->markToMove->value,
            // From the TOKEN.
            'yourMark' => $yourMark->value,
            // `markToMove === yourMark` and nothing more — no state check, no
            // terminal special case. Requirement 6.7's "whether that Mark belongs
            // to the viewing Player" is exactly this comparison, and the client
            // combines it with `state` where that matters.
            'isYourTurn' => $analysis->markToMove === $yourMark,
            // From the ROW, deliberately: see the class docblock on why this is not
            // read from `Analysis::winner()` and not reconciled against it.
            'winningMark' => $game->winning_mark?->value,
            // From the ANALYSIS, and EVERY completed line rather than the first
            // (Req 6.3). A double win is reachable in legal play, so this is a list
            // of lines and each line is its three cell indices — which is what
            // Requirement 6.5's highlight flattens.
            'winningLines' => array_map(
                static fn (WinningLine $line): array => $line->cells(),
                $analysis->winningLines,
            ),
            // The hyphenated display form, `XXXXX-XXXXX`. The column holds the
            // unhyphenated ten characters, so `JoinCode::parse()` is the one
            // conversion and its inverse `display()` sits ten lines from it.
            'joinCode' => $code?->display(),
            'joinUrl' => $code === null ? null : self::joinUrlFor($code),
            // From the ROW's back-reference. Present whenever a rematch exists
            // (Req 7.12), null otherwise.
            'rematchGameId' => $game->rematch?->id,
            'lastMoveAt' => self::lastMoveAtOf($game),
        ];
    }

    /**
     * The Join_Link: an absolute URL a Player can paste, opening the application at
     * the join action with the code prefilled (Req 1.6).
     *
     * BUILT FROM THE NAMED ROUTE, WHICH IT COULD NOT BE WHEN THIS CLASS WAS
     * WRITTEN. Task 5.5 came before task 5.6, so `GET /join/{join_code?}` did not
     * exist and `route('join', ...)` would have thrown `RouteNotFoundException` on
     * every waiting Game — turning the first prop this class ever produced into a
     * 500. It was therefore built as `url('/join/'.rawurlencode($code->display()))`
     * until 5.6 registered the route at exactly that path under exactly that name,
     * and swapped it here. The two forms were verified to produce the identical
     * string before the swap, and `GameRepresentationTest` still asserts the URL
     * against `url('/join/10ABC-DEFGH')` — the *path*, not the route name — which
     * is what would catch this route being renamed to something that resolves to a
     * different path. A Join_Link that a player has already pasted into a message
     * does not follow a route rename, so the path is the thing the test pins.
     *
     * Rejected then and still rejected: registering the route from inside
     * `App\Games`, which would put HTTP wiring in a namespace that knows nothing
     * about HTTP; and emitting a relative path, which would break Requirement 1.6's
     * "a URL", since a Join_Link is pasted into another browser where a path means
     * nothing.
     *
     * `route()` resolves against the current request's root, falling back to
     * `APP_URL`, so the host is the one the player is actually on, and it encodes
     * the parameter itself — a no-op for Crockford base32 and the hyphen, all of
     * which are unreserved in a path segment, but no longer this method's business
     * if `JoinCode::ALPHABET` ever changes.
     */
    private static function joinUrlFor(JoinCode $code): string
    {
        return route('join', $code->display());
    }

    /**
     * When the most recent Move of `$game` was recorded, as ISO 8601 in UTC with a
     * `Z` designator — `2026-08-07T13:14:00Z` — or null when the Move_List is
     * empty.
     *
     * UTC AND EXPLICITLY ZULU, because the client does arithmetic with it:
     * `useOpponentIdle` compares `now - lastMoveAt` against 60 seconds (Req 9.4),
     * and `Date.parse` of a string with no zone designator is implementation-defined
     * for date-time forms — historically local time in some engines. `Z` removes
     * the question. `->utc()` is applied before formatting rather than assumed,
     * because the column's timezone is the application's rather than necessarily
     * UTC.
     *
     * NOT `games.last_activity_at`, which is the nearby column that would look
     * right and be wrong: that one is also set at creation and moved by an accepted
     * join, so a Game with no Moves at all would report a `lastMoveAt`, and the idle
     * indication would start its clock from the join instead of from a Move.
     *
     * Ordered by `sequence_index` descending rather than by `created_at`, so the
     * read is served by the unique index on `(game_id, sequence_index)` and so the
     * answer is the *last* Move rather than the latest-stamped one. The two agree —
     * Moves are appended — and the index makes the ordering free.
     */
    private static function lastMoveAtOf(Game $game): ?string
    {
        $last = $game->moves()->orderByDesc('sequence_index')->first();

        return $last?->created_at->utc()->toIso8601ZuluString();
    }
}
