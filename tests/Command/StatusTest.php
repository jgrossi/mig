<?php

declare(strict_types=1);

namespace Mig\Tests\Command;

use Mig\Command\Status;
use Mig\Command\Up;
use Mig\Tests\TestCase;

class StatusTest extends TestCase
{
    private function runStatus(): string
    {
        return $this->capture(fn () => (new Status($this->store(), $this->files()))->run());
    }

    private function runUp(): string
    {
        return $this->capture(fn () => (new Up($this->pdo, $this->store(), $this->files()))->run());
    }

    public function test_shows_applied_migration(): void
    {
        $this->writeMigration(
            '001_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY)',
            'DROP TABLE users',
        );

        $this->runUp();

        $output = $this->runStatus();

        $this->assertStringContainsString('001_users.sql', $output);
        $this->assertStringContainsString('Applied', $output);
    }

    public function test_shows_pending_migration(): void
    {
        $this->writeMigration(
            '001_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY)',
            'DROP TABLE users',
        );

        $output = $this->runStatus();

        $this->assertStringContainsString('001_users.sql', $output);
        $this->assertStringContainsString('Pending', $output);
    }

    public function test_shows_mixed_state(): void
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

        $this->writeMigration(
            '003_comments.sql',
            'CREATE TABLE comments (id INTEGER PRIMARY KEY)',
            'DROP TABLE comments',
        );

        $output = $this->runStatus();

        $this->assertStringContainsString('Applied', $output);
        $this->assertStringContainsString('Pending', $output);
        $this->assertStringContainsString('003_comments.sql', $output);
    }

    public function test_shows_no_migrations_message(): void
    {
        $output = $this->runStatus();

        $this->assertStringContainsString('No migrations found', $output);
    }
}
