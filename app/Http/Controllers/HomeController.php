<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * `GET /` — the entry page, carrying the create form and the join form.
 *
 * NO PROPS, AND NO OUTCOME EITHER. There is nothing for the server to say here: a
 * visitor at `/` holds no Game and may not be given one, and both forms post to
 * routes that decide everything. A rejected join redirects to `/join` rather than
 * back here (the design's outcome table, third transport family), so the outcome
 * copy lives on `Join.tsx` and this page never has one to render.
 */
final class HomeController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Home');
    }
}
