import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import Card from '@/components/Card';
import Layout from '@/components/Layout';

/*
 * `GET /` — the entry page: create a Game, or join one with a code.
 *
 * Both actions are real forms because `useForm().post()` is what carries the CSRF token
 * (Inertia sends the `XSRF-TOKEN` cookie back as a header), so a hand-rolled `fetch` here
 * would be a 419 waiting to happen. The paths are literals rather than a route helper
 * because there is no route-name package installed on the client; `routes/web.php` names
 * them and this file is the only other place they appear.
 *
 * The join form here and the one on `Join.tsx` are deliberately separate rather than
 * a shared component: this one starts empty and that one arrives prefilled from a
 * Join_Link, and folding them together would mean a component whose only job is to
 * decide which of two pages it is on.
 *
 * The two headings read "Start a game" and "Join a game", and the buttons "Create a game"
 * and "Join game". Those four strings are what `PlayAGameTest` clicks and asserts on, so
 * they are not free to reword.
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

            <Layout home={false}>
                {/* The mark colours appear here before the board does, so the hue that
                    means X on the board is already established by the time it matters. */}
                <header className="flex flex-col gap-3">
                    <p className="font-mono text-sm tracking-[0.3em] text-ink-muted uppercase">
                        <span className="text-mark-x">X</span> <span className="text-mark-o">O</span> two players, one link
                    </p>
                    <h1 className="text-5xl font-bold tracking-tight text-balance">Tic-Tac-Toe</h1>
                    <p className="text-ink-muted">
                        Play someone in another browser. No account, no sign-up — the link is the invitation.
                    </p>
                </header>

                <Card as="section" aria-labelledby="create-heading" className="flex flex-col gap-3">
                    <h2 id="create-heading" className="text-lg font-semibold">
                        Start a game
                    </h2>
                    <p className="text-sm text-ink-muted">
                        You play <span className="font-mono font-bold text-mark-x">X</span>. We will give you a code and a
                        link to send to the other player.
                    </p>
                    <form onSubmit={submitCreate}>
                        <button
                            type="submit"
                            disabled={create.processing}
                            className="w-full rounded-xl bg-accent px-4 py-3 font-semibold text-surface-deep shadow-[0_4px_0_0_var(--color-surface-deep)] transition hover:bg-accent-hover active:translate-y-0.5 active:shadow-[0_1px_0_0_var(--color-surface-deep)] focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-panel focus-visible:outline-none disabled:opacity-50 sm:w-fit"
                        >
                            Create a game
                        </button>
                    </form>
                </Card>

                <Card as="section" aria-labelledby="join-heading" className="flex flex-col gap-3">
                    <h2 id="join-heading" className="text-lg font-semibold">
                        Join a game
                    </h2>
                    <p className="text-sm text-ink-muted">
                        Been sent a code? You play <span className="font-mono font-bold text-mark-o">O</span>.
                    </p>
                    <form onSubmit={submitJoin} className="flex flex-col gap-3">
                        <label htmlFor="join_code" className="text-sm font-medium">
                            Join code
                        </label>
                        <div className="flex flex-wrap items-center gap-3">
                            <input
                                id="join_code"
                                name="join_code"
                                type="text"
                                autoComplete="off"
                                spellCheck={false}
                                placeholder="XXXXX-XXXXX"
                                value={join.data.join_code}
                                onChange={(event) => join.setData('join_code', event.target.value)}
                                className="w-56 rounded-xl border border-hairline bg-surface-deep px-3 py-2.5 font-mono tracking-widest uppercase placeholder:tracking-normal placeholder:text-ink-muted/50 focus-visible:border-accent focus-visible:ring-2 focus-visible:ring-accent focus-visible:outline-none"
                            />
                            <button
                                type="submit"
                                disabled={join.processing}
                                className="rounded-xl border border-hairline bg-elevated px-4 py-2.5 font-semibold transition hover:bg-surface active:translate-y-px focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-panel focus-visible:outline-none disabled:opacity-50"
                            >
                                Join game
                            </button>
                        </div>
                    </form>
                </Card>
            </Layout>
        </>
    );
}
