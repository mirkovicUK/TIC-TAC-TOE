<?php

declare(strict_types=1);

namespace App\Games;

use App\Domain\TicTacToe\Mark;
use App\Domain\TicTacToe\Outcome;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The sole writer of the six Game lifecycle log records (Req 10.3–10.5): one
 * method per event, emitted as one JSON line on the `game_events` channel.
 *
 * `gameInvariantViolation()` is a seventh method on the same channel and is NOT one
 * of the six: no requirement asks for it, only the design's error-handling table
 * does, because it reports corruption rather than a Game lifecycle event. Its own
 * docblock says so; do not fold it into a count of Requirement 10.3's events.
 *
 * Every method takes typed arguments and there is no array, no `...$context` and
 * no public `emit()`, so there is no key-value bag for a secret to be dropped
 * into. `MintedToken`, `PlayerTokens` and `JoinCode` are objects with no
 * `__toString()`, so none of them satisfies a `string`, `int`, `Mark`,
 * `MoveOutcome` or `Outcome` parameter: passing one is a `TypeError` at the call
 * site rather than a Requirement 10.5 violation in production. The single `mixed`
 * parameter — `moveRejected()`'s cell index, which must accept whatever the
 * request carried (Req 4.4) — logs any object as its `get_debug_type()` name and
 * never its properties, so it is not a way in either. What remains possible is
 * reading a secret out of one of those types and passing the string deliberately;
 * `App\Logging\RedactSecrets` is the second line of defence against that, and the
 * limit is the same one `MintedToken`'s docblock records.
 *
 * `outcome`, `result` and `winning_mark` are backing values of existing enums
 * rather than strings written here, so a record cannot name an outcome the
 * application cannot produce. `game.finished` takes one `Outcome` and derives both
 * `result` and `winning_mark` from it, which is why the pair cannot disagree — a
 * `drawn` result carries `winning_mark: null`, the field being present and empty
 * rather than absent.
 *
 * `emit()` swallows every `Throwable`: a broken log channel must not fail a Game
 * action (Req 10.3 describes an emission, not a precondition). The cost is that a
 * misconfigured channel loses records silently, which is accepted — the sole
 * alternative is failing a Move because a stream could not be opened.
 *
 * Records are emitted from straight-line code after the write they describe has
 * committed, never from inside a `DB::transaction()` closure. That is what makes
 * "exactly one record per event" hold under retry and rollback: a closure may run
 * more than once, and a committed record cannot be rolled back with the Move it
 * would then be describing.
 */
final class GameEventLogger
{
    /**
     * The channel `config/logging.php` defines: `JsonFormatter` to `stderr`, with
     * the redaction processor attached. Named here rather than taken from
     * `LOG_CHANNEL`, because these records must not follow the default channel to
     * a file the container's logs never show.
     */
    public const string CHANNEL = 'game_events';

    /**
     * The `outcome` of an accepted Move. Not a `MoveOutcome` case: that enum is
     * the five ways `SubmitMove` refuses a Move, and adding an `Accepted` case
     * would widen every rejection return type in `App\Games` to include it.
     */
    private const string ACCEPTED = 'accepted';

    /**
     * Bytes of JSON kept for a non-integer cell index. Ten times the longest
     * legitimate value (`8`) is already generous for diagnosing a malformed
     * payload, and the cap is what stops a megabyte of request body reaching the
     * log stream.
     */
    private const int CELL_INDEX_LIMIT = 64;

    /** Req 10.3: emitted by `CreateGame` once the insert has committed. */
    public function gameCreated(string $gameId): void
    {
        $this->emit('game.created', $gameId);
    }

    /**
     * Req 10.3: emitted by `JoinGame` only on the path whose guarded UPDATE
     * claimed the O slot. The session short-circuit of Requirements 2.4 and 2.5
     * joins nobody, and `game_full` claims nothing, so neither emits.
     */
    public function gameJoined(string $gameId): void
    {
        $this->emit('game.joined', $gameId);
    }

    /** Req 10.4: the acting Mark, the Cell, the Sequence_Index and the outcome. */
    public function moveAccepted(string $gameId, Mark $mark, int $cellIndex, int $sequenceIndex): void
    {
        $this->emit('move.accepted', $gameId, [
            'mark' => $mark->value,
            'cell_index' => $cellIndex,
            'sequence_index' => $sequenceIndex,
            'outcome' => self::ACCEPTED,
        ]);
    }

    /**
     * Req 10.4 for a refused Move. The same four move fields as an acceptance, so
     * one record shape covers both and the `outcome` alone distinguishes them.
     *
     * `$mark` is the Mark bound to the presented Player_Token, not the
     * Mark_To_Move, which is what Requirement 10.4's "acting Mark" names and the
     * only Mark `SubmitMove` holds (Req 3.2). `$sequenceIndex` is the position the
     * Move would have taken — the length of the observed Move_List — so it is
     * defined for every rejection, including those refused before any Move was
     * derived.
     *
     * @param  mixed  $cellIndex  As the request carried it. `mixed` and not a
     *                            narrower union on purpose: this is called from
     *                            `SubmitMove`'s `invalid_move` path, where the
     *                            value is by definition arbitrary, and a type
     *                            error inside a logging call would fail the very
     *                            request the record exists to explain.
     */
    public function moveRejected(string $gameId, Mark $mark, mixed $cellIndex, int $sequenceIndex, MoveOutcome $outcome): void
    {
        $this->emit('move.rejected', $gameId, [
            'mark' => $mark->value,
            'cell_index' => is_int($cellIndex) ? $cellIndex : $this->describeCellIndex($cellIndex),
            'sequence_index' => $sequenceIndex,
            'outcome' => $outcome->value,
        ]);
    }

    /**
     * Req 10.3: the Terminal_State transition, emitted in addition to the
     * `move.accepted` record of the Move that caused it, so a winning Move
     * produces two records describing two events rather than one merged record.
     */
    public function gameFinished(string $gameId, Outcome $result): void
    {
        $this->emit('game.finished', $gameId, [
            'result' => $result->value,
            'winning_mark' => $result->winner()?->value,
        ]);
    }

    /**
     * Req 10.3. `$gameId` is the preceding Game — the row Requirement 7.5
     * increments and the one a Player is polling — and `$rematchGameId` the Game
     * just created, matching `GameRepresentation`'s `rematchGameId`.
     */
    public function rematchCreated(string $gameId, string $rematchGameId): void
    {
        $this->emit('rematch.created', $gameId, ['rematch_game_id' => $rematchGameId]);
    }

    /**
     * A persisted Move_List the Rules_Engine rejected: the design's error-handling
     * table for `InvalidMoveList::Error` — "500, log `game.invariant_violation` with
     * the Game_Id, no state change".
     *
     * Not one of the six events Requirement 10.3 enumerates, and no requirement asks
     * for it: it reports corruption, not a Game lifecycle event. The design is its
     * only source.
     *
     * The Game_Id and nothing else, because that is all `CorruptMoveListException`
     * carries and all the design's row asks for. The stack trace naming the corrupt
     * row is on the default channel's own record of the same exception, which
     * `bootstrap/app.php` deliberately leaves in place.
     *
     * Called from the `report` hook in `bootstrap/app.php`, never from
     * `SubmitMove::commit()`, where the exception is thrown inside a transaction so
     * that the insert rolls back with it.
     */
    public function gameInvariantViolation(string $gameId): void
    {
        $this->emit('game.invariant_violation', $gameId);
    }

    /**
     * The one write, and the only place the three fields Requirement 10.4 demands
     * of every record are assembled.
     *
     * `timestamp` is written explicitly even though Monolog stamps every record
     * with `datetime`: the design tabulates a `timestamp` field, and `datetime`'s
     * presence and format belong to the formatter rather than to this record.
     * Formatted as `GameRepresentation::lastMoveAt()` formats its instant.
     *
     * @param  array<string, int|string|null>  $fields  The event's own fields, which
     *                                                  cannot displace the three
     *                                                  above them.
     */
    private function emit(string $event, string $gameId, array $fields = []): void
    {
        try {
            Log::channel(self::CHANNEL)->info($event, [
                'event' => $event,
                'game_id' => $gameId,
                'timestamp' => now()->utc()->toIso8601ZuluString(),
                ...$fields,
            ]);
        } catch (Throwable) {
            // Deliberately silent. See the class docblock: a Game action must not
            // fail because a log record could not be written, and reporting the
            // failure through the same logging stack is what has just failed.
        }
    }

    /**
     * A cell index that was not an integer, as JSON, truncated to
     * `CELL_INDEX_LIMIT` bytes.
     *
     * Cannot throw and cannot corrupt the line. `JSON_THROW_ON_ERROR` is not
     * passed and `JSON_INVALID_UTF8_SUBSTITUTE` replaces malformed bytes, so a
     * string of arbitrary bytes encodes rather than failing. `JSON_UNESCAPED_UNICODE`
     * is deliberately NOT passed: without it the result is pure ASCII, so `substr`
     * cannot split a multi-byte character and leave broken UTF-8 for the formatter
     * to choke on.
     *
     * Anything that is not a scalar or null — an array, an object, a resource — is
     * reported as its type name and never serialised. That is what keeps a
     * secret-bearing object out of a record on the one parameter typed `mixed`;
     * `'{"raw":"..."}'` in a `cell_index` field is exactly the disclosure
     * Requirement 10.5 forbids, and no depth of nesting can produce it here.
     */
    private function describeCellIndex(mixed $cellIndex): string
    {
        if (! is_scalar($cellIndex) && $cellIndex !== null) {
            return get_debug_type($cellIndex);
        }

        $encoded = json_encode($cellIndex, JSON_INVALID_UTF8_SUBSTITUTE);

        // `false` for a float NAN or INF, the only scalars JSON cannot carry.
        if (! is_string($encoded)) {
            return get_debug_type($cellIndex);
        }

        return strlen($encoded) > self::CELL_INDEX_LIMIT
            ? substr($encoded, 0, self::CELL_INDEX_LIMIT).'...'
            : $encoded;
    }
}
