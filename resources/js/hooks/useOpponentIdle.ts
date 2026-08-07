import { useEffect, useState } from 'react';
import type { GameProps } from '@/pages/Game';

/*
 * The opponent-idle indication: whether to tell the viewing Player that their
 * opponent may have stopped playing (Req 9.4). The design's "Opponent-idle
 * indication" block carries the rationale; what follows is only what the code cannot
 * tell you.
 *
 * NO SERVER INVOLVEMENT, AND NONE IS NEEDED. `lastMoveAt` is already a prop of
 * `GameRepresentation`, so the threshold is arithmetic on state the page holds. The
 * tick exists only because the answer changes with the passage of time rather than
 * with an arriving prop: a page left alone crosses 60 seconds without any visit, and
 * without a timer React would have no reason to re-render.
 *
 * THE THREE CONDITIONS ARE CONJOINED, AND THE `isYourTurn` ONE IS LOAD-BEARING RATHER
 * THAN DECORATIVE. An earlier draft of Requirement 9.4 asked only for "no Move
 * accepted for 60 seconds", which is vacuously true of an empty Board — so the
 * Creator was told their opponent may have stopped playing while the Application was
 * in fact waiting on the Creator's own first Move (`docs/ai-direction.md`, "An idle
 * indication that fired on your own turn"). The criterion now names the
 * Mark_To_Move, and `game.isYourTurn` is that clause. Removing it reintroduces the
 * defect.
 *
 * REQUIREMENT 9.3 IS NOT THIS HOOK'S BUSINESS. The waiting-for-opponent indication is
 * the `active` and not-your-turn branch of `StatusBanner`, which needs no clock; this
 * hook decides only the additional line Requirement 9.4 asks for, which that branch
 * shows on top of it. So "quiet" here means the banner still says it is waiting on
 * the opponent, not that it says nothing.
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
 * `lastMoveAt` IS NULL WHEN THE MOVE_LIST IS EMPTY (`GameRepresentation::lastMoveAtOf`)
 * AND THAT CASE IS QUIET. There is no Move to measure 60 seconds from, and the only
 * other origin available — when the Game became `active` — is not a prop, so the
 * elapsed time is not merely unknown but unrepresentable here. Treating a null as an
 * elapsed eternity would announce "your opponent may have stopped playing" to the
 * Joiner the instant the page opened, before the Creator had had any opportunity at
 * all to move: the same vacuous indication recorded in `docs/ai-direction.md`, moved
 * to the other Player's screen. Quiet is the honest answer, and Requirement 9.3's
 * waiting indication still shows.
 *
 * An unparseable `lastMoveAt` yields NaN, and every comparison against NaN is false,
 * so a malformed timestamp falls to the quiet side too rather than needing a guard of
 * its own.
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

        // Cleared on unmount, so no tick — and therefore no state update on an
        // unmounted component — can follow it. The dependency array is empty because
        // nothing about the interval depends on the props: re-arming it on every prop
        // change would reset the countdown before it elapsed, which is the same hazard
        // `useGamePolling` records for its own effect.
        return () => clearInterval(ticker);
    }, []);

    return opponentIsIdle(game, now);
}
