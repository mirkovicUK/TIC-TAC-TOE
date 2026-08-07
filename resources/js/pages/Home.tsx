import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

/*
 * `GET /` — the entry page: create a Game, or join one with a code.
 *
 * BOTH FORMS ARE REAL FORMS AND BOTH BUTTONS ARE BUTTONS. `useForm().post()` is
 * what carries the CSRF token (Inertia sends the `XSRF-TOKEN` cookie back as a
 * header), so a hand-rolled `fetch` here would be a 419 waiting to happen, and an
 * anchor styled as a button would not post at all. The paths are literals rather
 * than a route helper because there is no route-name package installed on the
 * client; `routes/web.php` names them and this file is the only other place they
 * appear.
 *
 * The join form here and the one on `Join.tsx` are deliberately separate rather than
 * a shared component: this one starts empty and that one arrives prefilled from a
 * Join_Link, and folding them together would mean a component whose only job is to
 * decide which of two pages it is on.
 */
export default function Home() {
    const create = useForm({});
    const join = useForm({ join_code: '' });

    const submitCreate = (event: FormEvent) => {
        event.preventDefault();
        create.post('/games');
    };

    const submitJoin = (event: FormEvent) => {
        event.preventDefault();
        join.post('/join');
    };

    return (
        <>
            <Head title="Tic-Tac-Toe" />

            <main className="mx-auto flex min-h-screen max-w-xl flex-col justify-center gap-10 p-6">
                <h1 className="text-3xl font-semibold">Tic-Tac-Toe</h1>

                <section aria-labelledby="create-heading" className="flex flex-col gap-3">
                    <h2 id="create-heading" className="text-lg font-medium">
                        Start a game
                    </h2>
                    <p className="text-sm text-gray-600">
                        You play X. We will give you a code and a link to send to the other player.
                    </p>
                    <form onSubmit={submitCreate}>
                        <button
                            type="submit"
                            disabled={create.processing}
                            className="rounded bg-indigo-600 px-4 py-2 font-medium text-white disabled:opacity-50"
                        >
                            Create a game
                        </button>
                    </form>
                </section>

                <section aria-labelledby="join-heading" className="flex flex-col gap-3">
                    <h2 id="join-heading" className="text-lg font-medium">
                        Join a game
                    </h2>
                    <form onSubmit={submitJoin} className="flex flex-col gap-3">
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
                            value={join.data.join_code}
                            onChange={(event) => join.setData('join_code', event.target.value)}
                            className="w-56 rounded border border-gray-300 px-3 py-2 font-mono uppercase"
                        />
                        <button
                            type="submit"
                            disabled={join.processing}
                            className="w-fit rounded bg-gray-900 px-4 py-2 font-medium text-white disabled:opacity-50"
                        >
                            Join game
                        </button>
                    </form>
                </section>
            </main>
        </>
    );
}
