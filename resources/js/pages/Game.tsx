import { Head, usePage } from '@inertiajs/react';
import Board from '@/components/Board';
import JoinCodePanel from '@/components/JoinCodePanel';
import OutcomeMessage from '@/components/OutcomeMessage';
import StatusBanner from '@/components/StatusBanner';
import useGamePolling from '@/hooks/useGamePolling';
import useOpponentIdle from '@/hooks/useOpponentIdle';

/*
 * `GET /games/{game}` — the game page.
 *
 * IT RENDERS FROM PROPS AND HOLDS NO LOCAL STATE AT ALL. The board is
 * `props.game.board`, the banner is derived from `state`, `isYourTurn`,
 * `markToMove` and `winningMark`, the winning highlight is the flattened
 * `winningLines`, and the refusal message is the shared `outcome` prop. There is no
 * client-side copy of the board and no optimistic placement: a Move is a POST, the
 * server answers 303, and the following GET brings back the board the server
 * derived. That is what makes the two players' screens agree, and it is why a
 * conflict (Req 5.5) needs no reconciliation here — the response after the redirect
 * *is* the current state.
 *
 * `usePage` FOR `outcome`, PROPS FOR THE GAME. `outcome` is the one prop
 * `HandleInertiaRequests` shares, so it is not in this page's own props; `Join.tsx`
 * reads it the same way.
 *
 * THE BOARD IS RENDERED IN EVERY STATE, INCLUDING `waiting_for_opponent`. The
 * disabled condition in `Board.tsx` covers all three inert cases — waiting, the
 * opponent's turn, and a finished Game — so there is nothing to gate here, and a
 * grid that appears and disappears would move the join panel and the banner under a
 * player's cursor as the Game starts. While waiting, the Creator sees an empty
 * inert board beside the code to send.
 *
 * `opponentIdle` COMES FROM `useOpponentIdle` AND NOTHING HERE COMPUTES IT.
 * Requirement 9.3's waiting-for-opponent indication is already rendered — it is the
 * `active` and not-your-turn branch of `StatusBanner`, which needs no clock — and
 * Requirement 9.4's "may have stopped playing" is the branch this flag selects. The
 * 60-second decision, and the timer that makes it change without a visit, live in the
 * hook. The rematch control (task 7.2) is absent rather than approximated.
 *
 * `useGamePolling` IS CALLED FOR ITS EFFECT AND ITS RETURN IS IGNORED. It reads the
 * game prop, chooses the interval and stops itself when a Rematch appears (Req 8.1,
 * 8.5, 8.6); the mode it returns is there to be asserted in a test, not to be rendered.
 * Nothing about the loop is visible here, which is the point — the page still renders
 * only from props, and a poll is just another visit delivering new ones.
 *
 * `GameProps` IS THE WHOLE SHAPE `GameRepresentation` PRODUCES, and it is exported
 * because it is the client's half of that contract — `Board.tsx` and
 * `StatusBanner.tsx` import it rather than redeclaring the fields they read. It is
 * a type-only import in both, so nothing at runtime imports a page from a
 * component.
 */

export type GameProps = {
    id: string;
    state: 'waiting_for_opponent' | 'active' | 'won' | 'drawn';
    version: number;
    board: ('x' | 'o' | null)[];
    moves: { cell: number; sequence: number; mark: 'x' | 'o' }[];
    markToMove: 'x' | 'o';
    yourMark: 'x' | 'o';
    isYourTurn: boolean;
    winningMark: 'x' | 'o' | null;
    winningLines: number[][];
    joinCode: string | null;
    joinUrl: string | null;
    rematchGameId: string | null;
    lastMoveAt: string | null;
};

export default function Game({ game }: { game: GameProps }) {
    const { outcome } = usePage<{ outcome: string | null }>().props;

    useGamePolling(game);

    const opponentIdle = useOpponentIdle(game);

    return (
        <>
            <Head title="Your game" />

            <main className="mx-auto flex min-h-screen max-w-xl flex-col justify-center gap-6 p-6">
                <h1 className="text-3xl font-semibold">Your game</h1>

                <p className="text-sm text-gray-600">
                    You are playing <span className="font-mono uppercase">{game.yourMark}</span>.
                </p>

                <OutcomeMessage outcome={outcome} />

                <StatusBanner game={game} opponentIdle={opponentIdle} />

                <Board game={game} />

                {game.state === 'waiting_for_opponent' && game.joinCode !== null && game.joinUrl !== null && (
                    <JoinCodePanel joinCode={game.joinCode} joinUrl={game.joinUrl} />
                )}
            </main>
        </>
    );
}
