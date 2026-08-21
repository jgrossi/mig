<?php

declare(strict_types=1);

namespace Mig\Command;

interface Command
{
    public function run(): void;
}
