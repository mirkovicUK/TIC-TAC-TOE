import { router } from '@inertiajs/react';
import Card from '@/components/Card';
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
        <Card className="flex flex-col gap-3">
            <p className="text-sm text-ink-muted">
                {exists
                    ? 'A rematch of this game is ready. You keep the same opponent and swap marks.'
                    : 'Play the same opponent again. Marks swap, so whoever played X plays O.'}
            </p>

            <button
                type="button"
                onClick={start}
                className="w-fit rounded-xl bg-accent px-4 py-2.5 font-semibold text-surface-deep shadow-[0_4px_0_0_var(--color-surface-deep)] transition hover:bg-accent-hover active:translate-y-0.5 active:shadow-[0_1px_0_0_var(--color-surface-deep)] focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-panel focus-visible:outline-none"
            >
                {exists ? 'Go to the rematch' : 'Play again'}
            </button>

            <span role="status" className="min-h-5 text-sm text-win">
                {exists ? 'A rematch is ready.' : ''}
            </span>
        </Card>
    );
}
