<?php

declare(strict_types=1);

namespace Mig;

use PDO;

class MigrationStore
{
    public function __construct(
        private PDO $pdo,
        private string $table = 'migrations',
    ) {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS {$this->table} (
                filename   VARCHAR(255) PRIMARY KEY,
                applied_at VARCHAR(30)  NOT NULL
            )
        ");
    }

    /**
     * @return array<string, string> filename => applied_at
     */
    public function applied(): array
    {
        $applied = [];

        foreach ($this->pdo->query("SELECT filename, applied_at FROM {$this->table}")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $applied[$row['filename']] = $row['applied_at'];
        }

        return $applied;
    }

    /**
     * @return list<string> applied filenames, most recent first
     */
    public function latest(int $limit = PHP_INT_MAX): array
    {
        return $this->pdo
            ->query("SELECT filename FROM {$this->table} ORDER BY applied_at DESC, filename DESC LIMIT " . (int) $limit)
            ->fetchAll(PDO::FETCH_COLUMN);
    }

    public function add(string $filename): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO {$this->table} (filename, applied_at) VALUES (?, ?)");
        $stmt->execute([$filename, date('Y-m-d H:i:s')]);
    }

    public function remove(string $filename): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE filename = ?");
        $stmt->execute([$filename]);
    }

    public function clear(): void
    {
        $this->pdo->exec("DELETE FROM {$this->table}");
    }
}
