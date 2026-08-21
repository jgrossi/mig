<?php

declare(strict_types=1);

namespace Mig\Tests\Command;

use Mig\Command\Down;
use Mig\Command\Up;
use Mig\Tests\TestCase;

class DownTest extends TestCase
{
    private function runDown(int $steps = 1): Down
    {
        return new Down($this->pdo, $this->store(), $this->files(), $steps);
    }

    private function runUp(): string
    {
        return $this->capture(fn () => (new Up($this->pdo, $this->store(), $this->files()))->run());
    }

    public function test_reverts_last_migration(): void
    {
        $this->writeMigration(
            '001_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY)',
            'DROP TABLE users',
        );

        $this->runUp();
        $this->capture(fn () => $this->runDown()->run());

        $table = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetch();
        $this->assertFalse($table);

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function test_outputs_down_label(): void
    {
        $this->writeMigration(
            '001_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY)',
            'DROP TABLE users',
        );

        $this->runUp();

        $output = $this->capture(fn () => $this->runDown()->run());

        $this->assertStringContainsString('[DOWN]', $output);
        $this->assertStringContainsString('001_users.sql', $output);
    }

    public function test_does_nothing_when_empty(): void
    {
        $output = $this->capture(fn () => $this->runDown()->run());

        $this->assertStringContainsString('Nothing to roll back', $output);
    }

    public function test_only_reverts_the_last_of_multiple(): void
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

        $this->runUp();
        $this->capture(fn () => $this->runDown()->run());

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn();
        $this->assertSame(1, $count);

        $remaining = $this->pdo->query('SELECT filename FROM migrations')->fetchColumn();
        $this->assertSame('001_users.sql', $remaining);
    }

    public function test_steps_rolls_back_n_migrations(): void
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

        $this->runUp();
        $this->capture(fn () => $this->runDown(2)->run());

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn();
        $this->assertSame(1, $count);

        $remaining = $this->pdo->query('SELECT filename FROM migrations')->fetchColumn();
        $this->assertSame('001_users.sql', $remaining);
    }

    public function test_steps_caps_at_available_count(): void
    {
        $this->writeMigration(
            '001_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY)',
            'DROP TABLE users',
        );

        $this->runUp();
        $this->capture(fn () => $this->runDown(99)->run());

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function test_run_all_reverts_everything(): void
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

        $this->runUp();
        $output = $this->capture(fn () => $this->runDown()->runAll());

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn();
        $this->assertSame(0, $count);
        $this->assertStringContainsString('[DOWN] 002_posts.sql', $output);
        $this->assertStringContainsString('[DOWN] 001_users.sql', $output);
    }

    public function test_reverts_empty_stub_as_noop(): void
    {
        file_put_contents(
            $this->path . '/001_stub.sql',
            "-- mig:up\n\n\n-- mig:down\n\n",
        );

        $this->runUp();
        $output = $this->capture(fn () => $this->runDown()->run());

        $this->assertStringContainsString('[DOWN] 001_stub.sql', $output);
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn();
        $this->assertSame(0, $count);
    }
}
