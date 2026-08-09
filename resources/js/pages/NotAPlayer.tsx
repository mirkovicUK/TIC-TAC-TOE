import { Head } from '@inertiajs/react';
import Layout from '@/components/Layout';

/*
 * The denial-of-visibility page: 403 `not_authorised`, 404 `not_recognised` and 410
 * `game_expired`, rendered by the `GameNotVisibleException` handler in
 * `bootstrap/app.php`.
 *
 * The outcome is the only prop, and there is no `game` prop to add: a request that fails
 * `GameResolver` has the Board, Move_List, Game_State and Mark_To_Move excluded from its
 * response (Req 3.7, 3.10).
 *
 * All three `not_authorised` modes share one message because they share one outcome value:
 * no Player_Token, a token bound to nothing, and a token bound to another Game are
 * indistinguishable by design (Req 9.6), so this page must not tell them apart either.
 *
 * The keys are the outcome values from `App\Games\VisibilityOutcome`. The fallback
 * exists because `outcome` reaches this page as a string: a value not in the map means
 * the enum grew a case this file did not, and a neutral refusal is a better answer
 * than a blank page.
 */

const MESSAGES: Record<string, { heading: string; body: string }> = {
    not_authorised: {
        heading: 'You are not a player in this game',
        body: 'This game belongs to two other players, or the link you used was not the one issued to you.',
    },
    not_recognised: {
        heading: 'We do not recognise that game',
        body: 'Check the link you followed. If a game was created, only its two players can open it.',
    },
    game_expired: {
        heading: 'That game is no longer available',
        body: 'Games are kept for seven days after the last move, then deleted. This one has been.',
    },
};

const FALLBACK = {
    heading: 'You are not a player in this game',
    body: 'We cannot show you this game.',
};

type NotAPlayerProps = {
    outcome: string;
};

export default function NotAPlayer({ outcome }: NotAPlayerProps) {
    const { heading, body } = MESSAGES[outcome] ?? FALLBACK;

    return (
        <>
            <Head title={heading} />

            <Layout>
                <h1 className="text-2xl font-semibold tracking-tight">{heading}</h1>
                <p className="text-ink-muted">{body}</p>
            </Layout>
        </>
    );
}
