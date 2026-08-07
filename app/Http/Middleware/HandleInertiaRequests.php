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
     * `outcome` IS THE ONE SHARED PROP, AND IT IS THE WHOLE FLASH MECHANISM.
     * Every rejection the design answers with a redirect — `not_recognised` and
     * `game_full` back to `/join` (task 5.6), and `game_not_started`,
     * `game_ended`, `not_your_turn`, `invalid_move`, `conflict` and
     * `invalid_state` back to the game page (tasks 6.2 and 7.x) — is a 303 with
     * `->with('outcome', $outcome->value)`. Reading it here means the page
     * components take it as a prop rather than reaching for the session, and
     * means one mechanism serves both redirect families instead of one per page.
     *
     * DELIBERATELY NOT `flash.outcome` OR A NESTED BAG. The prop is the outcome
     * value and nothing else, so the two denial-of-visibility pages and the two
     * redirect families all key off one string; a bag invites a second key, and
     * the second key is where a Game_State or a Join_Code ends up.
     *
     * IT IS A CLOSURE so that it is evaluated per response rather than at
     * middleware time, which is what lets a partial reload (`only: ['game']`)
     * leave it out entirely — a poll must not consume a flashed outcome the page
     * has not rendered yet.
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
     * Narrowed to `?string` here rather than at every call site: the session bag
     * is typed `mixed`, and the four page components that render this prop are
     * TypeScript files that cannot narrow it. A non-string under that key would
     * mean something other than a controller wrote it, and the honest answer to
     * that is null rather than whatever it was.
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
