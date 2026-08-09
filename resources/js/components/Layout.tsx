import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

/*
 * The one page wrapper. Every page was repeating the same `<main>` classes, so a change of
 * background meant four edits and four chances to miss one; this is that markup, once.
 *
 * It renders `<main>` itself, which is why no page may nest another one — a second `main`
 * landmark is a real accessibility fault, not a cosmetic one. Pages pass their content as
 * children and their own heading.
 *
 * The background is two layers. `html` carries the flat colour (see `app.css`, so there is
 * no white flash before React mounts) and this adds a radial highlight behind the content,
 * which is what stops a dark page reading as flat grey. `bg-fixed` keeps it still while the
 * page scrolls.
 *
 * `home` defaults to true and is set false on `Home.tsx` alone. The link is the escape
 * hatch: it moves THIS player only — there is no server push, so the other player's browser
 * learns nothing from it and uses its own link. That is deliberate, and it is why this
 * needed no server change. Its label is not "Start a new game", which would imply something
 * happens to the current game; `RematchControl` owns "Play again", which keeps the same
 * opponent and is a different action.
 */

type LayoutProps = {
    children: ReactNode;
    home?: boolean;
};

export default function Layout({ children, home = true }: LayoutProps) {
    return (
        <div className="min-h-screen bg-surface bg-[radial-gradient(75rem_40rem_at_50%_-10%,var(--color-panel),transparent)] bg-fixed">
            <main className="mx-auto flex min-h-screen w-full max-w-xl flex-col justify-center gap-6 p-6">
                {children}

                {home && (
                    <p className="pt-2">
                        <Link
                            href="/"
                            className="rounded text-sm text-ink-muted underline decoration-hairline underline-offset-4 transition-colors hover:text-ink hover:decoration-ink focus-visible:ring-2 focus-visible:ring-accent focus-visible:outline-none"
                        >
                            Leave and start over
                        </Link>
                    </p>
                )}
            </main>
        </div>
    );
}
