import { usePoll } from '@inertiajs/react';
import { useEffect } from 'react';
import type { GameProps } from '@/pages/Game';

/*
 * The polling loop: how `Game.tsx` learns about the opponent's Move without being
 * refreshed (Req 8.1, 8.2, 8.5, 8.6). The design's "Polling lifecycle" block carries
 * the rationale; what follows is only what the code cannot tell you.
 *
 * Two `usePoll` calls rather than one with a computed interval. `usePoll`'s effect has an
 * empty dependency array (`node_modules/@inertiajs/react/dist/index.esm.js`, `usePoll`),
 * and `Poll` assigns `this.interval` in its constructor and arms
 * `setInterval(…, this.interval)` in `start()` with no path that reassigns it
 * (`@inertiajs/core`). A single call therefore pins the rate to first render, which puts a
 * page opened terminal and later live outside Requirement 8.1's ceiling. Do not
 * "simplify" this back into one call.
 *
 * The effect is keyed on the mode string, not on the poll objects. `start()` calls `stop()`
 * first and re-arms the timer, so an effect running every render would reset the countdown
 * before it elapsed and the Game would never poll at all — a silent total failure. The
 * `usePoll` return objects are new every render and so are deliberately absent from the
 * dependencies; their `start`/`stop` close over a stable ref.
 *
 * Unmount needs nothing here: `usePoll` returns `() => pollRef.current?.destroy()` from
 * its own effect, which is the "navigates away" half of Requirement 8.6.
 *
 * `only: ['game']` also protects the flashed outcome. Inertia's
 * `Response::resolveProperties()` filters against the `only` header before invoking any
 * closure, so a poll cannot consume an outcome the page has not rendered yet — see
 * `HandleInertiaRequests::share()`. Widening this would break that.
 *
 * `keepAlive` is not passed, so it stays at the library default — which throttles a hidden
 * tab rather than stopping it: `tick()` fires every tenth tick while `document.hidden`, one
 * request per 20 s live and per 50 s terminal.
 */

/** Req 8.1: `waiting_for_opponent` or `active`, inside the 2-second ceiling. */
export const LIVE_INTERVAL_MS = 2000;

/** Req 8.5: terminal with no Rematch, inside the 5-second ceiling. */
export const TERMINAL_INTERVAL_MS = 5000;

export type PollMode = 'live' | 'terminal' | 'stopped';

/**
 * Which poll, if any, should be running for `game`.
 *
 * Exported and pure so the decision can be asserted without a browser. The Rematch check
 * is unconditional, so a Rematch stops the loop whatever `state` says (Req 8.6).
 */
export function pollModeFor(game: GameProps): PollMode {
    if (game.rematchGameId !== null) {
        return 'stopped';
    }

    return game.state === 'won' || game.state === 'drawn' ? 'terminal' : 'live';
}

/**
 * Poll `GET /games/{id}` for `game`, and return the mode that is running.
 *
 * There is deliberately no `stop()` for a caller to call: every reason to stop is a fact
 * about the props, decided by `pollModeFor`.
 */
export default function useGamePolling(game: GameProps): PollMode {
    const mode = pollModeFor(game);

    // `autoStart` is read once, on mount; the effect below owns every transition after it.
    const live = usePoll(LIVE_INTERVAL_MS, { only: ['game'] }, { autoStart: mode === 'live' });
    const terminal = usePoll(TERMINAL_INTERVAL_MS, { only: ['game'] }, { autoStart: mode === 'terminal' });

    useEffect(() => {
        if (mode === 'stopped') {
            return;
        }

        const poll = mode === 'live' ? live : terminal;

        poll.start();

        return () => poll.stop();
    }, [mode]);

    return mode;
}
