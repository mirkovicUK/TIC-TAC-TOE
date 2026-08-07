<?php

declare(strict_types=1);

namespace App\Games;

use App\Domain\TicTacToe\Outcome;

/**
 * The persisted lifecycle value of a Game: exactly one of four (Req 6.1),
 * matching the CHECK constraint on `games.state` case for case.
 *
 * THIS ENUM IS THE BOUNDARY between the domain layer and the Game_Service, and
 * that is the whole reason it exists as a second type alongside
 * `App\Domain\TicTacToe\Outcome` rather than being folded into it.
 *
 * The two are deliberately not one type, for two independent reasons.
 *
 * FIRST, `waiting_for_opponent` is not derivable from a Move_List. The engine
 * sees an ordered list of Moves and nothing else, so it cannot tell "no opponent
 * has joined yet" from "an opponent has joined and nobody has moved yet" — both
 * are the empty list. That distinction is about players, which is a service
 * concern, and it is precisely why Game_State is persisted at all rather than
 * derived on read like the Board and the Mark_To_Move. Adding the case to
 * `Outcome` would hand the engine a value it can never produce and could never
 * be asked to classify.
 *
 * SECOND, the mapping below is lossy in the other direction. `WonByX` and
 * `WonByO` both collapse to `won`, because which Mark won is carried separately
 * in `games.winning_mark`; a single enum would have to choose between the
 * engine's four-way answer and the schema's four-way lifecycle, and they are not
 * the same four.
 */
enum GameState: string
{
    case WaitingForOpponent = 'waiting_for_opponent';
    case Active = 'active';
    case Won = 'won';
    case Drawn = 'drawn';

    /**
     * The Game_State a Game takes after an accepted Move, from what the engine
     * derived. Used by `SubmitMove` in the move transaction.
     *
     * `waiting_for_opponent` is NOT REACHABLE from here, and that is the point
     * of the method rather than a gap in it: every `Outcome` describes a
     * Move_List, and a Game that is waiting for an opponent has accepted no Move
     * for this method to be called about. A Game leaves that state through
     * `JoinGame`, which sets `active` directly, and never re-enters it.
     *
     * The match is exhaustive over `Outcome`, so adding a case there is a
     * compile-stage error here rather than a silent fall-through.
     */
    public static function fromOutcome(Outcome $outcome): self
    {
        return match ($outcome) {
            Outcome::InProgress => self::Active,
            Outcome::WonByX, Outcome::WonByO => self::Won,
            Outcome::Drawn => self::Drawn,
        };
    }

    /**
     * A Terminal_State is `won` or `drawn`.
     *
     * Named consumers: `SubmitMove`'s game-ended guard (Req 4.6),
     * `CreateRematch`'s `invalid_state` rejection of a preceding Game still in
     * play (Req 7.10), and `GameRepresentation`.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::WaitingForOpponent, self::Active => false,
            self::Won, self::Drawn => true,
        };
    }
}
