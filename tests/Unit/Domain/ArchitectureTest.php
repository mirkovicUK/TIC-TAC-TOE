<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase as PHPUnitTestCase;

// Feature: remote-tic-tac-toe, Property 6: The domain layer is pure
//
// Validates: Requirements 11.1, 11.9, 14.1
//
/*
 * Task 3.7 — the mechanical half of Property 6.
 *
 * Two claims, and they are independent of one another:
 *
 *   CLAIM 1 (Req 11.1, 11.9, ADR-003). Nothing in `App\Domain\TicTacToe`
 *   references the framework, the persistence layer or the transport layer.
 *   Asserted twice over, because there are two ways in: a NAME, which arrives
 *   through a `use` statement, a fully qualified reference mid-expression or a
 *   docblock type; and a global HELPER FUNCTION, which arrives through no import
 *   at all. Composer autoloads `now()`, `config()` and the rest into every file
 *   in the project, so `now()` in `RulesEngine.php` compiles, runs, reaches for
 *   the container and appears in no `use` statement — invisible to an
 *   import-only check and equally invisible to a reflection-based dependency
 *   check, since a function call is not a class dependency. The helper scan is
 *   therefore the load-bearing half, not the belt-and-braces half.
 *
 *   CLAIM 2 (Req 14.1). The domain unit tests exercise the engine without the
 *   persistence layer, the session or HTTP — which under Pest means the class
 *   generated for each file extends a plain `PHPUnit\Framework\TestCase` and not
 *   `Tests\TestCase`, whose parent boots the application.
 *
 * WHY THIS TEST DOES NOT FLAG ITSELF, AND WHY THAT IS NOT A LOOPHOLE. A checker
 * must name what it forbids, so this file necessarily contains the strings
 * `Illuminate\`, `now(`, `config(` and the rest. Two narrow arrangements keep
 * that from registering as an offence, and both are chosen to be as small as
 * possible:
 *
 *   - Claim 1 scans `app/Domain/` and nothing else. It never looks at `tests/`,
 *     so this file is outside its scope by construction rather than by
 *     exemption. There is no exclusion list to widen.
 *   - Claim 2 scans `tests/Unit/Domain/`, which does contain this file, so this
 *     one file is skipped — by comparing `realpath()` against `__FILE__`, an
 *     identity match on exactly one path. A basename or directory exclusion
 *     could quietly grow to cover a real test file; an identity match on
 *     `__FILE__` cannot cover anything but the file it is written in. The
 *     discovery test below also asserts that this file is one of the files
 *     discovered, so the skip is visible in the count rather than hidden by it.
 *
 * DISCOVERY, NOT A LIST. Both scans walk their directory tree, so a domain class
 * or a domain test added next month is covered without anyone remembering to add
 * it here. The cost of discovery is that a broken glob passes everything
 * vacuously, which is the same failure mode as a property test that generates
 * nothing — so both scans assert a floor on what they found and that each file
 * found is what it was expected to be.
 *
 * No framework boot here either: plain Pest functions under tests/Unit, so the
 * generated test class extends PHPUnit\Framework\TestCase. This file is one of
 * the files Claim 2 makes its claim about, which is what makes the ancestry
 * assertion below evidence rather than decoration.
 */

/**
 * The repository root, without `base_path()` — that helper is one of the things
 * this file exists to forbid, and reaching for it here would mean the checker
 * boots the container it is checking for the absence of.
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
 * Every `.php` file under `tests/Unit/Domain` EXCEPT this one.
 *
 * The exclusion is an identity match against `__FILE__`: it removes exactly the
 * file the forbidden names are written in and cannot, by construction, remove
 * any other. See the note at the head of this file.
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
 * Laravel's global helper functions. Every one of these is autoloaded, needs no
 * import, and reaches the container, the request, the session, the filesystem or
 * the configuration — so a single call is enough to make Requirement 11.9 false
 * while leaving every `use` statement in the file innocent.
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
 * Deliberately over-inclusive about *where* the name appears: a docblock type is
 * a reference for the purposes of Requirement 11.9, because it is a claim that
 * the domain knows about that type. Matching is case-insensitive, since PHP
 * resolves namespaces case-insensitively and `illuminate\support\str` names the
 * same class.
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
 * The helper-call scan, over PHP's own tokens rather than over raw text.
 *
 * THIS IS HOW WORD BOUNDARIES ARE HANDLED, and the tokeniser settles every case
 * a substring search gets wrong, without a hand-written boundary rule to get
 * subtly wrong in turn:
 *
 *   - `$this->config(...)`, `Foo::config(...)` and `$foo?->now(...)` are method
 *     calls, not helper calls. The preceding significant token is checked, so
 *     they are not flagged.
 *   - `dispatchSomething(` and `resolveConflict(` are single `T_STRING` tokens
 *     that do not equal any forbidden name, so they cannot match. Neither can
 *     `somethingConfig(` — a token is compared whole, so there is no such thing
 *     here as a match in the middle of an identifier.
 *   - `public function config(...)` DECLARES a method; the preceding token is
 *     `T_FUNCTION`, so it is not flagged. A domain class is free to have a
 *     method named after a helper. Calling it on `$this` is a method call, which
 *     the first rule already allows.
 *   - `\now(...)` — the fully qualified form of the same global helper — arrives
 *     as one `T_NAME_FULLY_QUALIFIED` token, so the leading separator is
 *     stripped before comparison. A substring search for `now(` would miss the
 *     backslash form's intent and a naive `\b` regex would match it as if
 *     unqualified; both happen to give the right verdict here, but only by
 *     accident.
 *   - Comments and docblocks are ignorable tokens and are dropped. Prose
 *     mentioning `now()` in a docblock is not a call. Names are matched in
 *     comments (above) because a docblock type is a reference; helper CALLS are
 *     not, because prose cannot call anything.
 *   - `new now(...)` instantiates a class, so `T_NEW` is excluded too.
 *
 * A name must also be immediately followed by `(` to count, which is what makes
 * this a check for *calls* rather than for the appearance of a common English
 * word.
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
 * Framework test-harness names a domain unit test file may not contain. The
 * first three boot the application; the traits are the usual way a Pest file
 * acquires a database without naming a base class at all.
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
 * Needed because the sibling domain tests DISCUSS the things Claim 2 forbids:
 * `RulesEngineTest` carries a header comment saying there is no
 * `uses(Tests\TestCase::class)` in it, which is true, and which a raw substring
 * search would report as a violation. Prose cannot opt a file into a base class,
 * so the per-file scan reads code only.
 *
 * The domain-name scan above deliberately does the opposite and reads comments
 * too, because a docblock type there is a genuine reference to a framework type.
 * The asymmetry is intentional: a docblock can name a type, but it cannot make a
 * call and it cannot invoke `uses()`.
 *
 * Tokens are joined with newlines rather than concatenated, so a qualified name
 * such as `Tests\TestCase` — a single token — stays intact while two adjacent
 * tokens cannot be run together into a name that was never written.
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
 * Discovery first. Everything below asserts the absence of something, and an
 * assertion about the absence of something in an empty set of files is free.
 * This is the test that makes the other four mean anything.
 *
 * The floor is nine because the design's domain layer is nine files — Mark,
 * Move, MoveList, WinningLine, Board, Outcome, Analysis, InvalidMoveList,
 * RulesEngine. It is a floor and not an equality so that adding a tenth domain
 * type does not fail a test about purity for a reason that has nothing to do
 * with purity.
 */
it('discovers the domain source files it makes its claims about', function () {
    $files = purityDomainFiles();

    expect(count($files))->toBeGreaterThanOrEqual(
        9,
        'discovery found '.count($files).' files under app/Domain; the domain layer has at least nine, so the scans below would be vacuous',
    );

    // Each file found really is a domain file. This is the second half of the
    // discovery check: a scan pointed at the wrong tree could still find nine
    // things.
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
 * CLAIM 1, second half: calls that need no name.
 *
 * The one an import-based or reflection-based check cannot see. Requirement 11.9
 * says the engine operates without access to the persistence layer, the session
 * or the transport layer, and `session()` gets to all three without a `use`
 * statement.
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
 * WHICH APPROACH, AND WHY. Pest compiles each test file into a class named
 * `P\Tests\Unit\Domain\<FileName>`, and that class is created when the file is
 * run — not by an autoloader. Reflecting on a SIBLING file's generated class
 * therefore only works if that sibling happens to have run earlier in the same
 * process: reachable when the whole directory runs and this file runs last,
 * missing when this file runs alone or first, which is what `ArchitectureTest`
 * sorting before `EnumerationTest` guarantees under a full-suite run. A check
 * that is order-dependent is not a check, so sibling reflection is used only for
 * whatever is already loaded, and never as the basis of the claim.
 *
 * What carries the claim instead is that THIS FILE IS ONE OF THE FILES THE CLAIM
 * IS ABOUT. It sits in `tests/Unit/Domain`, so Pest picks its base class by
 * exactly the process it uses for its three siblings — the project's Pest
 * configuration, which is global. `tests/Pest.php` scopes its one `uses()`
 * directive to `Feature`, so `Unit` is left on the default
 * `PHPUnit\Framework\TestCase` — the base class here follows from that scoping,
 * not from the config file being absent. Widen the directive to `Unit`, or drop
 * the `->in()` so it applies globally, and every file in this directory would
 * start booting the application — including this one, and this assertion is what
 * would fail. That is the mechanism by which Claim 2 could break without anyone
 * touching a domain test file, and reflecting on this file's own generated class
 * covers it. `tests/Pest.php` carries the matching warning at the point where
 * the edit would be made.
 *
 * Plainly, what this does NOT cover: a per-file opt-in inside one sibling, such
 * as a `uses()` call or an `extends`. Nothing about this class's ancestry can see
 * that. The static scan in the next test is what covers it, and the two together
 * are the claim — global base class by reflection, per-file opt-ins by
 * inspection.
 */
it('runs on a plain PHPUnit test case, booting no framework', function () {
    $generated = array_values(array_filter(
        get_declared_classes(),
        static fn (string $class): bool => str_starts_with($class, 'P\\Tests\\Unit\\Domain\\'),
    ));

    // Never empty: this test is running, so the class Pest generated for THIS
    // file is among the declared ones. It is located by the file's own basename
    // rather than by `static::class`, which PHPStan cannot type inside a Pest
    // closure. Any sibling already compiled in this process is checked too, as a
    // bonus.
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

    // The two exclusions above are only worth something if those names are real:
    // a typo in either would exclude nothing and pass forever. `Tests\TestCase`
    // exists in this repository, and its own parent is the framework test case —
    // which is both why the name is spelled that way and why extending it is the
    // thing being ruled out.
    expect(class_exists('Tests\TestCase'))->toBeTrue('the excluded base class must exist, or the exclusion asserts nothing')
        ->and(array_values((array) class_parents('Tests\TestCase')))
        ->toContain('Illuminate\Foundation\Testing\TestCase');
});

/*
 * CLAIM 2, by inspection — the per-file half.
 *
 * `uses(Tests\TestCase::class)` in any one of these files would swap that file's
 * base class on its own, invisibly to the reflection above. So would a
 * `RefreshDatabase` trait, or a framework helper call in a test body, which is
 * how a domain unit test acquires a database or a session without naming a base
 * class at all.
 *
 * This file is excluded, by identity against `__FILE__` and nothing broader,
 * because it is where the forbidden names are written down. See the head of the
 * file.
 */
it('boots no framework in any domain unit test file', function () {
    $files = purityDomainTestFiles();

    // Three siblings today: RulesEngineTest, IllFormedMoveListTest,
    // EnumerationTest, plus Support/LineOracle. A floor, so adding a domain test
    // does not fail this; but not zero, so a broken scan cannot pass it.
    expect(count($files))->toBeGreaterThanOrEqual(
        3,
        'discovery found '.count($files).' domain test files besides this one; the scan below would be vacuous',
    );

    // The skip is exactly one file, and it is this one. Asserted rather than
    // asserted-about-in-a-comment, so a reader can see the exclusion is narrow.
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
