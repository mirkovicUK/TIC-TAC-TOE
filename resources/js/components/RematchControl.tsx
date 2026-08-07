import { router } from '@inertiajs/react';
import type { GameProps } from '@/pages/Game';

/*
 * The subsequent-game control: one button, two labels, one endpoint (Req 7.1, 7.13).
 *
 * IT IS A POST TO `/games/{preceding}/rematch` IN BOTH STATES, AND THE SECOND STATE IS
 * WHERE THAT LOOKS WRONG AND IS NOT. Once `rematchGameId` is present the Rematch has a
 * URL, and turning this button into `<Link href={`/games/${game.rematchGameId}`}>` is
 * the obvious tidy-up. DO NOT. Under ADR-010 a Rematch is created with both token slots
 * NULL and each Player_Token is minted by the POST, at the moment that Player's session
 * presents its token for the *preceding* Game. So the player who did not click first
 * holds no token for the Rematch, a GET of the Rematch's URL is refused by
 * `acting.player` with `not_authorised`, and the tidy-up sends exactly one of the two
 * players to a "you are not a player in this game" page for a Game that is theirs. The
 * POST is what mints the credential; there is nothing to link to until it has run.
 *
 * The endpoint is idempotent for the same reason (Req 7.9, 7.15): `CreateRematch` creates
 * the Rematch or returns the existing one, so the two labels differ only in what they
 * promise the player, never in what they do. That is also why a double click needs no
 * guard here — both requests converge on the one Rematch — and why `game.id`, the
 * PRECEDING Game's id, is the path parameter in both states. `game.rematchGameId` is
 * read only to choose the label, and must never become part of the URL.
 *
 * RENDERED ONLY IN A TERMINAL STATE, AND THE GATE IS HERE RATHER THAN IN `Game.tsx`.
 * Requirement 7.1 conditions the control on the Game being terminal, and a Rematch
 * requested for a Game that is not is `invalid_state` (Req 7.10) — so offering the
 * button while `waiting_for_opponent` or `active` would offer an action the server is
 * required to refuse. `StatusBanner` is the precedent: a component handed the whole game
 * prop that decides per state what it has to say, so the state rule sits with the
 * reasoning for it instead of being split across a caller's `&&`.
 *
 * THE CONTROL IS DECIDED BY THE PROPS THE PAGE ALREADY HAS, WHICH IS WHAT MAKES IT
 * SURVIVE `useGamePolling` STOPPING. That hook stops the loop the moment
 * `rematchGameId` is non-null (Req 8.6) — but the poll that *delivers* the Rematch is
 * the poll that renders it: the props arrive, this component switches to the second
 * label, and only then does the mode become `stopped`. Nothing further is needed from
 * the server, so a stopped loop leaves the player looking at a live control rather than
 * a stale page.
 *
 * `router.post` RATHER THAN `useForm`, for `Board.tsx`'s reason: there are no fields, so
 * a form's `data` would be an empty object and its `processing` flag would be the local
 * state `Game.tsx` deliberately does not keep. `router.post` goes through the same
 * Inertia request path and carries the CSRF token the same way (Req 10.9). The body is
 * empty because the endpoint reads nothing from it — the acting Mark comes from the
 * Player_Token in the session and the swap is derived (Req 7.3, 7.7).
 *
 * NO `preserveScroll`, UNLIKE `Board.tsx`, AND THAT IS THE DIFFERENCE BETWEEN THE TWO
 * ACTIONS. A Move answers 303 back to the same Game, so holding the scroll position
 * keeps the board under the cursor; this answers 303 to the *Rematch* — the one redirect
 * in the application that leaves the Game the request named — and carrying a scroll
 * offset onto a different page would land the player part-way down a fresh board.
 *
 * THE ANNOUNCEMENT IS A `role="status"` LINE THAT IS ALWAYS MOUNTED, WHICH IS
 * `JoinCodePanel`'s copied-confirmation pattern. A Rematch appears without the player
 * doing anything — the opponent clicked and a poll brought it back — so it is a change
 * worth announcing, and `status` is the polite register: it should not interrupt, which
 * is `OutcomeMessage`'s `alert`. A live region announces *changes* after mount and not
 * its initial content, so the empty span while no Rematch exists is what makes the
 * appearance a change rather than nothing; rendering the line conditionally would mount
 * a populated region and announce nothing at all. The wording is neutral about who
 * started it, because a player who started the Rematch and then navigated back to this
 * Game also sees the second state.
 */

type RematchControlProps = {
    game: GameProps;
};

export default function RematchControl({ game }: RematchControlProps) {
    if (game.state !== 'won' && game.state !== 'drawn') {
        return null;
    }

    const exists = game.rematchGameId !== null;

    // The PRECEDING Game's id in both states. See the docblock: `rematchGameId` chooses
    // the label and never the URL.
    const start = () => {
        router.post(`/games/${game.id}/rematch`);
    };

    return (
        <div className="flex flex-col gap-2 rounded border border-gray-200 p-4">
            <p className="text-sm text-gray-600">
                {exists
                    ? 'A rematch of this game is ready. You keep the same opponent and swap marks.'
                    : 'Play the same opponent again. Marks swap, so whoever played X plays O.'}
            </p>

            <button
                type="button"
                onClick={start}
                className="w-fit rounded bg-indigo-600 px-4 py-2 font-medium text-white hover:bg-indigo-500"
            >
                {exists ? 'Go to the rematch' : 'Play again'}
            </button>

            <span role="status" className="text-sm text-green-700">
                {exists ? 'A rematch is ready.' : ''}
            </span>
        </div>
    );
}
