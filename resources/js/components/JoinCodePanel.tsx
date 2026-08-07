import { useState } from 'react';

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
        <section aria-labelledby="join-code-heading" className="flex flex-col gap-4 rounded border border-gray-200 p-4">
            <h2 id="join-code-heading" className="text-lg font-medium">
                Waiting for a second player
            </h2>

            <p className="text-sm text-gray-600">Send one of these to the person you want to play.</p>

            <div className="flex flex-col gap-1">
                <span id="join-code-label" className="text-sm font-medium">
                    Join code
                </span>
                <p aria-labelledby="join-code-label" className="font-mono text-2xl tracking-widest">
                    {joinCode}
                </p>
            </div>

            <div className="flex flex-col gap-2">
                <label htmlFor="join-link" className="text-sm font-medium">
                    Join link
                </label>
                <div className="flex flex-wrap items-center gap-2">
                    <input
                        id="join-link"
                        type="text"
                        readOnly
                        value={joinUrl}
                        onFocus={(event) => event.target.select()}
                        className="w-full max-w-md rounded border border-gray-300 px-3 py-2 font-mono text-sm"
                    />
                    <button
                        type="button"
                        onClick={() => void copy()}
                        className="rounded bg-gray-900 px-3 py-2 text-sm font-medium text-white"
                    >
                        Copy link
                    </button>
                </div>
                <span role="status" className="text-sm text-green-700">
                    {copied ? 'Join link copied.' : ''}
                </span>
            </div>
        </section>
    );
}
