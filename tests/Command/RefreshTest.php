<?php

declare(strict_types=1);

namespace Mig\Tests\Command;

use Mig\Command\Down;
use Mig\Command\Refresh;
use Mig\Command\Up;
use Mig\Tests\TestCase;

class RefreshTest extends TestCase
{
    private function runRefresh(): Refresh
    {
        return new Refresh(
            new Down($this->pdo, $this->store(), $this->files()),
            new Up($this->pdo, $this->store(), $this->files()),
        );
    }

    private function runUp(): string
    {
        return $this->capture(fn () => (new Up($this->pdo, $this->store(), $this->files()))->run());
    }

    public function test_reapplies_all_migrations(): void
    {
        $this->writeMigration(
            '001_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY)',
            'DROP TABLE users',
        );

        $this->runUp();
        $this->capture(fn () => $this->runRefresh()->run());

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn();
        $this->assertSame(1, $count);

        $table = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetch();
        $this->assertNotFalse($table);
    }

    public function test_on_empty_state_behaves_like_up(): void
    {
        $this->writeMigration(
            '001_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY)',
            'DROP TABLE users',
        );

        $this->capture(fn () => $this->runRefresh()->run());

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn();
        $this->assertSame(1, $count);
    }
}
