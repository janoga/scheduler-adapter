<?php

declare(strict_types=1);

namespace Janoga\Scheduling\Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class CoreIsFrameworkFreeTest extends TestCase
{
    public function test_nothing_outside_the_laravel_adapter_refers_to_laravel(): void
    {
        $src = dirname(__DIR__, 2).'/src';
        $offenders = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (str_starts_with($file->getPathname(), $src.'/Laravel/')) {
                continue;
            }

            if (preg_match('/\b(Illuminate|Laravel|Cron)\\\\/', (string) file_get_contents($file->getPathname())) === 1) {
                $offenders[] = substr($file->getPathname(), strlen($src) + 1);
            }
        }

        self::assertSame([], $offenders);
    }
}
