import { Head } from '@inertiajs/react';
import JoinCodePanel from '@/components/JoinCodePanel';

/*
 * `GET /games/{game}` — the game page.
 *
 * THIS IS THE MINIMAL FORM OF THIS PAGE, AND TASK 6.3 IS WHAT FINISHES IT. What is
 * here is what task 5.6 owns: the route renders, `props.game` arrives from
 * `GameRepresentation`, and the waiting state shows the Join_Code and a copyable
 * Join_Link (Req 1.6, 1.7) through `JoinCodePanel`.
 *
 * DELIBERATELY LEFT TO TASK 6.3, and not approximated here: `Board.tsx` and
 * `Cell.tsx` (with the disabled condition `!isYourTurn || state !== 'active'` and the
 * per-cell `aria-label`), `StatusBanner.tsx`, `OutcomeMessage.tsx` with
 * `lib/outcomes.ts`, and the two hooks — `useGamePolling` (task 6.4) and
 * `useOpponentIdle` (task 6.5). A board rendered here without the disabled condition
 * would be the exact defect the design writes two paragraphs about, so none is
 * rendered: the state is stated in words until the board arrives with the rules that
 * govern clicking it.
 *
 * `GameProps` IS THE WHOLE SHAPE `GameRepresentation` PRODUCES, including the fields
 * this page does not yet read. It is declared in full and exported because it is the
 * client's half of that contract, and task 6.3's components take it apart rather than
 * redeclaring pieces of it.
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
    return (
        <>
            <Head title="Your game" />

            <main className="mx-auto flex min-h-screen max-w-xl flex-col justify-center gap-6 p-6">
                <h1 className="text-3xl font-semibold">Your game</h1>

                <p className="text-sm text-gray-600">
                    You are playing <span className="font-mono uppercase">{game.yourMark}</span>.
                </p>

                {game.state === 'waiting_for_opponent' && game.joinCode !== null && game.joinUrl !== null && (
                    <JoinCodePanel joinCode={game.joinCode} joinUrl={game.joinUrl} />
                )}

                {game.state !== 'waiting_for_opponent' && (
                    <p className="text-gray-700">Both players are here. The board arrives with the next task.</p>
                )}
            </main>
        </>
    );
}
