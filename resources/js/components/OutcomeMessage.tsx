import { outcomeMessage } from '@/lib/outcomes';

/*
 * The flashed outcome of a Player's refused action, rendered above the board.
 *
 * IT TAKES THE SHARED PROP, NOT THE GAME. `outcome` is the one prop
 * `HandleInertiaRequests` shares, set by every 303 that rejects an authorised
 * Player's action — `game_not_started`, `game_ended`, `not_your_turn`,
 * `invalid_move`, `conflict` (task 6.2) and `invalid_state` (task 7.1). The
 * GET that follows the redirect carries the outcome together with the current
 * Game_State, Move_List and Version_Counter, so the message and the board the
 * player is looking at are from the same response (Req 5.5).
 *
 * `role="alert"` RATHER THAN `role="status"`, and the distinction matters here:
 * this is the result of something the player just did, so it should interrupt;
 * `StatusBanner` describes the board and should not. It renders nothing at all
 * when there is no outcome, so a poll that leaves the flash empty removes the
 * message rather than leaving an empty live region behind.
 *
 * The vocabulary and the mapping are `lib/outcomes.ts`'s; this component is the
 * placement and the styling and nothing else.
 */

type OutcomeMessageProps = {
    outcome: string | null;
};

export default function OutcomeMessage({ outcome }: OutcomeMessageProps) {
    const message = outcomeMessage(outcome);

    if (message === null) {
        return null;
    }

    return (
        <p role="alert" className="rounded-xl border border-notice/30 bg-notice-face px-4 py-3 text-notice">
            {message}
        </p>
    );
}
