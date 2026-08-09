import { Head, usePage } from '@inertiajs/react';
import Board from '@/components/Board';
import Layout from '@/components/Layout';
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

            <Layout>
                <h1 className="text-3xl font-semibold tracking-tight">Your game</h1>

                {/*
                 * DO NOT wrap this paragraph in a `header`, `div` or `section`, and do not
                 * change its text. `PlayAGameTest` reads it as `main > p:has(span)` and
                 * asserts the exact string 'You are playing x.', so its position as a DIRECT
                 * child of the layout's `main` is part of the contract — a wrapper makes the
                 * selector match nothing and the failure reads as a closed browser rather
                 * than a moved element. Learned by breaking it.
                 *
                 * That also rules out putting the mark in a decorative chip beside the
                 * heading: the glyph would land in the paragraph's text content and the
                 * assertion compares the whole string. Colour and weight on the letter is the
                 * emphasis that fits inside the constraint.
                 */}
                <p className="text-sm text-ink-muted">
                    You are playing{' '}
                    <span
                        className={`font-mono text-lg font-bold ${game.yourMark === 'x' ? 'text-mark-x' : 'text-mark-o'}`}
                    >
                        {game.yourMark}
                    </span>
                    .
                </p>

                <OutcomeMessage outcome={outcome} />

                <StatusBanner game={game} opponentIdle={opponentIdle} />

                <Board game={game} />

                <RematchControl game={game} />

                {game.state === 'waiting_for_opponent' && game.joinCode !== null && game.joinUrl !== null && (
                    <JoinCodePanel joinCode={game.joinCode} joinUrl={game.joinUrl} />
                )}
            </Layout>
        </>
    );
}
