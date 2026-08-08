<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

use function Pest\Laravel\get;

// Feature: remote-tic-tac-toe
//
// Validates: Requirements 10.1, 10.2
//
/*
 * `GET /health` and `HealthController`.
 *
 * The unreachable branch is driven by making a second sqlite connection the default
 * one, never by deleting or corrupting `database/database.sqlite`. `phpunit.xml` sets
 * `DB_DATABASE=:memory:`, so `DB::purge('sqlite')` would discard the schema
 * `RefreshDatabase` migrated and the transaction it holds open, and every later
 * assertion in the file would be about an empty database. Both replacement
 * connections are cloned from `config/database.php`'s `sqlite` entry, so the file is
 * the only thing that differs.
 *
 * Config is restored in a `finally` rather than left to the next test's fresh
 * application: `RefreshDatabase` rolls its transaction back against whatever
 * `database.default` names at teardown.
 */

uses(RefreshDatabase::class);

/**
 * Points the default connection at `$database` and returns the undo.
 *
 * @return Closure(): void
 */
function healthDefaultDatabaseBecomes(string $database): Closure
{
    $original = config('database.default');
    $sqlite = config('database.connections.sqlite');

    config([
        'database.connections.health_probe' => [
            ...(is_array($sqlite) ? $sqlite : []),
            'database' => $database,
        ],
        'database.default' => 'health_probe',
    ]);

    return function () use ($original): void {
        DB::purge('health_probe');
        config(['database.default' => $original]);
    };
}

/**
 * The names of every cookie a response sets.
 *
 * @param  TestResponse<SymfonyResponse>  $response
 * @return list<string>
 */
function healthCookieNames(TestResponse $response): array
{
    return array_values(array_map(
        static fn (Cookie $cookie): string => $cookie->getName(),
        $response->headers->getCookies(),
    ));
}

/*
 * Req 10.1: the reachable case, answered with a success status and a body reporting
 * the persistence layer reachable.
 *
 * The 1-second bound is asserted for the same reason the criterion states it — the
 * probe is a single read against an already-open connection, so the margin is three
 * orders of magnitude.
 */
it('answers a success status reporting the persistence layer reachable', function () {
    $started = microtime(true);
    $response = get('/health');
    $elapsed = microtime(true) - $started;

    $response->assertOk()->assertExactJson(['status' => 'ok', 'persistence' => 'reachable']);

    expect($response->isSuccessful())->toBeTrue('the Health_Endpoint did not answer a success status while the persistence layer was reachable (Req 10.1)')
        ->and($elapsed)->toBeLessThan(1.0, 'the Health_Endpoint took longer than the 1 second Requirement 10.1 allows');
});

/*
 * Req 10.2: a database file that cannot be opened.
 *
 * `SQLiteConnector::parseDatabasePath()` raises
 * `SQLiteDatabaseDoesNotExistException` before any statement is issued, which is why
 * `HealthController` catches `Throwable` and not `QueryException`.
 *
 * `isSuccessful()` rather than only `toBe(503)`, because Requirement 10.2's
 * reservation is about the STATUS class: a 200 carrying the error body would satisfy
 * an assertion naming 503 as the wrong answer while leaving every healthcheck in
 * front of it reporting the service up.
 */
it('answers 503 reporting the persistence layer unreachable when the database cannot be opened', function () {
    $restore = healthDefaultDatabaseBecomes(storage_path('app/health-test-no-such-directory/absent.sqlite'));

    try {
        $started = microtime(true);
        $response = get('/health');
        $elapsed = microtime(true) - $started;
    } finally {
        $restore();
    }

    expect($response->isSuccessful())->toBeFalse('the Health_Endpoint answered a success status for an unreachable persistence layer, which Requirement 10.2 reserves for the reachable case')
        ->and($response->getStatusCode())->toBe(503, 'the failure status is not the 503 the design records (Req 10.2)')
        ->and($response->getContent())->toBe('{"status":"error","persistence":"unreachable"}', 'the failure body is not the one the design records (Req 10.2)')
        ->and($elapsed)->toBeLessThan(1.0, 'the Health_Endpoint took longer than the 1 second Requirement 10.2 allows to report the persistence layer unreachable');

    // Non-vacuity: the 503 was the broken connection's doing and not the route's, so
    // the same request against the restored connection is a success again.
    get('/health')->assertOk();
});

/*
 * Req 10.2 for a file that opens but holds no schema, and the reason the probe names
 * a table.
 *
 * `select 1` references no table, so it succeeds here — asserted, so the distinction
 * is pinned rather than described. A probe written that way would report this
 * database reachable, which is the design's failure-table note made falsifiable.
 */
it('reports a schema-less database unreachable, where a select 1 probe would report it reachable', function () {
    $file = tempnam(sys_get_temp_dir(), 'health').'.sqlite';
    touch($file);

    $restore = healthDefaultDatabaseBecomes($file);

    try {
        expect(DB::connection('health_probe')->scalar('select 1'))->toBe(1, 'the schema-less database did not open at all, so this case is the unopenable one rather than the schema-less one')
            ->and(DB::connection('health_probe')->getSchemaBuilder()->hasTable('games'))->toBeFalse('the probe database has a games table, so it is not schema-less');

        $response = get('/health');
    } finally {
        $restore();

        foreach ([$file, $file.'-wal', $file.'-shm'] as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    expect($response->isSuccessful())->toBeFalse('a schema-less database was reported reachable, which is what a `select 1` probe would do (Req 10.1, 10.2)')
        ->and($response->getStatusCode())->toBe(503)
        ->and($response->getContent())->toBe('{"status":"error","persistence":"unreachable"}');
});

/*
 * The route carries no middleware, which is what holds the request to one query: the
 * `web` group would add the session store's own read and write to every probe, and
 * `SESSION_DRIVER=database` in the hosted environment makes those database
 * statements.
 *
 * Asserted on the resolved route's gathered middleware and on the response's cookies
 * together, because either alone is weak: the route table says nothing about what a
 * request actually ran, and an absent cookie could mean an absent session driver.
 * `GET /` is the control — it is in the `web` group, so it sets the cookie whose
 * absence is being claimed here.
 */
it('registers the health route with no middleware and sets no cookie', function () {
    $route = Route::getRoutes()->getByName('health');
    $sessionCookie = (string) config('session.cookie');

    expect($route)->not->toBeNull('there is no route named health, so nothing below is about the Health_Endpoint')
        ->and($route?->uri())->toBe('health')
        ->and($route?->gatherMiddleware())->toBe([], 'the Health_Endpoint carries route middleware, so it no longer performs one query per request (design section 3)')
        ->and($sessionCookie)->not->toBe('');

    $health = get('/health');
    $health->assertOk();

    // `str_contains`/`in_array` rather than `toContain()`, which takes variadic
    // needles and no message argument, so a message passed there is silently
    // asserted as a needle.
    expect(healthCookieNames($health))->toBe([], 'the Health_Endpoint set a cookie, so it ran the session middleware')
        ->and(in_array($sessionCookie, healthCookieNames(get('/')), true))->toBeTrue('no route in the web group sets the session cookie, so its absence at /health says nothing');
});

/*
 * One persistence query per request (Req 10.1), and it names a table.
 *
 * The query log is the only way to assert a count of statements. The `games`
 * fragment is the same claim the schema-less case makes from the outside; both are
 * kept because this one fails on the first line of a rewrite while the other fails
 * only once the behaviour has changed.
 */
it('issues exactly one persistence query per request, against a named table', function () {
    DB::enableQueryLog();
    DB::flushQueryLog();

    get('/health')->assertOk();

    $statements = array_map(
        static fn (array $entry): string => strtolower((string) $entry['query']),
        DB::getQueryLog(),
    );

    DB::disableQueryLog();

    expect($statements)->toHaveCount(1, 'the Health_Endpoint issued something other than one query: '.implode(' | ', $statements))
        ->and(str_contains($statements[0], 'games'))->toBeTrue("the probe references no named table, so an unreadable or schema-less database would report reachable: {$statements[0]}");
});
