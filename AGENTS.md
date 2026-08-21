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

Run tests without a local PHP install, via the Makefile:

```bash
make test                    # PHPUnit on PHP 8.3
make test PHP_VERSION=8.1    # PHPUnit on PHP 8.1
make cs-fix / cs-check / check
make run c="php -v"          # arbitrary command in the container
make shell                   # bash in the container
make clean                   # remove containers + vendor volume
```

Under the hood: `docker compose -f docker/docker-compose.yml run --rm --build test`.

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

## Releases

Automated by release-please (`.github/workflows/release-please.yml`). PR titles drive semver: `feat:` bumps minor, `fix:` bumps patch, `chore:` is ignored. On merge to `main`, release-please opens a `chore(main): release X.Y.Z` PR (bumps `composer.json`, updates `CHANGELOG.md`, `.release-please-manifest.json`); merging it tags and creates the GitHub release. Tags have no `v` prefix.

## Tests

- All tests use `PDO('sqlite::memory:')` — no database setup needed
- Migration files are written to a `sys_get_temp_dir()` directory created per test and cleaned up in `tearDown`
- Output is captured with the `capture(callable)` helper (named to avoid conflict with PHPUnit's `final run()`)
- PHPUnit 10.5, requires PHP 8.1+
