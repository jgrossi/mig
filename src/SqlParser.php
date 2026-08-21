<?php

declare(strict_types=1);

namespace Mig;

use RuntimeException;

class SqlParser
{
    private const SECTION_PATTERN = '/--\s*mig:(up|down)\s*/i';

    public static function parse(string $content, string $direction): string
    {
        $parts = preg_split(self::SECTION_PATTERN, $content, -1, PREG_SPLIT_DELIM_CAPTURE);

        // $parts: [before, marker1, section1, marker2, section2, ...]
        $sections = [];
        for ($i = 1; $i < count($parts); $i += 2) {
            $sections[strtolower($parts[$i])] = trim($parts[$i + 1] ?? '');
        }

        if (!isset($sections[$direction])) {
            throw new RuntimeException("Missing '-- mig:$direction' section in migration file");
        }

        return $sections[$direction];
    }
}
