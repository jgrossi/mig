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
    ) {
    }

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
            $this->pdo->query("SELECT filename FROM {$this->table}")->fetchAll(PDO::FETCH_COLUMN),
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

    public function rollback(int $steps = 1): void
    {
        $this->createMigrationsTable();

        $rows = $this->pdo
            ->query("SELECT filename FROM {$this->table} ORDER BY applied_at DESC, filename DESC LIMIT " . (int) $steps)
            ->fetchAll(PDO::FETCH_COLUMN);

        if (empty($rows)) {
            echo "Nothing to roll back.\n";
            return;
        }

        foreach ($rows as $filename) {
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

    public function status(): void
    {
        $this->createMigrationsTable();

        $files = array_map('basename', glob(rtrim($this->path, '/') . '/*.sql') ?: []);

        $applied = [];
        foreach ($this->pdo->query("SELECT filename, applied_at FROM {$this->table}")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $applied[$row['filename']] = $row['applied_at'];
        }

        $names = array_values(array_unique(array_merge($files, array_keys($applied))));
        sort($names);

        if (empty($names)) {
            echo "No migrations found.\n";
            return;
        }

        $w = max(9, ...array_map('strlen', $names));

        printf("%-{$w}s  %-8s  %s\n", 'Migration', 'Status', 'Applied At');
        echo str_repeat('-', $w + 31) . "\n";

        foreach ($names as $name) {
            if (isset($applied[$name])) {
                printf("%-{$w}s  %-8s  %s\n", $name, 'Applied', $applied[$name]);
            } else {
                printf("%-{$w}s  Pending\n", $name);
            }
        }
    }

    public function squash(): void
    {
        $this->createMigrationsTable();

        $applied = $this->pdo
            ->query("SELECT filename FROM {$this->table} ORDER BY applied_at ASC, filename ASC")
            ->fetchAll(PDO::FETCH_COLUMN);

        if (empty($applied)) {
            echo "Nothing to squash.\n";
            return;
        }

        $files   = array_map('basename', glob(rtrim($this->path, '/') . '/*.sql') ?: []);
        $pending = array_diff($files, $applied);

        if (!empty($pending)) {
            throw new RuntimeException("Cannot squash: pending migrations exist. Run 'mig up' first.");
        }

        $upParts   = [];
        $downParts = [];

        foreach ($applied as $filename) {
            $file = rtrim($this->path, '/') . '/' . $filename;
            if (!file_exists($file)) {
                fwrite(STDERR, "Error: migration file not found: $file\n");
                exit(1);
            }
            $content     = file_get_contents($file);
            $upParts[]   = $this->parseSql($content, 'up');
            $downParts[]  = $this->parseSql($content, 'down');
        }

        $squashName    = '000_squash.sql';
        $squashFile    = rtrim($this->path, '/') . '/' . $squashName;
        $squashContent = "-- mig:up\n" . implode("\n\n", $upParts)
                       . "\n\n-- mig:down\n" . implode("\n\n", array_reverse($downParts)) . "\n";

        // Delete originals first — handles re-squash where 000_squash.sql is already in $applied
        foreach ($applied as $filename) {
            unlink(rtrim($this->path, '/') . '/' . $filename);
            echo "[REMOVED] $filename\n";
        }

        file_put_contents($squashFile, $squashContent);

        $this->pdo->exec("DELETE FROM {$this->table}");
        $stmt = $this->pdo->prepare("INSERT INTO {$this->table} (filename, applied_at) VALUES (?, ?)");
        $stmt->execute([$squashName, date('Y-m-d H:i:s')]);

        echo "[SQUASHED] $squashName\n";
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
