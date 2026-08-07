import { usePoll } from '@inertiajs/react';
import { useEffect } from 'react';
import type { GameProps } from '@/pages/Game';

/*
 * The polling loop: how `Game.tsx` learns about the opponent's Move without being
 * refreshed (Req 8.1, 8.2, 8.5, 8.6, ADR-001).
 *
 * ONE DECISION, THREE VALUES. Everything this hook does follows from `pollModeFor`:
 * `live` at 2000 ms while `waiting_for_opponent` or `active`, `terminal` at 5000 ms
 * once the Game is `won` or `drawn` with no Rematch, and `stopped` the moment a
 * Rematch is discovered. The decision is exported and pure so that it can be read and
 * asserted without a running browser; the hook below is only the plumbing that makes
 * the chosen mode the one that is running.
 *
 * TWO `usePoll` CALLS, AND NOT ONE WITH A CHANGING INTERVAL. This is the part that
 * looks like ceremony and is not. `usePoll`'s effect has an EMPTY dependency array —
 * `node_modules/@inertiajs/react/dist/index.esm.js`, `usePoll`, the
 * `useEffect(..., [])` around `router.poll(interval, ...)` — and `Poll` in
 * `@inertiajs/core` stores the interval on construction and arms
 * `setInterval(..., this.interval)` in `start()`, with no path that changes it
 * afterwards. So passing a computed `interval` to a single `usePoll` fixes it at the
 * value it had on FIRST RENDER: a Game mounted while `active` would keep polling every
 * 2000 ms after it was won, and a Game opened at a board that had already finished
 * would poll every 5000 ms if the players started a Rematch. Both intervals satisfy
 * their own criterion and neither satisfies the other, so the switch has to be real.
 * Declaring both polls and running exactly one of them is what expresses it through the
 * documented API rather than around it.
 *
 * THE INITIAL MODE IS ARMED BY `autoStart` AND THE TRANSITIONS BY `start`/`stop`, which
 * is two mechanisms for one decision and is deliberate. `usePoll` captures its options
 * in that same mount-only effect, so `autoStart` is read once — which makes it exactly
 * the right place to express "which poll should be running when this page opens", and
 * useless for anything after that. The effect below then owns the transitions. Doing it
 * this way rather than starting both from the effect means the first poll is armed by
 * the library on the library's own timing, so the loop does not depend on this hook's
 * `useEffect` being queued after `usePoll`'s own — which it is, effects running in
 * declaration order, but a silent no-poll is the one failure here that no rendering
 * would reveal.
 *
 * THE EFFECT IS KEYED ON THE MODE, WHICH IS WHY IT IS A STRING AND NOT THE INTERVAL
 * NUMBER OR THE POLL OBJECT. `start()` calls `stop()` first and re-arms the timer, so
 * an effect that ran on every render would reset the countdown before it ever elapsed
 * and the Game would never poll at all. Keyed on `mode`, it runs on mount — where it is
 * a no-op in substance, re-arming the poll `autoStart` has just armed, before any tick
 * has elapsed — and then on the two transitions that matter: live → terminal when the
 * Game ends, and → stopped when a Rematch appears. The cleanup stops the outgoing poll
 * before the incoming one starts. The `usePoll` return objects are freshly allocated
 * each render, so they are deliberately NOT dependencies; their `start`/`stop` close
 * over the hook's own ref, which is stable, so a captured one from an earlier render
 * still controls the right poll.
 *
 * NOTHING CLEANS UP ON UNMOUNT BEYOND THAT, ON PURPOSE. `usePoll` returns
 * `() => pollRef.current?.destroy()` from its own effect, and `destroy()` in
 * `@inertiajs/core`'s `Polls` stops the poll and removes it from the registry. That is
 * the "navigates away" half of Requirement 8.6, and it is the library's job rather than
 * this hook's — an extra unmount cleanup here could only duplicate it.
 *
 * `only: ['game']` IS A PARTIAL RELOAD, AND IT IS ALSO WHAT PROTECTS THE FLASHED
 * OUTCOME. `ShowGameController` renders one prop named `game`, so the poll response
 * carries the representation and nothing else. `HandleInertiaRequests` shares `outcome`
 * as a CLOSURE precisely so that this request does not evaluate it: Inertia's
 * `Response::resolveProperties()` filters props against the `only` header
 * (`resolvePartialProperties`) BEFORE invoking any closure (`resolvePropertyInstances`),
 * so a poll cannot consume a flashed outcome that the page has not rendered yet.
 * Widening `only` to include `outcome` would break that.
 *
 * `keepAlive` IS NOT PASSED, so it stays at the library default of `false`. Note what
 * that default actually does, because it is less than "a hidden tab does not poll":
 * `Poll.isInBackground()` sets a `throttle` flag on `visibilitychange`, and `tick()`
 * then fires only every tenth tick while hidden — one request every 20 s in `live` mode
 * and every 50 s in `terminal` mode, rather than none. That is still the right default
 * here (a hidden tab has no viewer to serve, and a forgotten one costs a fiftieth of
 * the rate budget), but it is a throttle and not a stop, and Requirements 8.1 and 8.5
 * are written about a Player watching a board rather than a hidden tab.
 */

/** Req 8.1: `waiting_for_opponent` or `active`, inside the 2-second ceiling. */
export const LIVE_INTERVAL_MS = 2000;

/** Req 8.5: terminal with no Rematch, inside the 5-second ceiling. */
export const TERMINAL_INTERVAL_MS = 5000;

export type PollMode = 'live' | 'terminal' | 'stopped';

/**
 * Which poll, if any, should be running for `game`.
 *
 * The Rematch check comes FIRST and is unconditional: Requirement 8.6 stops the loop
 * when a Rematch is discovered, and `rematchGameId` is non-null exactly when the server
 * has one to offer (Req 7.12). It is checked ahead of the state test rather than folded
 * into the terminal branch so that a Rematch stops polling whatever `state` says.
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
 * The return value is the decision rather than a handle: there is no `stop()` for a
 * caller to call, because every reason to stop is a fact about the props and is decided
 * by `pollModeFor`. A caller that could stop the loop by hand would be a second place
 * the stop condition lived.
 */
export default function useGamePolling(game: GameProps): PollMode {
    const mode = pollModeFor(game);

    // `autoStart` is read on mount and never again, so these two expressions say
    // "which poll should be running when this page opens" and nothing more. Neither
    // call passes `keepAlive`: it stays at the library default.
    const live = usePoll(LIVE_INTERVAL_MS, { only: ['game'] }, { autoStart: mode === 'live' });
    const terminal = usePoll(TERMINAL_INTERVAL_MS, { only: ['game'] }, { autoStart: mode === 'terminal' });

    useEffect(() => {
        if (mode === 'stopped') {
            return;
        }

        const poll = mode === 'live' ? live : terminal;

        poll.start();

        return () => poll.stop();
        // `live` and `terminal` are deliberately absent from the dependencies: they
        // are new objects every render, and re-running this effect would re-arm the
        // timer before it had elapsed.
    }, [mode]);

    return mode;
}
