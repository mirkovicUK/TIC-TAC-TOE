<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase as PHPUnitTestCase;

// Feature: remote-tic-tac-toe, Property 6: The domain layer is pure
//
// Validates: Requirements 11.1, 11.9, 14.1
//
/*
 * The mechanical half of Property 6. Two independent claims:
 *
 *   CLAIM 1 (Req 11.1, 11.9, ADR-003). Nothing in `App\Domain\TicTacToe` references
 *   the framework, the persistence layer or the transport layer. Scanned twice
 *   because there are two ways in: a NAME, arriving through a `use` statement, a
 *   qualified reference mid-expression or a docblock type; and a global HELPER
 *   FUNCTION, arriving through no import at all. Composer autoloads `now()`,
 *   `config()` and the rest into every file, so `now()` in `RulesEngine.php`
 *   compiles, reaches the container, and is invisible to an import-only check and
 *   equally invisible to a reflection-based dependency check, a function call not
 *   being a class dependency. The helper scan is the load-bearing half.
 *
 *   CLAIM 2 (Req 14.1). The domain unit tests exercise the engine without the
 *   persistence layer, the session or HTTP — under Pest, the class generated for
 *   each file extends a plain `PHPUnit\Framework\TestCase` and not `Tests\TestCase`,
 *   whose parent boots the application.
 *
 * A checker must name what it forbids, so this file contains `Illuminate\`, `now(`
 * and the rest. Claim 1 scans `app/Domain/` and never `tests/`, so this file is out
 * of its scope by construction. Claim 2 scans `tests/Unit/Domain/`, which does
 * contain it, so this one file is skipped by comparing `realpath()` against
 * `__FILE__` — an identity match on one path, which cannot quietly grow to cover a
 * real test file the way a basename or directory exclusion could.
 *
 * Both scans walk their tree rather than reading a list, so a domain class or test
 * added later is covered. The cost is that a broken glob passes everything
 * vacuously, so each scan asserts a floor on what it found and that every file found
 * is what it was expected to be.
 */

/**
 * The repository root, without `base_path()` — that helper is one of the things this
 * file forbids, and reaching for it would boot the container it checks for the
 * absence of.
 *
 * `tests/Unit/Domain` → three levels up.
 */
function purityRepositoryRoot(): string
{
    return dirname(__DIR__, 3);
}

/**
 * @return list<string> Every `.php` file under `$directory`, sorted, absolute.
 */
function purityPhpFilesUnder(string $directory): array
{
    $found = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $found[] = (string) $file->getRealPath();
        }
    }

    sort($found);

    return $found;
}

/**
 * @return list<string>
 */
function purityDomainFiles(): array
{
    return purityPhpFilesUnder(purityRepositoryRoot().'/app/Domain');
}

/**
 * Every `.php` file under `tests/Unit/Domain` except this one, excluded by identity
 * against `__FILE__` so the exclusion cannot widen. See the head of this file.
 *
 * @return list<string>
 */
function purityDomainTestFiles(): array
{
    $checker = (string) realpath(__FILE__);

    return array_values(array_filter(
        purityPhpFilesUnder(purityRepositoryRoot().'/tests/Unit/Domain'),
        static fn (string $path): bool => $path !== $checker,
    ));
}

function purityRelativePath(string $path): string
{
    $root = purityRepositoryRoot().'/';

    return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
}

/**
 * Namespace prefixes no file in the domain layer may name, in any form.
 *
 * @return list<string>
 */
function purityForbiddenNamespaces(): array
{
    return ['Illuminate\\', 'App\\Models\\', 'App\\Http\\'];
}

/**
 * Laravel's global helper functions. Each is autoloaded, needs no import, and reaches
 * the container, request, session, filesystem or configuration, so one call makes
 * Requirement 11.9 false while every `use` statement in the file stays innocent.
 *
 * @return list<string>
 */
function purityFrameworkHelpers(): array
{
    return [
        'app', 'config', 'now', 'collect', 'resolve', 'event', 'logger', 'report',
        'abort', 'session', 'request', 'response', 'redirect', 'view', 'cache',
        'dispatch', 'validator', 'auth', 'route', 'url', 'old', 'env',
        'base_path', 'storage_path', 'database_path', 'config_path',
        'resource_path', 'public_path', 'app_path', 'trans', '__',
    ];
}

/**
 * Line-by-line, so a violation can be reported with the line that caused it.
 *
 * A docblock type counts as a reference for Requirement 11.9, being a claim that the
 * domain knows about that type. Matching is case-insensitive because PHP resolves
 * namespaces case-insensitively, so `illuminate\support\str` names the same class.
 *
 * @return list<string>
 */
function purityForbiddenNamespaceReferencesIn(string $path): array
{
    $violations = [];
    $relative = purityRelativePath($path);

    foreach (explode("\n", (string) file_get_contents($path)) as $index => $line) {
        foreach (purityForbiddenNamespaces() as $forbidden) {
            if (stripos($line, $forbidden) !== false) {
                $violations[] = sprintf(
                    '%s:%d references %s — %s',
                    $relative,
                    $index + 1,
                    $forbidden,
                    trim($line),
                );
            }
        }
    }

    return $violations;
}

/**
 * The helper-call scan, over PHP's own tokens rather than raw text, which settles
 * every word-boundary case a substring search gets wrong:
 *
 *   - `$this->config(...)`, `Foo::config(...)` and `$foo?->now(...)` are method
 *     calls; the preceding significant token is checked, so they are not flagged.
 *     Nor is `public function config(...)`, preceded by `T_FUNCTION`, or
 *     `new now(...)`, preceded by `T_NEW`.
 *   - `dispatchSomething(`, `resolveConflict(` and `somethingConfig(` are single
 *     `T_STRING` tokens compared whole, so nothing matches inside an identifier.
 *   - `\now(...)` arrives as one `T_NAME_FULLY_QUALIFIED` token, so the leading
 *     separator is stripped before comparison.
 *   - Comments and docblocks are ignorable tokens and are dropped: prose cannot call
 *     anything. The name scan above reads comments because a docblock type is a
 *     reference.
 *
 * A name must also be immediately followed by `(` to count, which makes this a check
 * for calls rather than for the appearance of a common English word.
 *
 * @return list<string>
 */
function purityFrameworkHelperCallsIn(string $path): array
{
    /** @var array<string, true> $forbidden */
    $forbidden = array_fill_keys(purityFrameworkHelpers(), true);

    $significant = array_values(array_filter(
        PhpToken::tokenize((string) file_get_contents($path)),
        static fn (PhpToken $token): bool => ! $token->isIgnorable(),
    ));

    $violations = [];
    $relative = purityRelativePath($path);

    foreach ($significant as $position => $token) {
        if (! $token->is([T_STRING, T_NAME_FULLY_QUALIFIED])) {
            continue;
        }

        $name = strtolower(ltrim($token->text, '\\'));

        if (! isset($forbidden[$name])) {
            continue;
        }

        $next = $significant[$position + 1] ?? null;

        if ($next === null || ! $next->is('(')) {
            continue;
        }

        $previous = $significant[$position - 1] ?? null;

        if ($previous !== null && $previous->is([
            T_OBJECT_OPERATOR,
            T_NULLSAFE_OBJECT_OPERATOR,
            T_DOUBLE_COLON,
            T_FUNCTION,
            T_NEW,
        ])) {
            continue;
        }

        $violations[] = sprintf(
            '%s:%d calls the framework helper %s()',
            $relative,
            $token->line,
            $token->text,
        );
    }

    return $violations;
}

/**
 * Framework test-harness names a domain unit test file may not contain. The first
 * three boot the application; the traits are the usual way a Pest file acquires a
 * database without naming a base class at all.
 *
 * @return list<string>
 */
function purityForbiddenTestHarnessReferences(): array
{
    return [
        'Tests\\TestCase',
        'Illuminate\\',
        'App\\Models\\',
        'App\\Http\\',
        'RefreshDatabase',
        'DatabaseMigrations',
        'DatabaseTransactions',
    ];
}

/**
 * The file's code with every comment and docblock removed, one token per line.
 *
 * Needed because the sibling domain tests discuss what Claim 2 forbids:
 * `RulesEngineTest` carries a header comment saying there is no
 * `uses(Tests\TestCase::class)` in it, which a raw substring search would report as a
 * violation. A docblock can name a type but cannot make a call or invoke `uses()`,
 * which is why this scan reads code only while the name scan above reads comments too.
 *
 * Tokens are joined with newlines rather than concatenated, so a qualified name such
 * as `Tests\TestCase` — one token — stays intact while two adjacent tokens cannot be
 * run together into a name nobody wrote.
 */
function purityCodeWithoutComments(string $contents): string
{
    $code = [];

    foreach (PhpToken::tokenize($contents) as $token) {
        if (! $token->isIgnorable()) {
            $code[] = $token->text;
        }
    }

    return implode("\n", $code);
}

/**
 * @param  list<string>  $violations
 */
function purityViolationReport(string $claim, array $violations): string
{
    return $violations === []
        ? ''
        : sprintf("%s\n  %s", $claim, implode("\n  ", $violations));
}

/*
 * Discovery first: everything below asserts an absence, and an absence over an empty
 * set of files is free. This is what makes the other four tests mean anything.
 *
 * The floor is nine because the domain layer is nine files — Mark, Move, MoveList,
 * WinningLine, Board, Outcome, Analysis, InvalidMoveList, RulesEngine. A floor and not
 * an equality, so adding a tenth type does not fail a purity test for a reason
 * unrelated to purity.
 */
it('discovers the domain source files it makes its claims about', function () {
    $files = purityDomainFiles();

    expect(count($files))->toBeGreaterThanOrEqual(
        9,
        'discovery found '.count($files).' files under app/Domain; the domain layer has at least nine, so the scans below would be vacuous',
    );

    // Second half of discovery: a scan pointed at the wrong tree could still find
    // nine things.
    $strays = [];

    foreach ($files as $file) {
        if (! str_contains((string) file_get_contents($file), 'namespace App\Domain\TicTacToe')) {
            $strays[] = purityRelativePath($file).' does not declare the domain namespace';
        }
    }

    expect($strays)->toBe([], purityViolationReport(
        'Every file discovered under app/Domain must be part of the domain namespace, or discovery is looking at the wrong tree. Found:',
        $strays,
    ));
});

/*
 * CLAIM 1, first half: names.
 */
it('references no framework, persistence or transport namespace anywhere in the domain layer', function () {
    $violations = [];

    foreach (purityDomainFiles() as $file) {
        foreach (purityForbiddenNamespaceReferencesIn($file) as $violation) {
            $violations[] = $violation;
        }
    }

    expect($violations)->toBe([], purityViolationReport(
        'The domain layer must name no Illuminate, App\Models or App\Http type (Req 11.1, 11.9). Found:',
        $violations,
    ));
});

/*
 * CLAIM 1, second half: calls that need no name, which an import-based or
 * reflection-based check cannot see. `session()` reaches the persistence layer, the
 * session and the transport layer without a `use` statement (Req 11.9).
 */
it('calls no autoloaded framework helper function anywhere in the domain layer', function () {
    $violations = [];

    foreach (purityDomainFiles() as $file) {
        foreach (purityFrameworkHelperCallsIn($file) as $violation) {
            $violations[] = $violation;
        }
    }

    expect($violations)->toBe([], purityViolationReport(
        'The domain layer must call no global framework helper — these need no import, so no use-statement check would catch them (Req 11.9). Found:',
        $violations,
    ));
});

/*
 * CLAIM 2, by reflection.
 *
 * Pest compiles each test file into a class named `P\Tests\Unit\Domain\<FileName>`,
 * created when the file runs rather than by an autoloader. Reflecting on a sibling's
 * generated class therefore only works if that sibling ran earlier in the same
 * process, which depends on run order, so sibling reflection is used for whatever is
 * already loaded and never as the basis of the claim.
 *
 * What carries the claim is that this file is one of the files the claim is about: it
 * sits in `tests/Unit/Domain`, so Pest picks its base class by the same global
 * configuration as its siblings. `tests/Pest.php` scopes its one `uses()` directive to
 * `Feature`, leaving `Unit` on the default `PHPUnit\Framework\TestCase`. Widen that
 * directive to `Unit`, or drop the `->in()` so it applies globally, and every file
 * here would start booting the application — including this one, and this assertion is
 * what fails. `tests/Pest.php` carries the matching warning where that edit would be
 * made.
 *
 * Not covered: a per-file opt-in inside one sibling, such as a `uses()` call or an
 * `extends`. The static scan in the next test covers that, and the two together are
 * the claim.
 */
it('runs on a plain PHPUnit test case, booting no framework', function () {
    $generated = array_values(array_filter(
        get_declared_classes(),
        static fn (string $class): bool => str_starts_with($class, 'P\\Tests\\Unit\\Domain\\'),
    ));

    // Never empty: this test is running, so the class Pest generated for this file is
    // among the declared ones. Located by the file's basename rather than by
    // `static::class`, which PHPStan cannot type inside a Pest closure.
    $own = array_values(array_filter(
        $generated,
        static fn (string $class): bool => str_ends_with($class, '\\'.basename(__FILE__, '.php')),
    ));

    expect($own)->not->toBeEmpty(
        'the class Pest generates for this very file was not found among ['.implode(', ', $generated).'], so the ancestry check below would assert nothing',
    );

    $bootsFramework = ['Tests\TestCase', 'Illuminate\Foundation\Testing\TestCase'];

    $violations = [];

    foreach ($generated as $class) {
        $ancestry = array_values((array) class_parents($class));

        if (! in_array(PHPUnitTestCase::class, $ancestry, true)) {
            $violations[] = sprintf(
                '%s does not extend %s; its ancestry is [%s]',
                $class,
                PHPUnitTestCase::class,
                implode(', ', $ancestry),
            );
        }

        foreach ($bootsFramework as $booting) {
            if (in_array($booting, $ancestry, true)) {
                $violations[] = sprintf('%s extends %s, which boots the application', $class, $booting);
            }
        }
    }

    expect($violations)->toBe([], purityViolationReport(
        'A domain unit test must run on a plain PHPUnit test case (Req 14.1). Found:',
        $violations,
    ));

    // Non-vacuity for the two exclusions: a typo in either name would exclude nothing
    // and pass forever. `Tests\TestCase` exists here and its own parent is the
    // framework test case, which is why extending it is what gets ruled out.
    expect(class_exists('Tests\TestCase'))->toBeTrue('the excluded base class must exist, or the exclusion asserts nothing')
        ->and(array_values((array) class_parents('Tests\TestCase')))
        ->toContain('Illuminate\Foundation\Testing\TestCase');
});

/*
 * CLAIM 2, by inspection — the per-file half.
 *
 * `uses(Tests\TestCase::class)` in any one of these files would swap that file's base
 * class on its own, invisibly to the reflection above. So would a `RefreshDatabase`
 * trait, or a framework helper call in a test body, which is how a domain unit test
 * acquires a database or a session without naming a base class at all.
 *
 * This file is excluded by identity against `__FILE__` and nothing broader, because it
 * is where the forbidden names are written down.
 */
it('boots no framework in any domain unit test file', function () {
    $files = purityDomainTestFiles();

    // Three siblings today: RulesEngineTest, IllFormedMoveListTest, EnumerationTest,
    // plus Support/LineOracle. A floor, so adding a domain test does not fail this;
    // not zero, so a broken scan cannot pass it.
    expect(count($files))->toBeGreaterThanOrEqual(
        3,
        'discovery found '.count($files).' domain test files besides this one; the scan below would be vacuous',
    );

    // The skip is exactly one file and it is this one, asserted rather than claimed in
    // a comment.
    expect(purityPhpFilesUnder(purityRepositoryRoot().'/tests/Unit/Domain'))
        ->toHaveCount(count($files) + 1)
        ->toContain((string) realpath(__FILE__));

    $violations = [];

    foreach ($files as $file) {
        $relative = purityRelativePath($file);
        $code = purityCodeWithoutComments((string) file_get_contents($file));

        foreach (purityForbiddenTestHarnessReferences() as $forbidden) {
            if (str_contains($code, $forbidden)) {
                $violations[] = sprintf('%s references %s', $relative, $forbidden);
            }
        }

        if (preg_match('/\buses\s*\(/', $code) === 1) {
            $violations[] = sprintf(
                '%s calls uses(), which can replace the base test case for that file alone',
                $relative,
            );
        }

        foreach (purityFrameworkHelperCallsIn($file) as $violation) {
            $violations[] = $violation;
        }
    }

    expect($violations)->toBe([], purityViolationReport(
        'A domain unit test must reach neither persistence, session nor HTTP (Req 14.1). Found:',
        $violations,
    ));
});
