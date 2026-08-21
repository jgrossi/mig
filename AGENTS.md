# mig

A minimal PHP database migration runner using PDO. No framework dependencies.

## Structure

```
bin/mig          CLI entry point
src/Migrator.php Single class with all migration logic
tests/           PHPUnit tests (SQLite in-memory, no real DB needed)
.php-cs-fixer.php Code style config
```

## Commands

```bash
composer cs-fix    # format code
composer cs-check  # check formatting (used in CI)
composer test      # run PHPUnit
composer check     # cs-fix + test
```

## Docker

Run tests without a local PHP install:

```bash
docker compose -f docker/docker-compose.yml run --rm test              # PHP 8.3
PHP_VERSION=8.1 docker compose -f docker/docker-compose.yml run --rm test
```

Runs `composer test` (PHPUnit) inside the container; source is bind-mounted, `vendor` lives in a named volume.

## How it works

- Each migration is a `.sql` file with `-- mig:up` and `-- mig:down` sections
- Applied migrations are tracked in a `migrations` table (auto-created)
- Files are sorted alphabetically before running — naming convention matters
- Squash output is always named `000_squash.sql` so it sorts before future migrations

## Migrator public API

| Method | Description |
|---|---|
| `migrate()` | Apply all pending migrations |
| `rollback(int $steps = 1)` | Roll back the last N applied migrations |
| `refresh()` | Roll back all, then re-apply all |
| `status()` | Print applied/pending table |
| `squash()` | Collapse all applied into `000_squash.sql`; throws `RuntimeException` if pending migrations exist |

## CLI commands

`bin/mig` reads `mig.php` from the current working directory. Supports `create`, `up`, `down [--steps=N]`, `refresh`, `status`, `squash`.

## Code style

PHP CS Fixer with `@PSR12` + trailing commas on all multi-line calls/arrays/params, `declare_strict_types`, short array syntax, sorted imports. Run `composer cs-fix` before committing.

## Commits

Commit messages: `fix|feat|chore: description`, description max 5 words.

## Pull Requests

- Title follows the same commit format: `fix|feat|chore: description`, description max 5 words.
- Body as short as possible.

## Tests

- All tests use `PDO('sqlite::memory:')` — no database setup needed
- Migration files are written to a `sys_get_temp_dir()` directory created per test and cleaned up in `tearDown`
- Output is captured with the `capture(callable)` helper (named to avoid conflict with PHPUnit's `final run()`)
- PHPUnit 10.5, requires PHP 8.1+
