<?php

declare(strict_types=1);

namespace Mig\Tests\Command;

use Mig\Command\Squash;
use Mig\Command\Up;
use Mig\Tests\TestCase;
use RuntimeException;

class SquashTest extends TestCase
{
    private function runSquash(): string
    {
        return $this->capture(fn () => (new Squash($this->store(), $this->files()))->run());
    }

    private function runUp(): string
    {
        return $this->capture(fn () => (new Up($this->pdo, $this->store(), $this->files()))->run());
    }

    public function test_creates_combined_file(): void
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
        $this->runSquash();

        $squashFile = $this->path . '/000_squash.sql';
        $this->assertFileExists($squashFile);

        $content = file_get_contents($squashFile);
        $this->assertStringContainsString('CREATE TABLE users', $content);
        $this->assertStringContainsString('CREATE TABLE posts', $content);
        $this->assertStringContainsString('-- mig:down', $content);
    }

    public function test_deletes_original_files(): void
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
        $this->runSquash();

        $this->assertFileDoesNotExist($this->path . '/001_users.sql');
        $this->assertFileDoesNotExist($this->path . '/002_posts.sql');
    }

    public function test_updates_migrations_table(): void
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
        $this->runSquash();

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn();
        $this->assertSame(1, $count);

        $filename = $this->pdo->query('SELECT filename FROM migrations')->fetchColumn();
        $this->assertSame('000_squash.sql', $filename);
    }

    public function test_throws_on_pending_migrations(): void
    {
        $this->writeMigration(
            '001_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY)',
            'DROP TABLE users',
        );

        $this->runUp();

        $this->writeMigration(
            '002_posts.sql',
            'CREATE TABLE posts (id INTEGER PRIMARY KEY)',
            'DROP TABLE posts',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('pending migrations exist');

        $this->runSquash();
    }

    public function test_does_nothing_when_empty(): void
    {
        $output = $this->runSquash();

        $this->assertStringContainsString('Nothing to squash', $output);
    }

    public function test_can_be_run_again_after_new_migrations(): void
    {
        $this->writeMigration(
            '001_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY)',
            'DROP TABLE users',
        );

        $this->runUp();
        $this->runSquash();

        // add and apply a new migration
        $this->writeMigration(
            '002_posts.sql',
            'CREATE TABLE posts (id INTEGER PRIMARY KEY)',
            'DROP TABLE posts',
        );
        $this->runUp();

        // squash again
        $this->runSquash();

        $squashFile = $this->path . '/000_squash.sql';
        $this->assertFileExists($squashFile);

        $content = file_get_contents($squashFile);
        $this->assertStringContainsString('CREATE TABLE users', $content);
        $this->assertStringContainsString('CREATE TABLE posts', $content);

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn();
        $this->assertSame(1, $count);
    }
}
