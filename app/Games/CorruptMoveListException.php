<?php

declare(strict_types=1);

namespace App\Games;

use RuntimeException;

/**
 * The persisted Move_List of a Game was rejected by the Rules_Engine.
 *
 * Unreachable by Requirement 11.6: every Move that ever reaches the `moves`
 * table went through `SubmitMove`, which validates against an already
 * well-formed list and appends at `count($observed->moveList)`. So this is data
 * corruption, not a user error, and the design's failure table says how it is
 * handled — 500, no state change, and a `game.invariant_violation` record carrying
 * the Game_Id, emitted by the `report` hook in `bootstrap/app.php` through
 * `GameEventLogger::gameInvariantViolation()`.
 *
 * It is deliberately not an `invalid_move` outcome. Mapping it there would report a
 * corrupt database row to the player as "that cell is not available", hide a defect
 * behind an ordinary rejection, and leave the corruption in place to be met again on
 * the next request.
 *
 * A dedicated type rather than a bare `RuntimeException`, and both halves of that
 * are load-bearing: the report hook selects on the parameter type, and the record's
 * Game_Id is read off `$gameId`, so neither depends on matching a message string.
 */
final class CorruptMoveListException extends RuntimeException
{
    public function __construct(public readonly string $gameId)
    {
        parent::__construct(sprintf(
            'The persisted Move_List of game %s was rejected by the Rules_Engine.',
            $gameId,
        ));
    }
}
