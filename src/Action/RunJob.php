<?php

declare(strict_types=1);

namespace Janoga\Scheduling\Action;

use InvalidArgumentException;
use Janoga\Scheduling\Job;

final readonly class RunJob
{
    /**
     * @param  class-string<Job>  $job  A class name, not an instance or a closure: it is built only
     *                                  when the task runs, and it can cross a process boundary.
     */
    public function __construct(public string $job)
    {
        if (! is_subclass_of($job, Job::class)) {
            throw new InvalidArgumentException("[{$job}] must be a class implementing ".Job::class.'.');
        }
    }
}
