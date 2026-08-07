/*
 * The outcome vocabulary, client side: one map from an outcome value to the copy a player
 * is shown. The values mirror the server's `MoveOutcome`, `JoinOutcome`,
 * `VisibilityOutcome` and `RematchOutcome`. `not_recognised` appears once because
 * `JoinOutcome::NotRecognised` and `VisibilityOutcome::NotRecognised` deliberately share
 * one backing value and one message; which was raised is carried by the transport.
 *
 * A missing case is a compile error: `MESSAGES` is typed `Record<Outcome, string>` over
 * the union derived from `OUTCOME_VALUES`, so adding a value without its copy fails
 * `tsc`. The fallback exists for a different reason — `outcome` reaches the client as a
 * `string` from a session flash, so a value this file has never heard of is representable
 * at runtime whatever the type says, and rendering nothing would hide a real rejection.
 *
 * `Join.tsx` and `NotAPlayer.tsx` keep their own copy and do not import this. They render
 * a whole-page rejection to someone who is *not* a Player; this map is the one-line form
 * shown above a board. Same values, different reader.
 */

export const OUTCOME_VALUES = [
    // VisibilityOutcome
    'not_authorised',
    'game_expired',
    'not_recognised',
    // JoinOutcome — `not_recognised` above is shared with it
    'game_full',
    // MoveOutcome
    'game_not_started',
    'game_ended',
    'not_your_turn',
    'invalid_move',
    'conflict',
    // RematchOutcome
    'invalid_state',
] as const;

export type Outcome = (typeof OUTCOME_VALUES)[number];

const MESSAGES: Record<Outcome, string> = {
    not_authorised: 'You are not a player in this game.',
    game_expired: 'That game is no longer available.',
    not_recognised: 'We do not recognise that game.',
    game_full: 'That game already has two players.',
    game_not_started: 'The other player has not joined yet, so there is no turn to take.',
    game_ended: 'That game has already finished.',
    not_your_turn: 'It is not your turn.',
    invalid_move: 'That square is not one you can play.',
    // Requirement 5.5: the board rendered alongside this message is the state the
    // server returned with the conflict, not the one this client submitted against.
    conflict: 'Your opponent got there first. This is the current board.',
    // Requirement 7.10: a rematch was requested for a game that has not finished.
    invalid_state: 'This game is still in play, so there is no rematch to start yet.',
};

const FALLBACK = 'That action could not be completed.';

export function isOutcome(value: string): value is Outcome {
    return (OUTCOME_VALUES as readonly string[]).includes(value);
}

/**
 * The copy for `value`, or null when there is nothing to say.
 *
 * Takes the prop as it arrives — `string | null`, because `HandleInertiaRequests`
 * shares `outcome` as a nullable string — so no caller has to narrow it first.
 */
export function outcomeMessage(value: string | null): string | null {
    if (value === null) {
        return null;
    }

    return isOutcome(value) ? MESSAGES[value] : FALLBACK;
}
