<?php

declare(strict_types=1);

namespace Mig\Tests\Command;

use Mig\Command\Create;
use Mig\MigrationFiles;
use Mig\Tests\TestCase;

class CreateTest extends TestCase
{
    public function test_creates_stub_file(): void
    {
        $output = $this->capture(fn () => (new Create($this->files()))->run());

        $created = glob($this->path . '/*.sql');
        $this->assertCount(1, $created);
        $this->assertMatchesRegularExpression('/^\d{14}\.sql$/', basename($created[0]));
        $this->assertStringContainsString('[CREATED] ' . basename($created[0]), $output);
        $this->assertSame(
            "-- mig:up\n\n\n-- mig:down\n\n",
            file_get_contents($created[0]),
        );
    }

    public function test_creates_directory_when_missing(): void
    {
        $path  = $this->path . '/nested';
        $files = new MigrationFiles($path);

        $this->capture(fn () => (new Create($files))->run());

        $this->assertCount(1, glob($path . '/*.sql'));

        foreach (glob($path . '/*.sql') ?: [] as $file) {
            unlink($file);
        }
        rmdir($path);
    }
}
