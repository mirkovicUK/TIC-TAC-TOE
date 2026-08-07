import { router } from '@inertiajs/react';
import type { GameProps } from '@/pages/Game';

/*
 * The subsequent-game control: one button, two labels, one endpoint (Req 7.1, 7.13).
 * It gates itself on a Terminal_State, because a Rematch of a Game still in play is
 * refused as `invalid_state` (Req 7.10).
 *
 * It stays a POST to `/games/{preceding}/rematch` after `rematchGameId` is known. DO NOT
 * turn that second state into `<Link href={`/games/${game.rematchGameId}`}>`. Under
 * ADR-010 a Rematch is created with both token slots NULL and each Player_Token is
 * minted by the POST, so the player who did not click first holds no token: a GET of the
 * Rematch's URL is refused with `not_authorised`, sending one of the two players to a
 * "you are not a player in this game" page for a Game that is theirs. The endpoint is
 * idempotent (Req 7.9, 7.15), so the two labels differ only in what they promise and a
 * double click needs no guard.
 *
 * The `role="status"` line is always mounted: a live region announces *changes* after
 * mount and not its initial content, so rendering it conditionally would announce
 * nothing. `status` rather than `OutcomeMessage`'s `alert`, because a Rematch arriving on
 * a poll should not interrupt.
 */

type RematchControlProps = {
    game: GameProps;
};

export default function RematchControl({ game }: RematchControlProps) {
    if (game.state !== 'won' && game.state !== 'drawn') {
        return null;
    }

    const exists = game.rematchGameId !== null;

    // The PRECEDING Game's id in both states; `rematchGameId` chooses the label and
    // never the URL.
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
