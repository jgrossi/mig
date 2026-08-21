<?php

declare(strict_types=1);

namespace Mig\Command;

use Mig\MigrationFiles;
use Mig\MigrationStore;
use Mig\SqlParser;
use PDO;

class Down implements Command
{
    public function __construct(
        private PDO $pdo,
        private MigrationStore $store,
        private MigrationFiles $files,
        private int $steps = 1,
    ) {
    }

    public function run(): void
    {
        $names = $this->store->latest($this->steps);

        if ($names === []) {
            echo "Nothing to roll back.\n";
            return;
        }

        foreach ($names as $name) {
            $this->revert($name);
        }
    }

    public function runAll(): void
    {
        foreach ($this->store->latest() as $name) {
            $this->revert($name);
        }
    }

    private function revert(string $name): void
    {
        $sql = SqlParser::parse($this->files->read($name), 'down');

        // empty section: no-op
        if (trim($sql) !== '') {
            $this->pdo->exec($sql);
        }

        $this->store->remove($name);

        echo "[DOWN] $name\n";
    }
}
