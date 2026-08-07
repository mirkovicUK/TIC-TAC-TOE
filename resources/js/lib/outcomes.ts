/*
 * The outcome vocabulary, client side: one map from an outcome value to the copy a
 * player is shown.
 *
 * THE VALUES MIRROR THE SERVER'S THREE OUTCOME ENUMS EXACTLY — `MoveOutcome`
 * (`game_not_started`, `game_ended`, `not_your_turn`, `invalid_move`, `conflict`),
 * `JoinOutcome` (`game_full`, `not_recognised`) and `VisibilityOutcome`
 * (`not_authorised`, `game_expired`, `not_recognised`). `not_recognised` appears once
 * because the server spells it once: `JoinOutcome::NotRecognised` and
 * `VisibilityOutcome::NotRecognised` deliberately share one backing value and one
 * client-facing message, and which of them was raised is carried by the transport
 * rather than by the string.
 *
 * TWO OUTCOMES IN THE DESIGN'S TABLE ARE ABSENT, AND THEIR ABSENCE IS DATED RATHER
 * THAN PERMANENT. `invalid_state` (Req 7.10) arrives with the rematch outcome enum in
 * task 7, and `rate_limited` (Req 10.6, 10.7) comes from framework middleware in task
 * 10 rather than from an enum in `App\Games`. Neither value can be flashed by any code
 * that exists today, so neither is listed: this file mirrors the vocabulary the server
 * can currently produce, and adding a key for an outcome nothing raises would make the
 * mirror unfalsifiable. `FALLBACK` below is what keeps the interim honest.
 *
 * A MISSING CASE IS A COMPILE ERROR. `MESSAGES` is typed `Record<Outcome, string>`
 * over the union derived from `OUTCOME_VALUES`, so adding a value to that list without
 * adding its copy fails `tsc` rather than falling through to the fallback at runtime.
 * The fallback exists for a different reason: `outcome` reaches the client as a
 * `string` from a session flash, so a value this file has never heard of is
 * representable at runtime whatever the type says. Rendering nothing for it would hide
 * a real rejection — the player would click, see no move and see no reason — so an
 * unrecognised value gets neutral copy instead of silence.
 *
 * `Join.tsx` AND `NotAPlayer.tsx` KEEP THEIR OWN COPY AND DO NOT IMPORT THIS. Both
 * render a rejection to someone who is *not* a Player of a Game, in the whole-page
 * form, with wording that explains what to do next; this map is the one-line form
 * shown above a board to a Player whose action was refused. Same values, different
 * reader, and folding them together would mean one string trying to serve both.
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
