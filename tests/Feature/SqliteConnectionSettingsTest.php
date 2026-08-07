<?php

use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;

/*
 * ADR-004. The four PRAGMAs are asserted against a temporary file-backed database
 * cloned from the application's own `sqlite` connection config, because the suite
 * runs on `:memory:`, where `journal_mode` is always `memory` and WAL unavailable.
 * Cloning the config is what makes this a check of config/database.php.
 */
it('applies the configured pragmas to a sqlite connection', function () {
    $file = tempnam(sys_get_temp_dir(), 'pragma').'.sqlite';
    touch($file);

    config([
        'database.connections.pragma_probe' => [
            ...config('database.connections.sqlite'),
            'database' => $file,
        ],
    ]);

    try {
        $connection = DB::connection('pragma_probe');

        expect($connection->scalar('PRAGMA foreign_keys'))->toBe(1)
            ->and(strtolower((string) $connection->scalar('PRAGMA journal_mode')))->toBe('wal')
            ->and($connection->scalar('PRAGMA synchronous'))->toBe(1)
            ->and($connection->scalar('PRAGMA busy_timeout'))->toBe(5000);
    } finally {
        DB::purge('pragma_probe');

        foreach ([$file, $file.'-wal', $file.'-shm'] as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }
});

/*
 * The suite forces SESSION_DRIVER=array, so the shipped defaults are asserted by
 * re-reading config/session.php with both variables absent — also the case that
 * matters: a deployment without them must still get database sessions with a 30-day
 * lifetime.
 */
it('defaults to database sessions in the default sqlite file with a 30 day lifetime', function () {
    $names = ['SESSION_DRIVER', 'SESSION_LIFETIME'];
    $originals = [];

    foreach ($names as $name) {
        $originals[$name] = Env::getRepository()->get($name);
        Env::getRepository()->clear($name);
        unset($_ENV[$name], $_SERVER[$name]);
        putenv($name);
    }

    try {
        $session = require config_path('session.php');
    } finally {
        foreach ($originals as $name => $value) {
            if ($value !== null) {
                // Restored through the raw stores, not `Env::getRepository()->set()`, which
                // leaked the value into every later test. `set()` goes through dotenv's
                // `ImmutableWriter`, which records the name in its `$loaded` registry — the
                // registry that tells the writer a variable came from `.env` rather than the
                // environment. `Env::getRepository()` is memoized for the process, so the
                // registry outlives this test and the next test's `LoadEnvironmentVariables`
                // is then permitted to overwrite `SESSION_DRIVER` with `.env`'s `database`,
                // discarding the `array` driver `phpunit.xml` sets for the rest of the run.
                //
                // That made the suite order-dependent: `ConcurrencyTest` suspends and resumes
                // a Player_Session, which `ArraySessionHandler` supports and
                // `DatabaseSessionHandler` cannot within one process, so it passed only
                // because `C` sorts before `S`. Writing `$_ENV` and `putenv` directly
                // restores what the reads see while leaving the registry untouched.
                $_ENV[$name] = $value;
                putenv("{$name}={$value}");
            }
        }
    }

    $connection = $session['connection'] ?? config('database.default');

    expect($session['driver'])->toBe('database')
        ->and($session['lifetime'])->toBe(60 * 24 * 30)
        ->and(config("database.connections.{$connection}.driver"))->toBe('sqlite');
});
