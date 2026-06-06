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
            "-- mig:up\n{$up}\n-- mig:down\n{$down}\n",
        );
    }

    private function capture(callable $fn): string
    {
        ob_start();
        $fn();
        return ob_get_clean();
    }

    private function migrate(?string $table = null): string
    {
        $migrator = $table
            ? new Migrator($this->pdo, $this->path, $table)
            : new Migrator($this->pdo, $this->path);

        return $this->capture(fn () => $migrator->migrate());
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
        $this->writeMigration(
            '001_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY)',
            'DROP TABLE users',
        );

        $this->migrate();

        $table = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetch();
        $this->assertNotFalse($table);

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM migrations")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function test_migrate_skips_already_applied(): void
    {
        $this->writeMigration(
            '001_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY)',
            'DROP TABLE users',
        );

        $this->migrate();
        $output = $this->migrate();

        $this->assertStringContainsString('[SKIP]', $output);
        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM migrations")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function test_migrate_runs_in_sorted_order(): void
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
        $this->writeMigration(
            '001_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY)',
            'DROP TABLE users',
        );

        $this->migrate('schema_migrations');

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM schema_migrations")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function test_migrate_handles_case_insensitive_markers(): void
    {
        file_put_contents(
            $this->path . '/001_case.sql',
            "-- MIG:UP\nCREATE TABLE items (id INTEGER PRIMARY KEY)\n-- MIG:DOWN\nDROP TABLE items\n",
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
        $this->writeMigration(
            '001_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY)',
            'DROP TABLE users',
        );

        $migrator = new Migrator($this->pdo, $this->path);
        $this->capture(function () use ($migrator) {
            $migrator->migrate();
            $migrator->rollback();
        });

        $table = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetch();
        $this->assertFalse($table);

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM migrations")->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function test_rollback_outputs_down_label(): void
    {
        $this->writeMigration(
            '001_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY)',
            'DROP TABLE users',
        );

        $migrator = new Migrator($this->pdo, $this->path);
        $this->capture(fn () => $migrator->migrate());

        $output = $this->capture(fn () => $migrator->rollback());

        $this->assertStringContainsString('[DOWN]', $output);
        $this->assertStringContainsString('001_users.sql', $output);
    }

    public function test_rollback_does_nothing_when_empty(): void
    {
        $migrator = new Migrator($this->pdo, $this->path);
        $output = $this->capture(fn () => $migrator->rollback());

        $this->assertStringContainsString('Nothing to roll back', $output);
    }

    public function test_rollback_only_reverts_the_last_of_multiple(): void
    {
        $this->writeMigration(
            '001_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY)',
            'DROP TABLE users',
        );
        $this->writeMigration(
            '002_posts.sql',
            'CREATE TABLE posts (id INTEGER PRIMARY KEY)',
            'DROP TABLE posts',
        );

        $migrator = new Migrator($this->pdo, $this->path);
        $this->capture(function () use ($migrator) {
            $migrator->migrate();
            $migrator->rollback();
        });

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM migrations")->fetchColumn();
        $this->assertSame(1, $count);

        $remaining = $this->pdo->query("SELECT filename FROM migrations")->fetchColumn();
        $this->assertSame('001_users.sql', $remaining);
    }

    public function test_rollback_steps_rolls_back_n_migrations(): void
    {
        $this->writeMigration(
            '001_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY)',
            'DROP TABLE users',
        );
        $this->writeMigration(
            '002_posts.sql',
            'CREATE TABLE posts (id INTEGER PRIMARY KEY)',
            'DROP TABLE posts',
        );
        $this->writeMigration(
            '003_comments.sql',
            'CREATE TABLE comments (id INTEGER PRIMARY KEY)',
            'DROP TABLE comments',
        );

        $migrator = new Migrator($this->pdo, $this->path);
        $this->capture(function () use ($migrator) {
            $migrator->migrate();
            $migrator->rollback(2);
        });

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM migrations")->fetchColumn();
        $this->assertSame(1, $count);

        $remaining = $this->pdo->query("SELECT filename FROM migrations")->fetchColumn();
        $this->assertSame('001_users.sql', $remaining);
    }

    public function test_rollback_steps_caps_at_available_count(): void
    {
        $this->writeMigration(
            '001_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY)',
            'DROP TABLE users',
        );

        $migrator = new Migrator($this->pdo, $this->path);
        $this->capture(function () use ($migrator) {
            $migrator->migrate();
            $migrator->rollback(99);
        });

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM migrations")->fetchColumn();
        $this->assertSame(0, $count);
    }

    // -------------------------------------------------------------------
    // refresh()
    // -------------------------------------------------------------------

    public function test_refresh_reapplies_all_migrations(): void
    {
        $this->writeMigration(
            '001_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY)',
            'DROP TABLE users',
        );

        $migrator = new Migrator($this->pdo, $this->path);
        $this->capture(function () use ($migrator) {
            $migrator->migrate();
            $migrator->refresh();
        });

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM migrations")->fetchColumn();
        $this->assertSame(1, $count);

        $table = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetch();
        $this->assertNotFalse($table);
    }

    public function test_refresh_on_empty_state_behaves_like_migrate(): void
    {
        $this->writeMigration(
            '001_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY)',
            'DROP TABLE users',
        );

        $migrator = new Migrator($this->pdo, $this->path);
        $this->capture(fn () => $migrator->refresh());

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM migrations")->fetchColumn();
        $this->assertSame(1, $count);
    }

    // -------------------------------------------------------------------
    // status()
    // -------------------------------------------------------------------

    public function test_status_shows_applied_migration(): void
    {
        $this->writeMigration(
            '001_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY)',
            'DROP TABLE users',
        );

        $migrator = new Migrator($this->pdo, $this->path);
        $this->capture(fn () => $migrator->migrate());

        $output = $this->capture(fn () => $migrator->status());

        $this->assertStringContainsString('001_users.sql', $output);
        $this->assertStringContainsString('Applied', $output);
    }

    public function test_status_shows_pending_migration(): void
    {
        $this->writeMigration(
            '001_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY)',
            'DROP TABLE users',
        );

        $migrator = new Migrator($this->pdo, $this->path);
        $output = $this->capture(fn () => $migrator->status());

        $this->assertStringContainsString('001_users.sql', $output);
        $this->assertStringContainsString('Pending', $output);
    }

    public function test_status_shows_mixed_state(): void
    {
        $this->writeMigration(
            '001_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY)',
            'DROP TABLE users',
        );
        $this->writeMigration(
            '002_posts.sql',
            'CREATE TABLE posts (id INTEGER PRIMARY KEY)',
            'DROP TABLE posts',
        );

        $migrator = new Migrator($this->pdo, $this->path);
        $this->capture(fn () => $migrator->migrate());

        $this->writeMigration(
            '003_comments.sql',
            'CREATE TABLE comments (id INTEGER PRIMARY KEY)',
            'DROP TABLE comments',
        );

        $output = $this->capture(fn () => $migrator->status());

        $this->assertStringContainsString('Applied', $output);
        $this->assertStringContainsString('Pending', $output);
        $this->assertStringContainsString('003_comments.sql', $output);
    }

    public function test_status_shows_no_migrations_message(): void
    {
        $migrator = new Migrator($this->pdo, $this->path);
        $output = $this->capture(fn () => $migrator->status());

        $this->assertStringContainsString('No migrations found', $output);
    }

    // -------------------------------------------------------------------
    // squash()
    // -------------------------------------------------------------------

    public function test_squash_creates_combined_file(): void
    {
        $this->writeMigration(
            '001_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY)',
            'DROP TABLE users',
        );
        $this->writeMigration(
            '002_posts.sql',
            'CREATE TABLE posts (id INTEGER PRIMARY KEY)',
            'DROP TABLE posts',
        );

        $migrator = new Migrator($this->pdo, $this->path);
        $this->capture(function () use ($migrator) {
            $migrator->migrate();
            $migrator->squash();
        });

        $squashFile = $this->path . '/000_squash.sql';
        $this->assertFileExists($squashFile);

        $content = file_get_contents($squashFile);
        $this->assertStringContainsString('CREATE TABLE users', $content);
        $this->assertStringContainsString('CREATE TABLE posts', $content);
        $this->assertStringContainsString('-- mig:down', $content);
    }

    public function test_squash_deletes_original_files(): void
    {
        $this->writeMigration(
            '001_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY)',
            'DROP TABLE users',
        );
        $this->writeMigration(
            '002_posts.sql',
            'CREATE TABLE posts (id INTEGER PRIMARY KEY)',
            'DROP TABLE posts',
        );

        $migrator = new Migrator($this->pdo, $this->path);
        $this->capture(function () use ($migrator) {
            $migrator->migrate();
            $migrator->squash();
        });

        $this->assertFileDoesNotExist($this->path . '/001_users.sql');
        $this->assertFileDoesNotExist($this->path . '/002_posts.sql');
    }

    public function test_squash_updates_migrations_table(): void
    {
        $this->writeMigration(
            '001_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY)',
            'DROP TABLE users',
        );
        $this->writeMigration(
            '002_posts.sql',
            'CREATE TABLE posts (id INTEGER PRIMARY KEY)',
            'DROP TABLE posts',
        );

        $migrator = new Migrator($this->pdo, $this->path);
        $this->capture(function () use ($migrator) {
            $migrator->migrate();
            $migrator->squash();
        });

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM migrations")->fetchColumn();
        $this->assertSame(1, $count);

        $filename = $this->pdo->query("SELECT filename FROM migrations")->fetchColumn();
        $this->assertSame('000_squash.sql', $filename);
    }

    public function test_squash_throws_on_pending_migrations(): void
    {
        $this->writeMigration(
            '001_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY)',
            'DROP TABLE users',
        );

        $migrator = new Migrator($this->pdo, $this->path);
        $this->capture(fn () => $migrator->migrate());

        $this->writeMigration(
            '002_posts.sql',
            'CREATE TABLE posts (id INTEGER PRIMARY KEY)',
            'DROP TABLE posts',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('pending migrations exist');

        ob_start();
        try {
            $migrator->squash();
        } finally {
            ob_end_clean();
        }
    }

    public function test_squash_does_nothing_when_empty(): void
    {
        $migrator = new Migrator($this->pdo, $this->path);
        $output = $this->capture(fn () => $migrator->squash());

        $this->assertStringContainsString('Nothing to squash', $output);
    }

    public function test_squash_can_be_run_again_after_new_migrations(): void
    {
        $this->writeMigration(
            '001_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY)',
            'DROP TABLE users',
        );

        $migrator = new Migrator($this->pdo, $this->path);
        $this->capture(function () use ($migrator) {
            $migrator->migrate();
            $migrator->squash();
        });

        // Add and apply a new migration after the first squash
        $this->writeMigration(
            '002_posts.sql',
            'CREATE TABLE posts (id INTEGER PRIMARY KEY)',
            'DROP TABLE posts',
        );
        $this->capture(fn () => $migrator->migrate());

        // Squash again
        $this->capture(fn () => $migrator->squash());

        $squashFile = $this->path . '/000_squash.sql';
        $this->assertFileExists($squashFile);

        $content = file_get_contents($squashFile);
        $this->assertStringContainsString('CREATE TABLE users', $content);
        $this->assertStringContainsString('CREATE TABLE posts', $content);

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM migrations")->fetchColumn();
        $this->assertSame(1, $count);
    }
}
