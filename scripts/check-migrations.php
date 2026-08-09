<?php

declare(strict_types=1);

/**
 * Reject a migration that cannot be safely deployed by the pipeline.
 *
 *   php scripts/check-migrations.php [directory]      # default: database/migrations
 *
 * Exits 0 when every migration passes, 1 on the first failing file, 2 on a usage error.
 * Run by the `quality` job, where it must fail the job rather than warn (Req 8.3).
 *
 * WHY THIS EXISTS, which is not the reason people assume. It is not about tidiness. On
 * SQLite, Laravel opens NO transaction around a migration: `Migrator::runMigration()`
 * gates that on `supportsSchemaTransactions()`, and `SQLiteGrammar` inherits
 * `$transactions = false`. So a migration that performs two schema changes and fails
 * between them leaves the database in a state that is neither before nor after, AND
 * leaves the `migrations` table without the row — so the next deploy re-runs it, hits
 * "table already exists" on the first statement, and every subsequent deployment fails
 * the same way until someone fixes the database by hand. The failure is a stuck
 * pipeline, not data loss, and an image rollback does not clear it because rolling the
 * image back does not roll the schema back.
 *
 * The rules, and each is checked only inside `up()`:
 *
 *   1. No destructive schema operation. `down()` is exempt because
 *      `Schema::dropIfExists()` is exactly what belongs there.
 *   2. No destructive raw SQL, for the same operations expressed through
 *      `DB::statement()`.
 *   3. At most one table per migration.
 *
 * WHAT THIS CANNOT DETECT, stated because a check whose limits are unknown gets trusted
 * for things it does not do:
 *
 *   - A NON-NULLABLE COLUMN ADDED TO A POPULATED TABLE. Syntactically additive, and it
 *     fails at runtime on the existing rows. This is the likeliest way to break a
 *     deployment while passing every rule here.
 *   - A DESTRUCTIVE RAW STATEMENT IT DOES NOT RECOGNISE. Rule 2 matches a fixed list of
 *     keywords. SQL assembled from variables, or a `PRAGMA`, passes.
 *   - WHETHER THE ONE CHANGE IS THE RIGHT CHANGE. It counts tables; it cannot read
 *     intent.
 *   - A PARTIAL FAILURE WITHIN ONE TABLE. `CREATE TABLE` followed by its indexes is one
 *     table and passes, yet a failure between them leaves the table without its indexes
 *     and re-running hits "already exists". Rule 3 bounds the blast radius to one table;
 *     it does not make a migration atomic, because on SQLite nothing can.
 *
 * The expand-and-contract sequence this supports: add the new column nullable, deploy;
 * backfill and start writing both, deploy; switch reads, deploy; stop writing the old
 * one, deploy. The contract step — actually dropping the column — is rejected by rule 1
 * and has to be done deliberately, by hand, against a database you have looked at.
 */
const DESTRUCTIVE_METHODS = [
    'drop',
    'dropColumn',
    'dropColumns',
    'dropIfExists',
    'dropPrimary',
    'dropUnique',
    'renameColumn',
    'renameTo',
    'change',
];

/**
 * Matched against string and heredoc contents inside `up()`. Whitespace-tolerant so
 * that a statement broken across lines is still caught.
 */
const DESTRUCTIVE_SQL = [
    '/\bDROP\s+TABLE\b/i',
    '/\bDROP\s+COLUMN\b/i',
    '/\bDROP\s+INDEX\b/i',
    '/\bRENAME\s+COLUMN\b/i',
    '/\bRENAME\s+TO\b/i',
    '/\bTRUNCATE\b/i',
    '/\bDELETE\s+FROM\b/i',
];

/**
 * Exempt from rule 3 only, and never from rules 1 or 2.
 *
 * These three are Laravel's own scaffold, they each create two or three tables in one
 * `up()`, and they are already applied on every database this project has. Rewriting
 * applied history to satisfy a check added afterwards would be the more dangerous
 * change. Nothing may be added here without an edit a reviewer sees.
 */
const MULTI_TABLE_EXEMPT = [
    '0001_01_01_000000_create_users_table.php',
    '0001_01_01_000001_create_cache_table.php',
    '0001_01_01_000002_create_jobs_table.php',
];

/**
 * @return list<array{int, string, int}> the tokens of the `up()` method body, or an
 *                                       empty list when the file declares no `up()`
 */
function upMethodTokens(string $source): array
{
    /** @var list<array{int, string, int}|string> $tokens */
    $tokens = token_get_all($source);
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        if (! is_array($token) || $token[0] !== T_FUNCTION) {
            continue;
        }

        // The name may be separated from `function` by whitespace only.
        $name = null;
        for ($j = $i + 1; $j < $count; $j++) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                continue;
            }
            $name = is_array($tokens[$j]) && $tokens[$j][0] === T_STRING ? $tokens[$j][1] : null;
            break;
        }

        if ($name !== 'up') {
            continue;
        }

        // Skip the signature and any return type; the body starts at the first brace.
        $open = null;
        for ($j = $i + 1; $j < $count; $j++) {
            if ($tokens[$j] === '{') {
                $open = $j;
                break;
            }
        }

        if ($open === null) {
            return [];
        }

        $depth = 0;
        $body = [];
        for ($j = $open; $j < $count; $j++) {
            $token = $tokens[$j];

            if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
                if ($depth === 1) {
                    continue;
                }
            } elseif ($token === '}') {
                $depth--;
                if ($depth === 0) {
                    return $body;
                }
            }

            $body[] = is_array($token) ? $token : [-1, $token, 0];
        }

        return $body;
    }

    return [];
}

/**
 * @param  list<array{int, string, int}>  $body
 * @return list<string> the violations found, empty when the migration passes
 */
function violations(string $file, array $body): array
{
    $found = [];
    $count = count($body);
    $tables = [];
    $sql = '';

    for ($i = 0; $i < $count; $i++) {
        [$id, $text] = $body[$i];

        // Comments are not code. A docblock explaining why a column is NOT dropped must
        // not be read as dropping it, and this project's migrations carry exactly that
        // kind of prose.
        if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
            continue;
        }

        if ($id === T_CONSTANT_ENCAPSED_STRING || $id === T_ENCAPSED_AND_WHITESPACE) {
            $sql .= "\n".$text;

            continue;
        }

        if ($id !== T_STRING) {
            continue;
        }

        // A method or static call, rather than a bare identifier that happens to match.
        $previous = null;
        for ($j = $i - 1; $j >= 0; $j--) {
            if ($body[$j][0] === T_WHITESPACE) {
                continue;
            }
            $previous = $body[$j];
            break;
        }

        $isCall = $previous !== null
            && in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true);

        if (! $isCall) {
            continue;
        }

        if (in_array($text, DESTRUCTIVE_METHODS, true)) {
            $found[] = sprintf('destructive operation `%s()` in up(); schema changes move forward only', $text);
        }

        // The table a schema-builder call names, for rule 3.
        if (in_array($text, ['create', 'table', 'rename'], true)) {
            for ($j = $i + 1; $j < $count; $j++) {
                if (in_array($body[$j][0], [T_WHITESPACE], true) || $body[$j][1] === '(') {
                    continue;
                }
                if ($body[$j][0] === T_CONSTANT_ENCAPSED_STRING) {
                    $tables[trim($body[$j][1], "'\"")] = true;
                }
                break;
            }
        }
    }

    foreach (DESTRUCTIVE_SQL as $pattern) {
        if (preg_match($pattern, $sql) === 1) {
            $found[] = sprintf('destructive raw SQL matching %s in up()', $pattern);
        }
    }

    // Raw DDL names its table positionally rather than as an argument.
    foreach (['/\bCREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"\'\[]?(\w+)/i', '/\bALTER\s+TABLE\s+[`"\'\[]?(\w+)/i'] as $pattern) {
        if (preg_match_all($pattern, $sql, $matches) > 0) {
            foreach ($matches[1] as $table) {
                $tables[$table] = true;
            }
        }
    }

    if (count($tables) > 1 && ! in_array(basename($file), MULTI_TABLE_EXEMPT, true)) {
        $found[] = sprintf(
            'up() touches %d tables (%s); one table per migration, because SQLite gives a migration no transaction to roll back',
            count($tables),
            implode(', ', array_keys($tables)),
        );
    }

    return $found;
}

$directory = $argv[1] ?? 'database/migrations';

if (! is_dir($directory)) {
    fwrite(STDERR, sprintf("check-migrations: %s is not a directory\n", $directory));
    exit(2);
}

$files = glob(rtrim($directory, '/').'/*.php');

if ($files === false || $files === []) {
    fwrite(STDERR, sprintf("check-migrations: no migrations found in %s\n", $directory));
    exit(2);
}

sort($files);
$failed = 0;

foreach ($files as $file) {
    $source = file_get_contents($file);

    if ($source === false) {
        fwrite(STDERR, sprintf("check-migrations: cannot read %s\n", $file));
        exit(2);
    }

    $body = upMethodTokens($source);

    if ($body === []) {
        fwrite(STDERR, sprintf("  FAIL  %s\n        declares no up() method, or its body could not be parsed\n", basename($file)));
        $failed++;

        continue;
    }

    $problems = violations($file, $body);

    if ($problems === []) {
        printf("  ok    %s\n", basename($file));

        continue;
    }

    $failed++;
    fwrite(STDERR, sprintf("  FAIL  %s\n", basename($file)));
    foreach ($problems as $problem) {
        fwrite(STDERR, sprintf("        %s\n", $problem));
    }
}

printf("\n%d migration(s) checked, %d failing\n", count($files), $failed);

if ($failed > 0) {
    fwrite(STDERR, "\nSchema changes must be additive. Add nullable, backfill, switch reads, then\nstop writing the old column -- and do the drop by hand, later, deliberately.\nSee database/migrations/README.md.\n");
    exit(1);
}

exit(0);
