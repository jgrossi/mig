<?php

declare(strict_types=1);

namespace Mig;

use PDO;
use RuntimeException;

class Migrator
{
    public function __construct(
        private PDO $pdo,
        private string $path,
        private string $table = 'migrations',
    ) {}

    public function migrate(): void
    {
        $this->createMigrationsTable();

        $files = glob(rtrim($this->path, '/') . '/*.sql');

        if (empty($files)) {
            echo "No migration files found in {$this->path}\n";
            return;
        }

        sort($files);

        $applied = array_flip(
            $this->pdo->query("SELECT filename FROM {$this->table}")->fetchAll(PDO::FETCH_COLUMN)
        );

        foreach ($files as $file) {
            $name = basename($file);
            if (isset($applied[$name])) {
                echo "[SKIP] $name\n";
                continue;
            }
            $sql = $this->parseSql(file_get_contents($file), 'up');
            $this->pdo->exec($sql);
            $stmt = $this->pdo->prepare("INSERT INTO {$this->table} (filename, applied_at) VALUES (?, ?)");
            $stmt->execute([$name, date('Y-m-d H:i:s')]);
            echo "[OK]   $name\n";
        }

        echo "Done.\n";
    }

    public function rollback(): void
    {
        $this->createMigrationsTable();

        $row = $this->pdo
            ->query("SELECT filename FROM {$this->table} ORDER BY applied_at DESC, filename DESC LIMIT 1")
            ->fetch(PDO::FETCH_COLUMN);

        if (!$row) {
            echo "Nothing to roll back.\n";
            return;
        }

        $file = rtrim($this->path, '/') . '/' . $row;

        if (!file_exists($file)) {
            fwrite(STDERR, "Error: migration file not found: $file\n");
            exit(1);
        }

        $sql = $this->parseSql(file_get_contents($file), 'down');
        $this->pdo->exec($sql);

        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE filename = ?");
        $stmt->execute([$row]);

        echo "[DOWN] $row\n";
    }

    public function refresh(): void
    {
        $this->createMigrationsTable();

        $applied = $this->pdo
            ->query("SELECT filename FROM {$this->table} ORDER BY applied_at DESC, filename DESC")
            ->fetchAll(PDO::FETCH_COLUMN);

        foreach ($applied as $filename) {
            $file = rtrim($this->path, '/') . '/' . $filename;
            if (!file_exists($file)) {
                fwrite(STDERR, "Error: migration file not found: $file\n");
                exit(1);
            }
            $sql = $this->parseSql(file_get_contents($file), 'down');
            $this->pdo->exec($sql);
            $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE filename = ?");
            $stmt->execute([$filename]);
            echo "[DOWN] $filename\n";
        }

        $this->migrate();
    }

    private function createMigrationsTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS {$this->table} (
                filename   VARCHAR(255) PRIMARY KEY,
                applied_at VARCHAR(30)  NOT NULL
            )
        ");
    }

    private function parseSql(string $content, string $direction): string
    {
        $pattern = '/--\s*mig:(up|down)\s*/i';
        $parts = preg_split($pattern, $content, -1, PREG_SPLIT_DELIM_CAPTURE);

        // $parts: [before, marker1, section1, marker2, section2, ...]
        $sections = [];
        for ($i = 1; $i < count($parts); $i += 2) {
            $sections[strtolower($parts[$i])] = trim($parts[$i + 1] ?? '');
        }

        if (!isset($sections[$direction])) {
            throw new RuntimeException("Missing '-- mig:$direction' section in migration file");
        }

        return $sections[$direction];
    }
}
