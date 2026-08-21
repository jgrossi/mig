<?php

declare(strict_types=1);

namespace Mig\Command;

use Mig\MigrationFiles;
use Mig\MigrationStore;
use Mig\SqlParser;
use PDO;

class Up implements Command
{
    public function __construct(
        private PDO $pdo,
        private MigrationStore $store,
        private MigrationFiles $files,
    ) {
    }

    public function run(): void
    {
        $names = $this->files->names();

        if ($names === []) {
            echo "No migration files found in " . $this->files->dir() . "\n";
            return;
        }

        $applied = array_flip(array_keys($this->store->applied()));

        foreach ($names as $name) {
            if (isset($applied[$name])) {
                echo "[SKIP] $name\n";
                continue;
            }

            $sql = SqlParser::parse($this->files->read($name), 'up');

            // empty section: no-op
            if (trim($sql) !== '') {
                $this->pdo->exec($sql);
            }

            $this->store->add($name);

            echo "[OK]   $name\n";
        }

        echo "Done.\n";
    }
}
