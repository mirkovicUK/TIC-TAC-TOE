<?php

use Tests\TestCase;

/*
 * Scoped to `Feature` on purpose: Req 14.1 and Req 11.9 require the
 * domain unit tests to run with no booted framework, so `->in('Unit')` or a bare
 * global `uses(TestCase::class)` would undo it. `tests/Unit/Domain/ArchitectureTest.php`
 * guards this, and only from inside `tests/Unit/Domain/`.
 */

uses(TestCase::class)->in('Feature');

/*
 * `Browser` needs the same booted framework: the plugin's in-process HTTP server
 * calls `test()->prepareCookiesForRequest()` and `test()->serverVariables()`
 * (`vendor/pestphp/pest-plugin-browser/src/Drivers/LaravelHttpServer.php`), both of
 * which come from Laravel's `TestCase`.
 */
uses(TestCase::class)->in('Browser');
