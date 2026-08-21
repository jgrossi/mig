<?php

declare(strict_types=1);

namespace Mig\Tests\Command;

use Mig\Command\Up;
use Mig\Tests\TestCase;
use PDO;
use RuntimeException;

class UpTest extends TestCase
{
    private function runUp(?string $table = null): string
    {
        $up = new Up($this->pdo, $this->store($table), $this->files());

        return $this->capture(fn () => $up->run());
    }

    public function test_creates_migrations_table(): void
    {
        $this->runUp();

        $result = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='migrations'")->fetch();
        $this->assertNotFalse($result);
    }

    public function test_applies_pending_migration(): void
    {
        $this->writeMigration(
            '001_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY)',
            'DROP TABLE users',
        );

        $this->runUp();

        $table = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetch();
        $this->assertNotFalse($table);

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function test_skips_already_applied(): void
    {
        $this->writeMigration(
            '001_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY)',
            'DROP TABLE users',
        );

        $this->runUp();
        $output = $this->runUp();

        $this->assertStringContainsString('[SKIP]', $output);
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function test_runs_in_sorted_order(): void
    {
        $this->writeMigration(
            '002_add_email.sql',
            'ALTER TABLE users ADD COLUMN email TEXT',
            'SELECT 1',
        );
        $this->writeMigration(
            '001_create_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY)',
            'DROP TABLE users',
        );

        $this->runUp();

        $applied = $this->pdo->query('SELECT filename FROM migrations ORDER BY rowid')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['001_create_users.sql', '002_add_email.sql'], $applied);
    }

    public function test_handles_empty_directory(): void
    {
        $output = $this->runUp();

        $this->assertStringContainsString('No migration files found', $output);
    }

    public function test_throws_on_missing_up_section(): void
    {
        file_put_contents($this->path . '/001_bad.sql', "-- mig:down\nDROP TABLE users\n");

        $up = new Up($this->pdo, $this->store(), $this->files());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Missing '-- mig:up'");

        $this->capture(fn () => $up->run());
    }

    public function test_uses_custom_table_name(): void
    {
        $this->writeMigration(
            '001_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY)',
            'DROP TABLE users',
        );

        $this->runUp('schema_migrations');

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function test_handles_case_insensitive_markers(): void
    {
        file_put_contents(
            $this->path . '/001_case.sql',
            "-- MIG:UP\nCREATE TABLE items (id INTEGER PRIMARY KEY)\n-- MIG:DOWN\nDROP TABLE items\n",
        );

        $this->runUp();

        $table = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='items'")->fetch();
        $this->assertNotFalse($table);
    }

    public function test_applies_empty_stub_as_noop(): void
    {
        file_put_contents(
            $this->path . '/001_stub.sql',
            "-- mig:up\n\n\n-- mig:down\n\n",
        );

        $output = $this->runUp();

        $this->assertStringContainsString('[OK]   001_stub.sql', $output);
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn();
        $this->assertSame(1, $count);
    }
}
