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
 * handled — 500, a `game.invariant_violation` record carrying the Game_Id, and
 * no state change.
 *
 * IT IS DELIBERATELY NOT AN `invalid_move` OUTCOME. Mapping it there would
 * report a corrupt database row to the player as "that cell is not available",
 * hide a defect behind an ordinary rejection, and leave the corruption in place
 * to be met again on the next request.
 *
 * A dedicated type rather than a bare `RuntimeException` so the log record can
 * be keyed on the class and can carry the Game_Id it names, without a caller
 * having to match on a message string.
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
