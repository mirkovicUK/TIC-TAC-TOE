import { Head, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';

/*
 * `GET /join/{join_code?}` — the target of a Join_Link, and the landing place for a
 * rejected join.
 *
 * TWO INPUTS, ONE PAGE. `joinCode` is the route segment, normalised to the
 * `XXXXX-XXXXX` display form by `JoinFormController` when it could be read at all,
 * so a link opens with the field filled and the player only has to press the button.
 * `outcome` is the shared prop `HandleInertiaRequests` reads out of the flash, and it
 * arrives after a 303 from `POST /join`: `not_recognised` when the code matched no
 * Game, `game_full` when it matched one that already has two Players.
 *
 * NOTHING ABOUT THE GAME IS ON THIS PAGE, and there is nothing to put here: a
 * rejected join means the caller is not a Player, so the server sent the outcome
 * value and no Game_State, Board or Move_List (Req 3.10). The two messages below are
 * the entire vocabulary this page renders. They are written out here rather than
 * imported from `lib/outcomes.ts`, which task 6.3 introduces for the outcomes of an
 * authorised Player's action — a different family, on a different page, for a
 * different reader.
 */

const JOIN_OUTCOME_MESSAGES: Record<string, string> = {
    not_recognised: 'That join code was not recognised. Check it and try again.',
    game_full: 'That game already has two players.',
};

type JoinProps = {
    joinCode: string | null;
};

export default function Join({ joinCode }: JoinProps) {
    const { outcome } = usePage<{ outcome: string | null }>().props;

    const form = useForm({ join_code: joinCode ?? '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/join');
    };

    const message = outcome === null ? null : (JOIN_OUTCOME_MESSAGES[outcome] ?? 'That join request could not be completed.');

    return (
        <>
            <Head title="Join a game" />

            <main className="mx-auto flex min-h-screen max-w-xl flex-col justify-center gap-6 p-6">
                <h1 className="text-3xl font-semibold">Join a game</h1>

                {message !== null && (
                    <p role="alert" className="rounded border border-amber-300 bg-amber-50 px-4 py-3 text-amber-900">
                        {message}
                    </p>
                )}

                <form onSubmit={submit} className="flex flex-col gap-3">
                    <label htmlFor="join_code" className="text-sm font-medium">
                        Join code
                    </label>
                    <input
                        id="join_code"
                        name="join_code"
                        type="text"
                        autoComplete="off"
                        spellCheck={false}
                        placeholder="XXXXX-XXXXX"
                        value={form.data.join_code}
                        onChange={(event) => form.setData('join_code', event.target.value)}
                        className="w-56 rounded border border-gray-300 px-3 py-2 font-mono uppercase"
                    />
                    <button
                        type="submit"
                        disabled={form.processing}
                        className="w-fit rounded bg-gray-900 px-4 py-2 font-medium text-white disabled:opacity-50"
                    >
                        Join game
                    </button>
                </form>
            </main>
        </>
    );
}
