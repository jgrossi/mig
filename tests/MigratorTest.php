<?php

declare(strict_types=1);

namespace Mig\Tests;

use Mig\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class MigratorTest extends TestCase
{
    private PDO $pdo;
    private string $path;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->path = sys_get_temp_dir() . '/mig_test_' . uniqid();
        mkdir($this->path);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->path . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->path);
    }

    private function writeMigration(string $name, string $up, string $down): void
    {
        file_put_contents(
            $this->path . '/' . $name,
            "-- mig:up\n{$up}\n-- mig:down\n{$down}\n"
        );
    }

    private function migrate(?string $table = null): string
    {
        $migrator = $table
            ? new Migrator($this->pdo, $this->path, $table)
            : new Migrator($this->pdo, $this->path);

        ob_start();
        $migrator->migrate();
        return ob_get_clean();
    }

    // -------------------------------------------------------------------
    // migrate()
    // -------------------------------------------------------------------

    public function test_migrate_creates_migrations_table(): void
    {
        $this->migrate();

        $result = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='migrations'")->fetch();
        $this->assertNotFalse($result);
    }

    public function test_migrate_applies_pending_migration(): void
    {
        $this->writeMigration('001_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY)',
            'DROP TABLE users'
        );

        $this->migrate();

        $table = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetch();
        $this->assertNotFalse($table);

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM migrations")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function test_migrate_skips_already_applied(): void
    {
        $this->writeMigration('001_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY)',
            'DROP TABLE users'
        );

        $this->migrate();
        $output = $this->migrate();

        $this->assertStringContainsString('[SKIP]', $output);
        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM migrations")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function test_migrate_runs_in_sorted_order(): void
    {
        $this->writeMigration('002_add_email.sql',
            'ALTER TABLE users ADD COLUMN email TEXT',
            'SELECT 1'
        );
        $this->writeMigration('001_create_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY)',
            'DROP TABLE users'
        );

        $this->migrate();

        $applied = $this->pdo->query("SELECT filename FROM migrations ORDER BY rowid")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['001_create_users.sql', '002_add_email.sql'], $applied);
    }

    public function test_migrate_handles_empty_directory(): void
    {
        $output = $this->migrate();

        $this->assertStringContainsString('No migration files found', $output);
    }

    public function test_migrate_throws_on_missing_up_section(): void
    {
        file_put_contents($this->path . '/001_bad.sql', "-- mig:down\nDROP TABLE users\n");

        $migrator = new Migrator($this->pdo, $this->path);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Missing '-- mig:up'");

        ob_start();
        try {
            $migrator->migrate();
        } finally {
            ob_end_clean();
        }
    }

    public function test_migrate_uses_custom_table_name(): void
    {
        $this->writeMigration('001_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY)',
            'DROP TABLE users'
        );

        $this->migrate('schema_migrations');

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM schema_migrations")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function test_migrate_handles_case_insensitive_markers(): void
    {
        file_put_contents($this->path . '/001_case.sql',
            "-- MIG:UP\nCREATE TABLE items (id INTEGER PRIMARY KEY)\n-- MIG:DOWN\nDROP TABLE items\n"
        );

        $this->migrate();

        $table = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='items'")->fetch();
        $this->assertNotFalse($table);
    }

    // -------------------------------------------------------------------
    // rollback()
    // -------------------------------------------------------------------

    public function test_rollback_reverts_last_migration(): void
    {
        $this->writeMigration('001_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY)',
            'DROP TABLE users'
        );

        $migrator = new Migrator($this->pdo, $this->path);
        ob_start();
        $migrator->migrate();
        $migrator->rollback();
        ob_end_clean();

        $table = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetch();
        $this->assertFalse($table);

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM migrations")->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function test_rollback_outputs_down_label(): void
    {
        $this->writeMigration('001_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY)',
            'DROP TABLE users'
        );

        $migrator = new Migrator($this->pdo, $this->path);
        ob_start();
        $migrator->migrate();
        ob_end_clean();

        ob_start();
        $migrator->rollback();
        $output = ob_get_clean();

        $this->assertStringContainsString('[DOWN]', $output);
        $this->assertStringContainsString('001_users.sql', $output);
    }

    public function test_rollback_does_nothing_when_empty(): void
    {
        $migrator = new Migrator($this->pdo, $this->path);
        ob_start();
        $migrator->rollback();
        $output = ob_get_clean();

        $this->assertStringContainsString('Nothing to roll back', $output);
    }

    public function test_rollback_only_reverts_the_last_of_multiple(): void
    {
        $this->writeMigration('001_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY)',
            'DROP TABLE users'
        );
        $this->writeMigration('002_posts.sql',
            'CREATE TABLE posts (id INTEGER PRIMARY KEY)',
            'DROP TABLE posts'
        );

        $migrator = new Migrator($this->pdo, $this->path);
        ob_start();
        $migrator->migrate();
        $migrator->rollback();
        ob_end_clean();

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM migrations")->fetchColumn();
        $this->assertSame(1, $count);

        $remaining = $this->pdo->query("SELECT filename FROM migrations")->fetchColumn();
        $this->assertSame('001_users.sql', $remaining);
    }

    // -------------------------------------------------------------------
    // refresh()
    // -------------------------------------------------------------------

    public function test_refresh_reapplies_all_migrations(): void
    {
        $this->writeMigration('001_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY)',
            'DROP TABLE users'
        );

        $migrator = new Migrator($this->pdo, $this->path);
        ob_start();
        $migrator->migrate();
        $migrator->refresh();
        ob_end_clean();

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM migrations")->fetchColumn();
        $this->assertSame(1, $count);

        $table = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetch();
        $this->assertNotFalse($table);
    }

    public function test_refresh_on_empty_state_behaves_like_migrate(): void
    {
        $this->writeMigration('001_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY)',
            'DROP TABLE users'
        );

        $migrator = new Migrator($this->pdo, $this->path);
        ob_start();
        $migrator->refresh();
        ob_end_clean();

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM migrations")->fetchColumn();
        $this->assertSame(1, $count);
    }
}
