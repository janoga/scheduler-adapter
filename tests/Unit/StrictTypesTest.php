<?php

declare(strict_types=1);

namespace Janoga\Scheduling\Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class StrictTypesTest extends TestCase
{
    public function test_every_source_file_declares_strict_types(): void
    {
        $src = dirname(__DIR__, 2).'/src';
        $offenders = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS)) as $file) {
            // strict_types is per file and must be the first statement; only comments may precede it.
            $declared = preg_match(
                '/^<\?php\s+(?:(?:\/\/[^\n]*|#[^\n]*|\/\*.*?\*\/)\s*)*declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;/s',
                (string) file_get_contents($file->getPathname()),
            );

            if ($declared !== 1) {
                $offenders[] = substr($file->getPathname(), strlen($src) + 1);
            }
        }

        self::assertSame([], $offenders);
    }
}
