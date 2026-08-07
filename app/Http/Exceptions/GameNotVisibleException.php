<?php

declare(strict_types=1);

namespace App\Http\Exceptions;

use App\Games\VisibilityOutcome;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A request named a Game it may not see: `ResolveActingPlayer`'s way of
 * short-circuiting, carrying the `VisibilityOutcome` that says which of the three
 * refusals it was.
 *
 * WHY AN EXCEPTION RATHER THAN A RESPONSE FROM THE MIDDLEWARE. Requirement 3.9
 * requires authorisation to be settled before any other condition is evaluated,
 * and throwing is the only form of short-circuit that cannot be undone
 * downstream: no controller runs, no action is constructed, no snapshot is read,
 * on GET and POST alike. A middleware that *returned* a response would achieve
 * the same thing today and would still have to decide what that response looks
 * like — which is task 5.6's decision, not this class's.
 *
 * THE SEAM FOR TASK 5.6. That task renders `NotAPlayer.tsx` keyed by outcome for
 * 403/404/410. It needs two facts from a refusal and gets both from here: the
 * outcome value, as `$exception->outcome`, and the status, as
 * `$exception->getStatusCode()`. So the renderer it registers in
 * `bootstrap/app.php` is
 *
 *     $exceptions->render(fn (GameNotVisibleException $e, Request $request) =>
 *         Inertia::render('NotAPlayer', ['outcome' => $e->outcome->value])
 *             ->toResponse($request)
 *             ->setStatusCode($e->getStatusCode()));
 *
 * and the prop it passes is the outcome and nothing else, because this exception
 * carries nothing else — there is no Game on it to leak (Req 3.10). Until that
 * renderer exists, the framework's own handler answers with the correct status
 * and a body with no game data in it, which is already the behaviour the design
 * requires, just not yet the page.
 *
 * WHY THE STATUS MAPPING LIVES HERE AND NOT ON `VisibilityOutcome`. The design is
 * explicit that outcome distinctness is carried by the value and that the HTTP
 * status is merely how the transport expresses it. Putting 403/404/410 on the
 * enum would put transport vocabulary in `App\Games`, where a class that answers
 * a question about a Player_Token has no business knowing about HTTP. Keeping it
 * here means the whole of the three-way status decision is one `match` on the
 * transport side of the boundary, and `GameResolver` stays a function from a
 * Game_Id to a value.
 *
 * WHY IT EXTENDS `HttpException`. That is the contract Laravel's handler already
 * understands: the status is honoured without a renderer being registered, so the
 * middleware is correct on its own and task 5.6 replaces the page rather than
 * supplying the status. `NotFoundHttpException` and `AccessDeniedHttpException`
 * were the alternative and are worse for one specific reason: three refusals with
 * three exception classes would give 5.6 three renderers to register and would
 * leave `game_expired` with no framework class at all, while the outcome — the
 * thing the design says carries the distinction, and the thing the page is keyed
 * on — would have to be recovered from the status code. One class carrying the
 * outcome keeps the vocabulary in one place.
 */
final class GameNotVisibleException extends HttpException
{
    public function __construct(
        public readonly VisibilityOutcome $outcome,
    ) {
        parent::__construct(
            // The design's outcome table, verbatim: 403 for `not_authorised`,
            // 404 for `not_recognised` by Game_Id, 410 for `game_expired`. The
            // match is exhaustive, so a fourth visibility outcome would be a
            // static-analysis failure here rather than a silent default.
            match ($outcome) {
                VisibilityOutcome::NotAuthorised => 403,
                VisibilityOutcome::NotRecognised => 404,
                VisibilityOutcome::GameExpired => 410,
            },
            // The message is the outcome value, which is the only fact about the
            // request there is to state. Nothing about the Game appears in it —
            // not the Game_State, not the Move_List, not a token (Req 3.10, 8.7)
            // — because a message is rendered on the debug error page and would
            // be the easiest place to leak the thing this exception exists to
            // withhold.
            $outcome->value,
        );
    }
}
