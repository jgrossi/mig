<?php

declare(strict_types=1);

namespace Mig\Command;

class Refresh implements Command
{
    public function __construct(
        private Down $down,
        private Up $up,
    ) {
    }

    public function run(): void
    {
        $this->down->runAll();
        $this->up->run();
    }
}
