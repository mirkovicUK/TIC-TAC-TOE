<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Games\JoinCode;
use Inertia\Inertia;
use Inertia\Response;

/**
 * `GET /join/{join_code?}` — the join form, prefilled when a Join_Link supplied a
 * code (Req 1.6, 2.2).
 *
 * WHAT `GET /join` WITH NO CODE DOES, AND WHY IT IS NOT A 404. Two reasons, and
 * either alone would settle it. It is the manual-entry form for the player whose
 * opponent read the code aloud instead of sending a link — `Home.tsx` carries the
 * same form, and a person who navigates to `/join` should not be told the page does
 * not exist. And it is the landing place for every join rejection: the design's
 * third transport family answers `not_recognised` and `game_full` with a 303 back
 * to `/join` and the outcome flashed, so this page must render with no code and an
 * outcome. That is why the parameter is optional rather than two separate routes.
 *
 * NO LOOKUP HAPPENS HERE, and none may be added. This is a GET that renders a form;
 * resolving the code to a Game is `POST /join`'s job, and doing it here would turn
 * a pasted Join_Link into a state-changing request performed by whatever prefetched
 * it, as well as handing a prober a GET oracle over the code space.
 *
 * SO THE PREFILL IS NOT VALIDATION EITHER. A code that `JoinCode::parse()` can read
 * is normalised to its `XXXXX-XXXXX` display form, so a link written in lower case
 * or with an `l` for a `1` arrives looking like the code the other player is
 * holding. Anything else is passed through verbatim for the player to correct, and
 * is refused by `JoinGame` on submission with the same `not_recognised` as any other
 * unmatched code — one vocabulary for one condition, exactly as `JoinGame::handle()`
 * takes `mixed` rather than `string` for.
 */
final class JoinFormController extends Controller
{
    /**
     * @param  string|null  $join_code  The `{join_code?}` route segment, named to
     *                                  match the route parameter so the framework
     *                                  injects it. Absent for `GET /join`.
     */
    public function __invoke(?string $join_code = null): Response
    {
        return Inertia::render('Join', [
            'joinCode' => $join_code === null
                ? null
                : JoinCode::parse($join_code)?->display() ?? $join_code,
        ]);
    }
}
