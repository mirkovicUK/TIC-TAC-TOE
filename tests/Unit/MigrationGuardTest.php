<?php

declare(strict_types=1);

/**
 * `scripts/check-migrations.php` against a fixture of every shape it is meant to reject
 * and every shape it must not (Req 8.3).
 *
 * WHY THIS TEST IS NOT OPTIONAL. This repository has one recorded incident of a check
 * that could not fail: seventeen assertions written as `toContain()` against a value
 * that always contained the substring, so they passed regardless of the behaviour. A
 * migration guard is exactly the same hazard — it runs on every push, it is silent when
 * it is happy, and nobody notices a guard that has stopped guarding. So the rejections
 * are asserted individually, by message, and so are the two shapes that must pass.
 *
 * No framework here. The script shells out and reads nothing but files, so booting
 * Laravel would only slow it down; `tests/Pest.php` scopes `TestCase` to Feature and
 * Browser, which leaves this in the fast path.
 *
 * @param  string  $case  a directory under tests/Fixtures/migrations
 * @return array{int, string} the exit code and the combined output
 */
function runMigrationGuard(string $case): array
{
    $root = dirname(__DIR__, 2);
    $command = sprintf(
        '%s %s %s 2>&1',
        escapeshellarg(PHP_BINARY),
        escapeshellarg($root.'/scripts/check-migrations.php'),
        escapeshellarg($root.'/tests/Fixtures/migrations/'.$case),
    );

    $output = [];
    $status = 0;
    exec($command, $output, $status);

    return [$status, implode("\n", $output)];
}

it('rejects a column dropped in up()', function () {
    [$status, $output] = runMigrationGuard('drop_column');

    expect($status)->toBe(1)
        ->and($output)->toContain('dropColumn()')
        ->and($output)->toContain('1 failing');
});

it('rejects a column renamed in up()', function () {
    [$status, $output] = runMigrationGuard('rename_column');

    expect($status)->toBe(1)
        ->and($output)->toContain('renameColumn()');
});

it('rejects an existing column altered in place in up()', function () {
    [$status, $output] = runMigrationGuard('change_column');

    expect($status)->toBe(1)
        ->and($output)->toContain('change()');
});

it('rejects a migration touching two tables even when both operations are additive', function () {
    [$status, $output] = runMigrationGuard('two_tables');

    expect($status)->toBe(1)
        ->and($output)->toContain('touches 2 tables')
        ->and($output)->toContain('games')
        ->and($output)->toContain('moves');
})->note('Both operations add a nullable column. The objection is that a failure between them can be neither rolled back nor re-run, because SQLite gives a migration no transaction.');

it('rejects destruction written as raw SQL rather than through the Blueprint', function () {
    [$status, $output] = runMigrationGuard('raw_drop');

    expect($status)->toBe(1)
        ->and($output)->toContain('destructive raw SQL');
})->note('This project writes its real tables with DB::statement, so a rule that only inspected Blueprint calls would miss the commonest place a DROP could appear here.');

it('accepts one nullable column added to one table', function () {
    [$status, $output] = runMigrationGuard('additive');

    expect($status)->toBe(0)
        ->and($output)->toContain('0 failing');
})->note('The rejections above prove the guard can fail; this proves it can pass. A guard that rejects everything gets switched off, which is the same outcome as having none.');

it('accepts a destructive down() beside an additive up()', function () {
    [$status, $output] = runMigrationGuard('destructive_down');

    expect($status)->toBe(0)
        ->and($output)->toContain('0 failing');
})->note('Every real migration in this project ends with Schema::dropIfExists() in down(). A checker scanning whole files rather than the up() body would reject all six. That fixture also names dropColumn and DROP TABLE inside a comment, so it fails any grep-based implementation.');

it('accepts the migrations actually in this repository', function () {
    $root = dirname(__DIR__, 2);
    $output = [];
    $status = 0;
    exec(sprintf(
        '%s %s %s 2>&1',
        escapeshellarg(PHP_BINARY),
        escapeshellarg($root.'/scripts/check-migrations.php'),
        escapeshellarg($root.'/database/migrations'),
    ), $output, $status);

    expect($status)->toBe(0)
        ->and(implode("\n", $output))->toContain('6 migration(s) checked, 0 failing');
})->note('Three of the six create several tables in one up(). They are Laravel scaffold, already applied on every database this project has, and named in MULTI_TABLE_EXEMPT — rewriting applied history to satisfy a rule added afterwards is the more dangerous change.');

it('reports a usage error rather than passing when given a directory with no migrations', function () {
    [$status, $output] = runMigrationGuard('.');

    expect($status)->toBe(2)
        ->and($output)->toContain('no migrations found');
})->note('Exit 2, distinct from 1. A guard pointed at the wrong path must not report success, which is how a CI step silently stops checking anything.');
