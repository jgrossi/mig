<?php

declare(strict_types=1);

namespace Mig;

use RuntimeException;

class MigrationFiles
{
    public function __construct(private string $path)
    {
    }

    /**
     * @return list<string> sorted *.sql basenames in the migrations directory
     */
    public function names(): array
    {
        $names = array_map('basename', glob($this->dir() . '/*.sql') ?: []);
        sort($names);

        return $names;
    }

    public function exists(string $name): bool
    {
        return file_exists($this->dir() . '/' . $name);
    }

    public function read(string $name): string
    {
        if (!$this->exists($name)) {
            throw new RuntimeException('Migration file not found: ' . $this->dir() . '/' . $name);
        }

        return file_get_contents($this->dir() . '/' . $name);
    }

    public function write(string $name, string $content): void
    {
        if (!is_dir($this->dir())) {
            mkdir($this->dir(), 0755, true);
        }

        file_put_contents($this->dir() . '/' . $name, $content);
    }

    public function delete(string $name): void
    {
        unlink($this->dir() . '/' . $name);
    }

    public function dir(): string
    {
        return rtrim($this->path, '/');
    }
}
