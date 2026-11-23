<?php

declare(strict_types=1);

namespace Janoga\Scheduling\Tests\Fixtures;

use Janoga\Scheduling\Job;
use RuntimeException;

final class FailingJob implements Job
{
    public function handle(): void
    {
        throw new RuntimeException('Simulated failure.');
    }
}
