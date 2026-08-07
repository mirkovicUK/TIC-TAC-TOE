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
 * The code is optional rather than two separate routes because `GET /join` with no
 * code is both the manual-entry form and the landing place for every join rejection:
 * `not_recognised` and `game_full` answer with a 303 back here and the outcome
 * flashed, so this page must render with no code and an outcome.
 *
 * No lookup happens here, and none may be added. Resolving a code to a Game is
 * `POST /join`'s job; doing it on a GET would turn a pasted Join_Link into a
 * state-changing request performed by whatever prefetched it, and would hand a
 * prober a GET oracle over the code space.
 *
 * So the prefill is normalisation, not validation. A code `JoinCode::parse()` can
 * read is shown in `XXXXX-XXXXX` form, so a link written in lower case or with an
 * `l` for a `1` looks like the code the other player holds. Anything else passes
 * through verbatim for the player to correct and is refused on submission with the
 * same `not_recognised` as any other unmatched code.
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
