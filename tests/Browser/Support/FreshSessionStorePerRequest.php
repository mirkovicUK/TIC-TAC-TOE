<?php

declare(strict_types=1);

namespace Tests\Browser\Support;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives each request its own session Store and handler, so two browsers driven by
 * `PlayAGameTest` are two Player_Sessions rather than one.
 *
 * Test-only, and needed only because the browser plugin serves every request from one
 * booted application (`vendor/pestphp/pest-plugin-browser/src/Drivers/LaravelHttpServer.php`).
 * `SessionManager` caches the driver it builds, so without this two things carry across
 * requests: `Store::loadSession()` MERGES the handler's data into the attributes already
 * present — `array_replace($this->attributes, $this->readFromHandler())`,
 * `vendor/laravel/framework/src/Illuminate/Session/Store.php:116` — and
 * `DatabaseSessionHandler::$exists` latches true after the first write
 * (`.../Session/DatabaseSessionHandler.php:144`), after which every later session is
 * UPDATEd by id and never INSERTed. Under php-fpm one process serves one request and
 * neither arises.
 *
 * Register it as GLOBAL middleware, not in the `web` group: it has to run before
 * `StartSession` resolves the driver, and by then the preceding request has already been
 * terminated and saved.
 */
final readonly class FreshSessionStorePerRequest
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        Session::forgetDrivers();

        return $next($request);
    }
}
