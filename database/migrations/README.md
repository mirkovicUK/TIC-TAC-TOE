# Rules for writing a migration here

Two rules, both enforced by `scripts/check-migrations.php` in the `quality` job:

1. **`up()` must be additive.** No `dropColumn`, `renameColumn`, `dropIfExists`, `drop`,
   `change`, and no raw `DROP`/`RENAME`/`TRUNCATE`/`DELETE FROM`. `down()` is unrestricted —
   `Schema::dropIfExists()` is exactly what belongs there.
2. **One table per migration.** Two tables in one `up()` is rejected even when both changes are
   additive.

## Why, because the reason is not tidiness

**On SQLite, Laravel wraps a migration in no transaction at all.** `Migrator::runMigration()`
gates that on `supportsSchemaTransactions()`, and `SQLiteGrammar` inherits
`$transactions = false`. So a migration that does two things and fails after the first leaves the
database in a state that is neither before nor after — *and* leaves no row in the `migrations`
table. The next deployment re-runs it, the first statement hits "already exists", and every
deployment after that fails the same way until somebody opens the database by hand.

The failure is a **stuck pipeline**, not data loss. It is worth being precise about that, because
the two call for different care. And note what does *not* fix it: rolling the image back does not
roll the schema back. The pipeline's automatic fallback restores the previous Image_Pair and
nothing else (ADR-012).

## Adding a column: expand and contract

Adding a nullable column is one deployment. Removing one is four, and the last is manual.

| Step | Migration | Application code |
| --- | --- | --- |
| 1. Expand | add the column **nullable** | ignores it |
| 2. Backfill | none | writes both old and new |
| 3. Switch | none | reads new, still writes both |
| 4. Stop | none | writes new only |
| 5. Contract | **by hand, later** | — |

Step 5 is rejected by rule 1 on purpose. Dropping a column is a decision to make against a
database you have looked at, not something to discover in a pipeline log at 3am. On SQLite it also
rebuilds the table, so it is the least additive operation available.

**A non-nullable column is the trap.** `$table->text('x')` with no `->nullable()` is
syntactically additive, passes every rule here, and fails at runtime on the rows already in the
table. The checker cannot see it. If the column must eventually be non-nullable, get there by
backfilling and then tightening — not in the migration that introduces it.

## What the checker cannot catch

Stated so it is not trusted for more than it does:

- a non-nullable column added to a populated table
- destructive raw SQL it does not recognise — the pattern list is fixed, so SQL built from
  variables, or a `PRAGMA`, passes
- whether the one change is the *right* change; it counts tables, it cannot read intent
- a partial failure *within* one table. `CREATE TABLE` plus its indexes is one table and passes,
  and a failure between them still leaves the table without indexes. Rule 2 bounds the blast
  radius to one table; it cannot make a migration atomic, because on SQLite nothing can.

## Three files here are exempt from rule 2

`0001_01_01_000000_create_users_table.php`, `0001_01_01_000001_create_cache_table.php` and
`0001_01_01_000002_create_jobs_table.php` each create two or three tables in one `up()`. They are
Laravel's scaffold, they are already applied on every database this project has, and rewriting
applied history to satisfy a rule added afterwards is the more dangerous change. They are named in
`MULTI_TABLE_EXEMPT` in the checker; adding anything to that list takes an edit a reviewer sees.

The three tables this application actually owns — `games`, `moves`, `expiry_records` — are one
table each, written as a raw `CREATE TABLE` because the Blueprint cannot express a CHECK
constraint and SQLite has no `ALTER TABLE ... ADD CONSTRAINT`.

## Running it

```bash
composer check:migrations                          # database/migrations
php scripts/check-migrations.php <directory>       # anything else
```

Exit 0 all clear, 1 a violation, 2 a bad path — 2 is distinct so a checker pointed at the wrong
directory cannot report success.
