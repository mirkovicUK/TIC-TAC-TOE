import { Head, Link } from '@inertiajs/react';

/*
 * The denial-of-visibility page: 403 `not_authorised`, 404 `not_recognised` and 410
 * `game_expired`, rendered by the `GameNotVisibleException` handler in
 * `bootstrap/app.php`.
 *
 * ONE PROP, AND IT IS THE ONLY THING THE SERVER SENT. There is no `game` prop on this
 * page and there is nothing this component could do with one: a request that fails
 * `GameResolver` gets the Board, the Move_List, the Game_State and the Mark_To_Move
 * excluded from its response (Req 3.7, 3.10), and the exception the handler renders
 * from carries a `VisibilityOutcome` and nothing else.
 *
 * ALL THREE `not_authorised` MODES SHARE ONE MESSAGE, because they share one outcome
 * value: no Player_Token, a token bound to nothing, and a token bound to another Game
 * are indistinguishable by design (Req 9.6). The server does not tell them apart, so
 * this page cannot and must not.
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

            <main className="mx-auto flex min-h-screen max-w-xl flex-col justify-center gap-4 p-6">
                <h1 className="text-2xl font-semibold">{heading}</h1>
                <p className="text-gray-700">{body}</p>
                <Link href="/" className="w-fit rounded bg-gray-900 px-4 py-2 font-medium text-white">
                    Start a new game
                </Link>
            </main>
        </>
    );
}
