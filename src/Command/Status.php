<?php

declare(strict_types=1);

namespace Mig\Command;

use Mig\MigrationFiles;
use Mig\MigrationStore;

class Status implements Command
{
    public function __construct(
        private MigrationStore $store,
        private MigrationFiles $files,
    ) {
    }

    public function run(): void
    {
        $applied = $this->store->applied();
        $names   = array_values(array_unique(array_merge($this->files->names(), array_keys($applied))));
        sort($names);

        if ($names === []) {
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
}
