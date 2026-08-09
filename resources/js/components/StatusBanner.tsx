import type { GameProps } from '@/pages/Game';

/*
 * One shared shell for all four branches, so the banner occupies the same height and
 * position whichever state the game is in. Without it the board jumped a line whenever the
 * turn copy changed length, which under a 2-second poll is a board that twitches.
 */
const shell = 'rounded-2xl border border-hairline/60 bg-panel px-4 py-3 shadow-[0_1px_0_0_rgba(255,255,255,0.06)_inset]';

const mark = (m: 'x' | 'o') =>
    `font-mono text-xl font-bold uppercase ${m === 'x' ? 'text-mark-x' : 'text-mark-o'}`;

/*
 * What the board currently means, in words: whose turn it is, whether that is you,
 * whether we are waiting on the opponent, and how the Game ended.
 *
 * `role="status"` rather than `role="alert"`: the polling loop replaces this text when the
 * opponent moves, and a status region is announced without interrupting the player. The
 * other register belongs to `OutcomeMessage`, which announces a refusal of something the
 * player just did.
 *
 * The turn copy is shown only while `active` (Req 6.7). `markToMove` is the parity of the
 * Move_List length and stays defined in a Terminal_State, so rendering it unconditionally
 * would tell a player it was their turn on a game they had just lost — the same fact
 * `Board.tsx`'s second half enforces.
 *
 * `waiting_for_opponent` renders nothing here because Requirement 1.7's waiting indication
 * is `JoinCodePanel`'s heading, where it sits with the Join_Code. A second line above it
 * would be duplicate copy and, in a live region, a duplicate announcement.
 *
 * Requirement 9.3 is the `active` and not-your-turn branch; Requirement 9.4 is the
 * `opponentIdle` prop, which `useOpponentIdle` decides.
 *
 * The `won`-with-no-`winningMark` branch is unreachable — a CHECK on `games` pairs a
 * non-null `winning_mark` with `state = 'won'` — but has to exist because the column is
 * nullable. What it must not do is fall back to `markToMove`, which in a won game names
 * the player who did *not* win.
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
                <div role="status" className={`${shell} flex flex-col gap-1`}>
                    <p className="flex items-center gap-2 text-lg">
                        <span className={mark(game.markToMove)}>{game.markToMove}</span>
                        {game.isYourTurn ? ' to move — that is you.' : ' to move — waiting for your opponent.'}
                        {game.isYourTurn && (
                            <span
                                aria-hidden="true"
                                className="ml-auto size-2 shrink-0 animate-pulse rounded-full bg-accent"
                            />
                        )}
                    </p>
                    {! game.isYourTurn && opponentIdle && (
                        <p className="text-notice">
                            Your opponent has not moved for a while and may have stopped playing.
                        </p>
                    )}
                </div>
            );

        case 'won':
            return (
                <div role="status" className={`${shell} flex flex-col gap-1`}>
                    <p className="text-xl font-semibold">
                        {game.winningMark === null
                            ? 'This game has been won.'
                            : `${game.winningMark.toUpperCase()} won this game.`}
                    </p>
                    {game.winningMark !== null && (
                        <p className="text-ink-muted">
                            {game.winningMark === game.yourMark ? 'You won.' : 'Your opponent won.'}
                        </p>
                    )}
                </div>
            );

        case 'drawn':
            return (
                <div role="status" className={shell}>
                    <p className="text-xl font-semibold">This game ended in a draw.</p>
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
