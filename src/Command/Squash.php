<?php

declare(strict_types=1);

namespace Mig\Command;

use Mig\MigrationFiles;
use Mig\MigrationStore;
use Mig\SqlParser;
use RuntimeException;

class Squash implements Command
{
    private const SQUASH_FILE = '000_squash.sql';

    public function __construct(
        private MigrationStore $store,
        private MigrationFiles $files,
    ) {
    }

    public function run(): void
    {
        // reverse to application order
        $applied = array_reverse($this->store->latest());

        if ($applied === []) {
            echo "Nothing to squash.\n";
            return;
        }

        $pending = array_diff($this->files->names(), $applied);

        if ($pending !== []) {
            throw new RuntimeException("Cannot squash: pending migrations exist. Run 'mig up' first.");
        }

        $upParts   = [];
        $downParts = [];

        foreach ($applied as $name) {
            $content     = $this->files->read($name);
            $upParts[]   = SqlParser::parse($content, 'up');
            $downParts[] = SqlParser::parse($content, 'down');
        }

        $squashContent = "-- mig:up\n" . implode("\n\n", $upParts)
                       . "\n\n-- mig:down\n" . implode("\n\n", array_reverse($downParts)) . "\n";

        // delete first — 000_squash.sql may already be applied
        foreach ($applied as $name) {
            $this->files->delete($name);
            echo "[REMOVED] $name\n";
        }

        $this->files->write(self::SQUASH_FILE, $squashContent);

        $this->store->clear();
        $this->store->add(self::SQUASH_FILE);

        echo "[SQUASHED] " . self::SQUASH_FILE . "\n";
    }
}
