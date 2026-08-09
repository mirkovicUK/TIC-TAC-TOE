import { Head, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import Card from '@/components/Card';
import Layout from '@/components/Layout';

/*
 * `GET /join/{join_code?}` — the target of a Join_Link, and the landing place for a
 * rejected join.
 *
 * Two inputs. `joinCode` is the route segment, normalised to the `XXXXX-XXXXX` display form
 * by `JoinFormController` when it could be read at all, so a link opens with the field
 * filled. `outcome` is the shared prop `HandleInertiaRequests` reads out of the flash, and
 * it arrives after a 303 from `POST /join`.
 *
 * A rejected join means the caller is not a Player, so the server sent the outcome value and
 * no Game_State, Board or Move_List (Req 3.10). The two messages below are written out here
 * rather than imported from `lib/outcomes.ts`, which is the one-line form shown to an
 * authorised Player — a different family, for a different reader.
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

            <Layout>
                <h1 className="text-3xl font-semibold tracking-tight">Join a game</h1>

                {message !== null && (
                    <p role="alert" className="rounded-xl border border-notice/30 bg-notice-face px-4 py-3 text-notice">
                        {message}
                    </p>
                )}

                <Card className="flex flex-col gap-3">
                <p className="text-sm text-ink-muted">
                    You play <span className="font-mono font-bold text-mark-o">O</span>.
                </p>
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
                        className="w-56 rounded-xl border border-hairline bg-surface-deep px-3 py-2.5 font-mono tracking-widest uppercase text-ink placeholder:tracking-normal placeholder:text-ink-muted/50 focus-visible:border-accent focus-visible:ring-2 focus-visible:ring-accent focus-visible:outline-none"
                    />
                    <button
                        type="submit"
                        disabled={form.processing}
                        className="w-fit rounded-xl bg-accent px-4 py-2.5 font-semibold text-surface-deep shadow-[0_4px_0_0_var(--color-surface-deep)] transition hover:bg-accent-hover active:translate-y-0.5 active:shadow-[0_1px_0_0_var(--color-surface-deep)] focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-panel focus-visible:outline-none disabled:opacity-50"
                    >
                        Join game
                    </button>
                </form>
                </Card>
            </Layout>
        </>
    );
}
