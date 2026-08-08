<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * `GET /health` — reports whether the persistence layer is reachable (Req 10.1, 10.2).
 *
 * Plain JSON rather than an Inertia response: the caller is a healthcheck, not a
 * browser. The route carries no middleware at all, which is what holds the request to
 * one query — `SESSION_DRIVER=database`, so the `web` group would add the session
 * store's own reads and writes to every probe. It is registered in the `then` callback
 * of `withRouting()` in `bootstrap/app.php` for that reason; a definition in
 * `routes/web.php` would take the `web` group.
 *
 * The probe names a real table on purpose. `select 1` references none, so SQLite
 * answers it without opening a read transaction on the database file, and it would
 * report an unreadable or schema-less database as reachable.
 *
 * `Throwable`, not `QueryException`: a missing database file surfaces as
 * `Illuminate\Database\SQLiteDatabaseDoesNotExistException`, an
 * `InvalidArgumentException` raised by `SQLiteConnector::parseDatabasePath()` before
 * any query is issued. Narrowing the catch turns the commonest real failure into a 500.
 *
 * On the 1-second bound: `busy_timeout` is 5 s (`config/database.php`), but it only
 * applies where SQLite answers SQLITE_BUSY, and under the WAL journal mode set on the
 * same connection a reader takes its read mark without waiting on a writer. Hence a
 * read-only probe rather than a shorter timeout, which would cost either a second
 * statement — breaking the one-query rule — or a second connection, which under the
 * `:memory:` test configuration would be a different, empty database.
 */
final class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            // The value is discarded: reachability is established by the statement
            // returning at all, so an empty `games` table is as good an answer as a
            // full one.
            DB::table('games')->exists();
        } catch (Throwable) {
            return response()->json([
                'status' => 'error',
                'persistence' => 'unreachable',
            ], 503);
        }

        // Reached only after the probe has returned, which is how Requirement 10.2's
        // reservation of the success status is kept structurally rather than by a
        // status variable that some later branch could leave at 200.
        return response()->json([
            'status' => 'ok',
            'persistence' => 'reachable',
        ]);
    }
}
