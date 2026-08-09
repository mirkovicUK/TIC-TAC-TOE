<?php

declare(strict_types=1);

namespace App\Http\Exceptions;

use App\Games\VisibilityOutcome;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A request named a Game it may not see: `ResolveActingPlayer`'s short-circuit,
 * carrying the `VisibilityOutcome` that says which of the three refusals it was.
 *
 * Thrown rather than returned as a response because throwing is the only
 * short-circuit that cannot be undone downstream — no controller, no action, no
 * snapshot, on GET and POST alike (Req 3.9).
 *
 * `NotAPlayer.tsx` is rendered keyed by outcome and needs only two facts from
 * a refusal, both here: `$exception->outcome` and `$exception->getStatusCode()`.
 * There is no Game on this exception to leak (Req 3.10). Extending `HttpException`
 * means the status is honoured with no renderer registered, so the middleware is
 * already correct and 5.6 supplies the page rather than the status. One class
 * carrying the outcome, rather than three framework exception classes, keeps the
 * distinction on the value the design says carries it.
 *
 * The 403/404/410 mapping lives here and not on `VisibilityOutcome` so that HTTP
 * vocabulary stays out of `App\Games`: `GameResolver` remains a function from a
 * Game_Id to a value.
 */
final class GameNotVisibleException extends HttpException
{
    public function __construct(
        public readonly VisibilityOutcome $outcome,
    ) {
        parent::__construct(
            // The design's outcome table, verbatim. The match is exhaustive, so a
            // fourth visibility outcome is a static-analysis failure here rather
            // than a silent default.
            match ($outcome) {
                VisibilityOutcome::NotAuthorised => 403,
                VisibilityOutcome::NotRecognised => 404,
                VisibilityOutcome::GameExpired => 410,
            },
            // The outcome value and nothing about the Game — no state, no
            // Move_List, no token (Req 3.10, 8.7) — because the message is rendered
            // on the debug error page.
            $outcome->value,
        );
    }
}
