# jgrossi/mig

[![Tests](https://github.com/jgrossi/mig/actions/workflows/tests.yml/badge.svg)](https://github.com/jgrossi/mig/actions/workflows/tests.yml)

Simple PHP database migration runner using PDO. Works with MySQL, PostgreSQL, and SQLite.

## Installation

```bash
composer require jgrossi/mig
```

## Configuration

Create a `mig.php` file in your project root.

**Using individual connection fields:**

```php
<?php
return [
    'driver'   => 'mysql', // mysql, pgsql, or sqlite
    'host'     => 'localhost',
    'port'     => 3306,     // optional
    'dbname'   => 'myapp',
    'username' => 'root',
    'password' => 'secret',
    'path'     => __DIR__ . '/db/migrations',
    'table'    => 'migrations', // optional, default: migrations
];
```

**Using a raw DSN:**

```php
<?php
return [
    'dsn'      => 'mysql:host=localhost;dbname=myapp;charset=utf8mb4',
    'username' => 'root',
    'password' => 'secret',
    'path'     => __DIR__ . '/db/migrations',
];
```

**SQLite example:**

```php
<?php
return [
    'dsn'  => 'sqlite:' . __DIR__ . '/db/app.sqlite',
    'path' => __DIR__ . '/db/migrations',
];
```

## Migration files

Each migration is a single `.sql` file with an up and a down section:

```sql
-- mig:up
CREATE TABLE users (
    id   INT PRIMARY KEY,
    name TEXT NOT NULL
);

-- mig:down
DROP TABLE users;
```

## Commands

### `mig create`

Creates a new empty migration file in your migrations directory, named after the current timestamp.

```bash
./vendor/bin/mig create
# [CREATED] 20260605143022.sql
```

### `mig up`

Applies all pending migrations in order, skipping any already applied.

```bash
./vendor/bin/mig up
# [OK]   20260605143022.sql
# [SKIP] 20260605143100.sql
# Done.
```

### `mig down`

Rolls back the last applied migration. Use `--steps=N` to roll back more than one.

```bash
./vendor/bin/mig down
# [DOWN] 20260605143100.sql

./vendor/bin/mig down --steps=3
# [DOWN] 20260605143100.sql
# [DOWN] 20260605143022.sql
# [DOWN] 20260605142900.sql
```

### `mig status`

Shows all migration files and whether each has been applied.

```bash
./vendor/bin/mig status
# Migration              Status    Applied At
# -------------------------------------------------
# 20260605143022.sql     Applied   2026-06-05 14:30:22
# 20260605143100.sql     Applied   2026-06-05 14:31:00
# 20260605150000.sql     Pending
```

### `mig squash`

Collapses all applied migrations into a single `000_squash.sql` file and removes the originals. Useful for cleaning up history on established projects. Requires no pending migrations.

```bash
./vendor/bin/mig squash
# [REMOVED] 20260605143022.sql
# [REMOVED] 20260605143100.sql
# [SQUASHED] 000_squash.sql
```

The squash file is prefixed with `000_` so it always sorts before future migrations, ensuring a correct apply order on fresh installs.

### `mig refresh`

Rolls back all applied migrations in reverse order, then re-runs them all from scratch. Useful during development.

```bash
./vendor/bin/mig refresh
# [DOWN] 20260605143100.sql
# [DOWN] 20260605143022.sql
# [OK]   20260605143022.sql
# [OK]   20260605143100.sql
# Done.
```

## How it works

Applied migrations are tracked in a `migrations` table created automatically in your database. Each `.sql` file is applied exactly once, identified by its filename.
