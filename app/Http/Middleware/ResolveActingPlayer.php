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
 * Runs `GameResolver` for every route naming a Game_Id, and stops the request
 * dead if the answer is a refusal.
 *
 * This is where Requirement 3.9 — authorisation is evaluated before any
 * move-validity condition, and is the only outcome reported for a request that
 * fails it — becomes a property of the request pipeline rather than a rule each
 * controller has to remember. Nothing downstream of `$next()` runs on a refusal:
 * no controller, no `GameSnapshot::of()`, no `SubmitMove` guard, no validation of
 * `cell_index`. The refusal is thrown, so it cannot be un-refused later.
 *
 * ROUTE-MODEL BINDING MUST BE KEPT AWAY FROM THESE ROUTES, and this is the one
 * thing that can quietly break the whole visibility table. Laravel's
 * `SubstituteBindings` middleware sits in the `web` group, and group middleware
 * runs BEFORE route-level middleware, so if a route declared `{game}` and its
 * controller type-hinted `App\Models\Game`, the framework would resolve the model
 * first and abort with its own 404 for any id with no row. That collapses rows 3,
 * 4, 6 and 7 into one framework 404 and destroys the `game_expired` distinction
 * Requirement 13.6 requires — silently, because a 404 is what two of those four
 * rows should produce anyway, so the tests that would notice are the two that
 * expect 410 and the ones that check the outcome value rather than the status.
 *
 * Three things keep it away, and none of them is a comment asking nicely:
 *
 *   - No controller on a game-scoped route may type-hint `App\Models\Game` in the
 *     parameter named `game`, and no `Route::model()` or `Route::bind()` may be
 *     registered for that name. Implicit binding is driven by the controller's
 *     signature, so an untyped `Request`-only controller is never bound. Task 5.6
 *     writes those controllers and this is the constraint it inherits;
 *     `ResolvedPlayer::resolved()` below is what it uses instead, and it is
 *     strictly more useful, since it supplies the acting Mark as well as the row.
 *   - The id is read here from `originalParameter()`, the raw value the URL
 *     carried, never from `parameter()`. If a binding were ever registered, this
 *     middleware would still be resolving a string Game_Id rather than reading an
 *     id back off a model the framework had already found — so the failure would
 *     be confined to the framework's premature 404 and could not also corrupt
 *     what this class resolves.
 *   - `ResolveActingPlayerTest` asserts the hazard directly: a route whose
 *     handler type-hints `Game` answers 404 before this middleware runs, so the
 *     constraint is pinned by a failing test rather than by prose.
 *
 * IT IS NOT A GLOBAL MIDDLEWARE. `bootstrap/app.php` registers it as the alias
 * `acting.player` and appends nothing to the global or `web` stacks: a route with
 * no `{game}` parameter has no Game_Id to resolve, and a global registration
 * would make `GET /` and `POST /join` throw the `LogicException` below on every
 * request.
 */
final class ResolveActingPlayer
{
    /**
     * The route parameter naming the Game. `{game}` in every route the design
     * lists — `GET /games/{game}`, `POST /games/{game}/moves`,
     * `POST /games/{game}/rematch` — so it is spelled once here rather than three
     * times in `routes/web.php`.
     */
    public const string ROUTE_PARAMETER = 'game';

    /**
     * Where the `ResolvedPlayer` is left for the controller.
     *
     * A REQUEST ATTRIBUTE, NOT A CONTAINER BINDING. Both work; the attribute is
     * better here on two counts. It is scoped to the request object, so it cannot
     * outlive the request or be seen by anything the request was not passed to,
     * whereas a `$container->instance(ResolvedPlayer::class, ...)` is
     * process-scoped state that happens to be reset between requests. And it
     * fails legibly: a controller on a route that forgot this middleware gets the
     * explicit `LogicException` from `resolved()` below, naming the mistake,
     * rather than a container resolution error about an unbindable constructor
     * argument.
     *
     * The value is namespaced under `game.` so it cannot collide with an
     * attribute the framework or another middleware sets.
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

        // The rejection branch reads nothing off the resolution and could not: a
        // `VisibilityOutcome` is an enum case with no fields, so there is no Game
        // here to be handed on by accident (Req 3.10).
        if ($resolution instanceof VisibilityOutcome) {
            throw new GameNotVisibleException($resolution);
        }

        $request->attributes->set(self::REQUEST_ATTRIBUTE, $resolution);

        return $next($request);
    }

    /**
     * The `ResolvedPlayer` this middleware established for `$request`.
     *
     * THE SEAM FOR TASKS 5.6, 6.2 AND 7.x. A controller on a game-scoped route
     * calls `ResolveActingPlayer::resolved($request)` and gets the Game row and
     * the acting Mark together — which is why no controller needs to type-hint
     * `App\Models\Game` and trip route-model binding, and why no controller has
     * to repeat the authorisation question.
     *
     * A typed accessor rather than a bare `$request->attributes->get(...)`,
     * because the bag is typed `mixed` and every call site would otherwise need
     * its own narrowing to satisfy static analysis — three call sites, three
     * chances to narrow it to something laxer than `ResolvedPlayer`.
     *
     * @throws LogicException if the route this request matched was not protected
     *                        by this middleware. That is a routing defect, not a
     *                        user error: an unprotected game-scoped route would
     *                        serve a Game to anyone, so it fails loudly as a 500
     *                        rather than being reported as one of the visibility
     *                        outcomes, which would make the mistake look like an
     *                        ordinary refusal.
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
     * `originalParameter()` and not `parameter()`: the former is the string the
     * URL carried, the latter is whatever route-model binding may have replaced
     * it with. See the class docblock — the whole visibility table depends on this
     * class being handed an id it can fail to find.
     *
     * @throws LogicException if the matched route declares no `{game}` parameter.
     *                        This middleware applies only to routes naming a Game
     *                        id, so its absence means it was registered somewhere
     *                        it does not belong — again a routing defect, and one
     *                        that must not be reported as `not_recognised`, which
     *                        would turn a misregistration into a plausible-looking
     *                        404 on every request to that route.
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
