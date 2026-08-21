<?php

declare(strict_types=1);

namespace Mig\Tests;

use Mig\SqlParser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SqlParserTest extends TestCase
{
    public function test_parses_up_and_down_sections(): void
    {
        $content = "-- mig:up\nCREATE TABLE users (id INTEGER PRIMARY KEY)\n-- mig:down\nDROP TABLE users\n";

        $this->assertSame('CREATE TABLE users (id INTEGER PRIMARY KEY)', SqlParser::parse($content, 'up'));
        $this->assertSame('DROP TABLE users', SqlParser::parse($content, 'down'));
    }

    public function test_markers_are_case_insensitive(): void
    {
        $content = "-- MIG:UP\nCREATE TABLE t (id INT)\n-- MIG:DOWN\nDROP TABLE t\n";

        $this->assertSame('CREATE TABLE t (id INT)', SqlParser::parse($content, 'up'));
    }

    public function test_trims_surrounding_whitespace(): void
    {
        $content = "-- mig:up\n\n   CREATE TABLE t (id INT)   \n\n-- mig:down\nDROP TABLE t\n";

        $this->assertSame('CREATE TABLE t (id INT)', SqlParser::parse($content, 'up'));
    }

    public function test_throws_when_section_missing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Missing '-- mig:down'");

        SqlParser::parse("-- mig:up\nSELECT 1\n", 'down');
    }
}
