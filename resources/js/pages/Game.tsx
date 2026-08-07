import { Head, usePage } from '@inertiajs/react';
import Board from '@/components/Board';
import JoinCodePanel from '@/components/JoinCodePanel';
import OutcomeMessage from '@/components/OutcomeMessage';
import RematchControl from '@/components/RematchControl';
import StatusBanner from '@/components/StatusBanner';
import useGamePolling from '@/hooks/useGamePolling';
import useOpponentIdle from '@/hooks/useOpponentIdle';

/*
 * `GET /games/{game}` — the game page. It renders from props and holds no local state: a
 * Move is a POST, the server answers 303, and the following GET brings back the board the
 * server derived. That is why a conflict (Req 5.5) needs no reconciliation here.
 *
 * `outcome` comes from `usePage` because it is shared by `HandleInertiaRequests` rather
 * than being one of this page's own props; `Join.tsx` reads it the same way.
 *
 * The board is rendered in every state, including `waiting_for_opponent`: `Board.tsx`'s
 * disabled condition covers all three inert cases, and a grid that appeared and
 * disappeared would move the join panel and the banner under a player's cursor as the
 * Game starts. `StatusBanner` and `RematchControl` likewise gate themselves, so do not
 * add a state check around them here.
 *
 * `useGamePolling` is called for its effect; the mode it returns is there to be asserted
 * in a test, not rendered.
 *
 * `GameProps` is exported because it is the client's half of the `GameRepresentation`
 * contract — `Board.tsx` and `StatusBanner.tsx` import it type-only rather than
 * redeclaring the fields they read, so nothing at runtime imports a page from a component.
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

                <RematchControl game={game} />

                {game.state === 'waiting_for_opponent' && game.joinCode !== null && game.joinUrl !== null && (
                    <JoinCodePanel joinCode={game.joinCode} joinUrl={game.joinUrl} />
                )}
            </main>
        </>
    );
}
