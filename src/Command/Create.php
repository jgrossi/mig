<?php

declare(strict_types=1);

namespace Mig\Command;

use Mig\MigrationFiles;

class Create implements Command
{
    public function __construct(private MigrationFiles $files)
    {
    }

    public function run(): void
    {
        $name = date('YmdHis') . '.sql';

        $this->files->write($name, "-- mig:up\n\n\n-- mig:down\n\n");

        echo "[CREATED] $name\n";
    }
}
