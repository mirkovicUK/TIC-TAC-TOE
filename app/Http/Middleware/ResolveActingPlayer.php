<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Games\GameResolver;
use App\Games\ResolvedPlayer;
use App\Games\VisibilityOutcome;
use App\Http\Exceptions\GameNotVisibleException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs `GameResolver` for every route naming a Game_Id, and throws
 * `GameNotVisibleException` if the answer is a refusal.
 *
 * This is where Requirement 3.9 becomes a property of the pipeline rather than a
 * rule each controller remembers: authorisation is settled first, and nothing
 * downstream of `$next()` runs on a refusal, so it cannot be un-refused later.
 *
 * Route-model binding must be kept away from these routes — the one thing that
 * can quietly break the whole visibility table. `SubstituteBindings` sits in the
 * `web` group, and group middleware runs before route middleware, so a controller
 * type-hinting `App\Models\Game` for the `{game}` parameter would have the
 * framework abort with its own 404 for any id with no row. That collapses rows 3,
 * 4, 6 and 7 into one status and destroys the `game_expired` distinction
 * Requirement 13.6 requires — quietly, because 404 is the right answer for two of
 * those four rows. So no game-scoped controller may type-hint `Game`, and no
 * `Route::model()` or `Route::bind()` may be registered for that name;
 * controllers call `resolved()` below instead, which supplies the acting Mark as
 * well as the row. `ResolveActingPlayerTest` pins the hazard with a route whose
 * handler type-hints `Game` and answers 404 before this middleware runs.
 *
 * Registered in `bootstrap/app.php` as the alias `acting.player`, never globally:
 * a route with no `{game}` parameter would throw the `LogicException` below on
 * every request.
 */
final class ResolveActingPlayer
{
    /**
     * The route parameter naming the Game, spelled once here rather than in each
     * of the three `{game}` routes.
     */
    public const string ROUTE_PARAMETER = 'game';

    /**
     * Where the `ResolvedPlayer` is left for the controller.
     *
     * A request attribute rather than a container binding: it cannot outlive the
     * request, and a controller on a route that forgot this middleware gets the
     * explicit `LogicException` from `resolved()` naming the mistake rather than a
     * container resolution error. Namespaced under `game.` to avoid collisions.
     */
    public const string REQUEST_ATTRIBUTE = 'game.resolved_player';

    public function __construct(
        private readonly GameResolver $resolver,
    ) {}

    /**
     * Resolve, or refuse.
     *
     * @param  Closure(Request): Response  $next
     *
     * @throws GameNotVisibleException on any of the three refusals — 403 for
     *                                 `not_authorised`, 404 for
     *                                 `not_recognised`, 410 for `game_expired`
     */
    public function handle(Request $request, Closure $next): Response
    {
        $resolution = $this->resolver->resolve($this->gameIdFrom($request));

        // A `VisibilityOutcome` is a fieldless enum case, so there is no Game here
        // to be handed on by accident (Req 3.10).
        if ($resolution instanceof VisibilityOutcome) {
            throw new GameNotVisibleException($resolution);
        }

        $request->attributes->set(self::REQUEST_ATTRIBUTE, $resolution);

        return $next($request);
    }

    /**
     * The `ResolvedPlayer` this middleware established for `$request` — the Game
     * row and the acting Mark together. This is the seam every game-scoped
     * controller uses, which is why none of them type-hints `App\Models\Game` or
     * repeats the authorisation question.
     *
     * Typed rather than a bare `$request->attributes->get(...)`, so no call site
     * has to narrow the `mixed` bag itself.
     *
     * @throws LogicException if the route this request matched was not protected
     *                        by this middleware. That is a routing defect, not a
     *                        user error: an unprotected game-scoped route would
     *                        serve a Game to anyone, so it fails loudly as a 500
     *                        rather than being reported as one of the visibility
     *                        outcomes.
     */
    public static function resolved(Request $request): ResolvedPlayer
    {
        $resolution = $request->attributes->get(self::REQUEST_ATTRIBUTE);

        if (! $resolution instanceof ResolvedPlayer) {
            throw new LogicException(sprintf(
                'No acting player has been resolved for this request. Every route naming a %s parameter must run the %s middleware.',
                self::ROUTE_PARAMETER,
                self::class,
            ));
        }

        return $resolution;
    }

    /**
     * The raw Game_Id from the URL.
     *
     * `originalParameter()` and not `parameter()`: the former is the string the URL
     * carried, the latter is whatever route-model binding may have replaced it
     * with. See the class docblock — the visibility table depends on this class
     * being handed an id it can fail to find.
     *
     * @throws LogicException if the matched route declares no `{game}` parameter,
     *                        meaning the middleware was registered somewhere it
     *                        does not belong. A routing defect, and one that must
     *                        not be reported as `not_recognised`.
     */
    private function gameIdFrom(Request $request): string
    {
        $route = $request->route();

        $gameId = $route instanceof Route
            ? $route->originalParameter(self::ROUTE_PARAMETER)
            : null;

        if (! is_string($gameId) || $gameId === '') {
            throw new LogicException(sprintf(
                'The %s middleware ran on a route with no %s parameter; it applies only to routes naming a Game id.',
                self::class,
                self::ROUTE_PARAMETER,
            ));
        }

        return $gameId;
    }
}
