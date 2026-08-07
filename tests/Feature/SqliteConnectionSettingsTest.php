<?php

use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;

/*
 * Task 1.4 / ADR-004. The four PRAGMAs are asserted against a temporary
 * file-backed database built from the application's own `sqlite` connection
 * config, because the suite itself runs on `:memory:`, where `journal_mode` is
 * always `memory` and WAL is unavailable. Cloning the config is what makes this
 * a check of config/database.php rather than of the probe.
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
 * re-reading config/session.php with both variables absent — which is also the
 * case that matters: a deployment without them must still get database sessions
 * with a 30-day lifetime.
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
                Env::getRepository()->set($name, $value);
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }

    $connection = $session['connection'] ?? config('database.default');

    expect($session['driver'])->toBe('database')
        ->and($session['lifetime'])->toBe(60 * 24 * 30)
        ->and(config("database.connections.{$connection}.driver"))->toBe('sqlite');
});
