import { useEffect, useState } from 'react';
import type { GameProps } from '@/pages/Game';

/*
 * The opponent-idle indication: whether to tell the viewing Player that their opponent
 * may have stopped playing (Req 9.4). The design's "Opponent-idle indication" block
 * carries the rationale; what follows is only what the code cannot tell you.
 *
 * The tick exists because the answer changes with the passage of time rather than with an
 * arriving prop: a page left alone crosses 60 seconds without any visit, so without a
 * timer React would have no reason to re-render.
 *
 * The `isYourTurn` clause is load-bearing. Without it the criterion is vacuously true of
 * an empty Board, and the Creator is told their opponent may have stopped playing while
 * the Application is waiting on the Creator's own first Move. Removing it reintroduces
 * that defect (`docs/ai-direction.md`, "An idle indication that fired on your own turn").
 *
 * Requirement 9.3's waiting-for-opponent indication is `StatusBanner`'s `active` and
 * not-your-turn branch, which needs no clock. So "quiet" here means the banner still says
 * it is waiting on the opponent, not that it says nothing.
 */

/** Req 9.4: "no Move has been accepted for at least 60 seconds". */
export const IDLE_THRESHOLD_MS = 60_000;

/** How often the answer is recomputed while the page sits still. */
export const IDLE_TICK_MS = 5000;

/**
 * Whether `game` should be reported as idle at `now`, in epoch milliseconds.
 *
 * Exported and pure so the threshold can be asserted without a browser and without a
 * fake clock — the caller supplies the clock.
 *
 * A null `lastMoveAt` — an empty Move_List, per `GameRepresentation::lastMoveAtOf` — is
 * quiet, and Requirement 9.4 was narrowed to an "at least one Move has been accepted"
 * clause to say so. There is no Move to measure from, and the only other origin (when the
 * Game became `active`) is not a prop. The accepted consequence — a Creator who never
 * returns leaves the Joiner unwarned — is a stated known limitation (Req 12.13).
 *
 * An unparseable `lastMoveAt` yields NaN, and every comparison against NaN is false, so a
 * malformed timestamp falls to the quiet side without a guard of its own.
 */
export function opponentIsIdle(game: GameProps, now: number): boolean {
    if (game.state !== 'active' || game.isYourTurn || game.lastMoveAt === null) {
        return false;
    }

    return now - Date.parse(game.lastMoveAt) >= IDLE_THRESHOLD_MS;
}

/**
 * Whether to show the "may have stopped playing" indication for `game`.
 *
 * The tick is a stored timestamp rather than a counter, and the first one is read
 * during the initial render, so a page opened on a Game that has been quiet for an
 * hour indicates immediately instead of waiting out a tick.
 */
export default function useOpponentIdle(game: GameProps): boolean {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        const ticker = setInterval(() => setNow(Date.now()), IDLE_TICK_MS);

        // The dependency array is empty because nothing about the interval depends on the
        // props: re-arming it on every prop change would reset the countdown before it
        // elapsed, which is the same hazard `useGamePolling` records for its own effect.
        return () => clearInterval(ticker);
    }, []);

    return opponentIsIdle(game, now);
}
