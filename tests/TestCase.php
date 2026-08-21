<?php

declare(strict_types=1);

namespace Mig\Tests;

use Mig\MigrationFiles;
use Mig\MigrationStore;
use PDO;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

abstract class TestCase extends PHPUnitTestCase
{
    protected PDO $pdo;
    protected string $path;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->path = sys_get_temp_dir() . '/mig_test_' . uniqid();
        mkdir($this->path);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->path . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->path);
    }

    protected function writeMigration(string $name, string $up, string $down): void
    {
        file_put_contents(
            $this->path . '/' . $name,
            "-- mig:up\n{$up}\n-- mig:down\n{$down}\n",
        );
    }

    protected function capture(callable $fn): string
    {
        ob_start();

        try {
            $fn();
        } finally {
            $output = ob_get_clean();
        }

        return $output;
    }

    protected function store(?string $table = null): MigrationStore
    {
        return new MigrationStore($this->pdo, $table ?? 'migrations');
    }

    protected function files(): MigrationFiles
    {
        return new MigrationFiles($this->path);
    }
}
