import type { GameProps } from '@/pages/Game';

/*
 * What the board currently means, in words: whose turn it is, whether that is you,
 * whether we are waiting on the opponent, and how the Game ended.
 *
 * `role="status"` RATHER THAN `role="alert"`. The polling loop replaces this text
 * when the opponent moves, and a status region is announced without interrupting
 * what the player is doing, which is the right register for "O to move". The
 * refusal of something the player just did is the other register and belongs to
 * `OutcomeMessage`.
 *
 * THE TURN COPY IS SHOWN ONLY WHILE `active` (Req 6.7). `markToMove` is defined in
 * a Terminal_State as well — it is the parity of the Move_List length and nothing
 * else — so rendering it unconditionally would tell a player it was their turn on a
 * game they had just lost. The switch below is what keeps it to the one state the
 * criterion names, and it is the same fact `Board.tsx`'s second half enforces.
 *
 * `waiting_for_opponent` RENDERS NOTHING HERE, AND THAT IS NOT AN OMISSION.
 * Requirement 1.7's waiting indication is `JoinCodePanel`'s heading, because there
 * it sits with the Join_Code the player has to send — the same fact stated once. A
 * second "waiting for a second player" line directly above it would be duplicate
 * copy and, in a live region, a duplicate announcement.
 *
 * REQUIREMENT 9.3 IS THE `active` AND NOT-YOUR-TURN BRANCH; REQUIREMENT 9.4 IS THE
 * `opponentIdle` PROP. The 60-second decision is a clock, and a clock is a hook:
 * `useOpponentIdle` (task 6.5) owns the timer and hands the answer down as a
 * boolean. This component takes the signal rather than computing it, so the
 * rendering can be read — and the "waiting" versus "may have stopped playing" split
 * seen — without a timer anywhere near it.
 *
 * THE `won`-WITH-NO-`winningMark` BRANCH IS UNREACHABLE AND IS STILL NOT RECONCILED.
 * A CHECK on `games` pairs a non-null `winning_mark` with `state = 'won'`, and
 * `SubmitMove` writes the column only from `Analysis::winner()`. It is typed
 * nullable because the column is, so the branch has to exist; what it must not do is
 * fall back to `markToMove`, which in a won game names the player who did *not* win.
 * Neutral copy is the honest answer to a row that should not exist.
 */

type StatusBannerProps = {
    game: GameProps;
    opponentIdle: boolean;
};

export default function StatusBanner({ game, opponentIdle }: StatusBannerProps) {
    switch (game.state) {
        case 'waiting_for_opponent':
            return null;

        case 'active':
            return (
                <div role="status" className="flex flex-col gap-1">
                    <p className="text-lg">
                        <span className="font-mono uppercase">{game.markToMove}</span>
                        {game.isYourTurn ? ' to move — that is you.' : ' to move — waiting for your opponent.'}
                    </p>
                    {! game.isYourTurn && opponentIdle && (
                        <p className="text-amber-800">
                            Your opponent has not moved for a while and may have stopped playing.
                        </p>
                    )}
                </div>
            );

        case 'won':
            return (
                <div role="status" className="flex flex-col gap-1">
                    <p className="text-lg font-medium">
                        {game.winningMark === null
                            ? 'This game has been won.'
                            : `${game.winningMark.toUpperCase()} won this game.`}
                    </p>
                    {game.winningMark !== null && (
                        <p className="text-gray-700">
                            {game.winningMark === game.yourMark ? 'You won.' : 'Your opponent won.'}
                        </p>
                    )}
                </div>
            );

        case 'drawn':
            return (
                <div role="status">
                    <p className="text-lg font-medium">This game ended in a draw.</p>
                </div>
            );

        default: {
            // A fifth Game_State would be a compile error here rather than a blank
            // banner: `game.state` narrows to `never` once the four are handled.
            const unhandled: never = game.state;

            throw new Error(`Unhandled game state: ${String(unhandled)}`);
        }
    }
}
