<?php

declare(strict_types=1);

namespace App\Games;

use App\Domain\TicTacToe\Outcome;

/**
 * The persisted lifecycle value of a Game: exactly one of four (Req 6.1), matching
 * the CHECK constraint on `games.state` case for case.
 *
 * This is the boundary between the domain layer and the Game_Service, and stays a
 * separate type from `App\Domain\TicTacToe\Outcome` for two reasons. First,
 * `waiting_for_opponent` is not a domain concept: the engine sees only an ordered
 * list of Moves, so it cannot tell "no opponent has joined" from "an opponent has
 * joined and nobody has moved" — both are the empty list — which is why Game_State
 * is persisted rather than derived on read like the Board and the Mark_To_Move.
 * Second, `fromOutcome()` is lossy the other way: `WonByX` and `WonByO` both map to
 * `won`, with the winning Mark carried in `games.winning_mark`.
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
     * `waiting_for_opponent` is unreachable here by design, not by omission: every
     * `Outcome` describes a Move_List, and a Game waiting for an opponent has
     * accepted no Move for this method to be called about. A Game leaves that state
     * through `JoinGame`, which sets `active` directly, and never re-enters it.
     *
     * The match is exhaustive over `Outcome`, so adding a case there is an error
     * here rather than a silent fall-through.
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
     * A Terminal_State is `won` or `drawn`. Named consumers: `SubmitMove`'s
     * game-ended guard (Req 4.6), `CreateRematch`'s `invalid_state` rejection
     * (Req 7.10), and `GameRepresentation`.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::WaitingForOpponent, self::Active => false,
            self::Won, self::Drawn => true,
        };
    }
}
