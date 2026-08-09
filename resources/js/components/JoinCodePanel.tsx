import { useState } from 'react';
import Card from '@/components/Card';

/*
 * The Join_Code and a copyable Join_Link, shown while a Game is
 * `waiting_for_opponent` (Req 1.6, 1.7).
 *
 * It takes the two strings rather than the game prop: `GameRepresentation` sets `joinCode`
 * and `joinUrl` only while the Game is waiting and nulls both afterwards, so the state gate
 * lives on the server and the caller renders this panel or does not.
 *
 * The link is also shown as text in a read-only field because the Clipboard API needs a
 * secure context, so `navigator.clipboard` is absent over plain HTTP — which the deployment
 * allows as a fallback if TLS could not be obtained. `readOnly` rather than `disabled` is
 * what keeps that field selectable and reachable by keyboard.
 *
 * The waiting indication (Req 1.7) is here rather than in the page's status banner
 * because it is the same fact as the code: this panel exists exactly while there is
 * no second Player.
 */

type JoinCodePanelProps = {
    joinCode: string;
    joinUrl: string;
};

export default function JoinCodePanel({ joinCode, joinUrl }: JoinCodePanelProps) {
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(joinUrl);
            setCopied(true);
        } catch {
            setCopied(false);
        }
    };

    return (
        <Card as="section" aria-labelledby="join-code-heading" className="flex flex-col gap-4">
            {/* The pulsing dot is the only motion on the page and it is decorative:
                `role="status"` on the heading is what actually announces the wait. */}
            <h2 id="join-code-heading" className="flex items-center gap-2.5 text-lg font-semibold">
                <span aria-hidden="true" className="relative flex size-2.5 shrink-0">
                    <span className="absolute inline-flex size-full animate-ping rounded-full bg-accent opacity-60" />
                    <span className="relative inline-flex size-2.5 rounded-full bg-accent" />
                </span>
                Waiting for a second player
            </h2>

            <p className="text-sm text-ink-muted">Send one of these to the person you want to play.</p>

            <div className="flex flex-col gap-1.5">
                <span id="join-code-label" className="text-xs font-medium tracking-wider text-ink-muted uppercase">
                    Join code
                </span>
                {/* Big, monospaced and widely tracked, because this is read aloud down a
                    phone as often as it is copied. */}
                <p
                    aria-labelledby="join-code-label"
                    className="rounded-xl bg-surface-deep px-4 py-3 text-center font-mono text-3xl font-bold tracking-[0.2em] text-ink select-all"
                >
                    {joinCode}
                </p>
            </div>

            <div className="flex flex-col gap-2">
                <label htmlFor="join-link" className="text-xs font-medium tracking-wider text-ink-muted uppercase">
                    Join link
                </label>
                <div className="flex flex-wrap items-center gap-2">
                    <input
                        id="join-link"
                        type="text"
                        readOnly
                        value={joinUrl}
                        onFocus={(event) => event.target.select()}
                        className="w-full max-w-md rounded-lg border border-hairline bg-surface-deep px-3 py-2 font-mono text-sm text-ink"
                    />
                    <button
                        type="button"
                        onClick={() => void copy()}
                        className="rounded-xl border border-hairline bg-elevated px-3 py-2 text-sm font-semibold text-ink transition hover:bg-surface active:translate-y-px focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-panel focus-visible:outline-none"
                    >
                        {copied ? 'Copied' : 'Copy link'}
                    </button>
                </div>
                <span role="status" className="min-h-5 text-sm text-win">
                    {copied ? 'Join link copied.' : ''}
                </span>
            </div>
        </Card>
    );
}
