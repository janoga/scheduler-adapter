<?php

declare(strict_types=1);

namespace Janoga\Scheduling;

/**
 * A unit of work the scheduler runs in-process.
 *
 * Implementations are resolved from the application's container, so they may declare
 * constructor dependencies. Throwing marks the run as failed.
 */
interface Job
{
    public function handle(): void;
}
