<?php

declare(strict_types=1);

namespace Mig\Tests;

class MigrationStoreTest extends TestCase
{
    public function test_creates_table_on_construction(): void
    {
        $this->store();

        $result = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='migrations'")->fetch();
        $this->assertNotFalse($result);
    }

    public function test_adds_lists_and_removes(): void
    {
        $store = $this->store();

        $store->add('001_users.sql');
        $store->add('002_posts.sql');

        $this->assertSame(
            ['001_users.sql', '002_posts.sql'],
            array_keys($store->applied()),
        );

        $store->remove('001_users.sql');

        $this->assertSame(['002_posts.sql'], array_keys($store->applied()));
    }

    public function test_latest_orders_newest_first(): void
    {
        $this->store();

        $stmt = $this->pdo->prepare('INSERT INTO migrations (filename, applied_at) VALUES (?, ?)');
        $stmt->execute(['001_users.sql', '2026-01-01 10:00:00']);
        $stmt->execute(['003_a.sql', '2026-01-01 11:00:00']);
        $stmt->execute(['003_b.sql', '2026-01-01 11:00:00']);

        $store = $this->store();

        // applied_at DESC, then filename DESC
        $this->assertSame(
            ['003_b.sql', '003_a.sql', '001_users.sql'],
            $store->latest(),
        );
        $this->assertSame(['003_b.sql'], $store->latest(1));
    }

    public function test_clear_empties_the_table(): void
    {
        $store = $this->store();
        $store->add('001_users.sql');
        $store->clear();

        $this->assertSame([], $store->applied());
    }

    public function test_uses_custom_table_name(): void
    {
        $store = $this->store('schema_migrations');
        $store->add('001_users.sql');

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
        $this->assertSame(1, $count);
    }
}
