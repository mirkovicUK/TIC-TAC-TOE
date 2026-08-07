<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * `outcome` is the one shared prop, and the whole flash mechanism: every
     * rejection the design answers with a redirect is a 303 carrying
     * `->with('outcome', $outcome->value)`, so reading it here lets one mechanism
     * serve both redirect families and lets pages take it as a prop rather than
     * reaching for the session. It is the bare value, not `flash.outcome` or a
     * nested bag — a bag invites a second key, and the second key is where a
     * Game_State or a Join_Code ends up.
     *
     * It is a CLOSURE so it is evaluated per response rather than at middleware
     * time, which lets a partial reload (`only: ['game']`) leave it out: a poll
     * must not consume a flashed outcome the page has not rendered yet.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'outcome' => fn (): ?string => $this->flashedOutcome($request),
        ];
    }

    /**
     * The flashed outcome value, or null.
     *
     * Narrowed to `?string` here rather than at every call site: the session bag is
     * typed `mixed` and the page components are TypeScript files that cannot narrow
     * it. A non-string under that key means something other than a controller wrote
     * it, and null is the honest answer to that.
     */
    private function flashedOutcome(Request $request): ?string
    {
        if (! $request->hasSession()) {
            return null;
        }

        $outcome = $request->session()->get('outcome');

        return is_string($outcome) ? $outcome : null;
    }
}
