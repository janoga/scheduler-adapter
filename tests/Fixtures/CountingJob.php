<?php

declare(strict_types=1);

namespace Janoga\Scheduling\Tests\Fixtures;

use Closure;
use Janoga\Scheduling\Job;

final class CountingJob implements Job
{
    public static int $runs = 0;

    /** @var (Closure(): void)|null */
    public static ?Closure $whileRunning = null;

    public static function reset(): void
    {
        self::$runs = 0;
        self::$whileRunning = null;
    }

    public function handle(): void
    {
        self::$runs++;

        if (self::$whileRunning !== null) {
            (self::$whileRunning)();
        }
    }
}
