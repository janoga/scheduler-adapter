<?php

declare(strict_types=1);

namespace Janoga\Scheduling;

use Janoga\Scheduling\Exception\DuplicateTask;
use Janoga\Scheduling\Exception\InvalidCronExpression;

/**
 * The only scheduling API the application talks to.
 *
 * A task is described as data ({@see ScheduledTask}) rather than through a fluent builder,
 * so nothing in this contract mirrors the API of one particular scheduling library.
 */
interface Scheduler
{
    /**
     * Registers a task. Definitions are rejected here, at registration, never at run time.
     *
     * @throws DuplicateTask when a task with the same name is already registered
     * @throws InvalidCronExpression when the underlying engine cannot evaluate the expression
     */
    public function add(ScheduledTask $task): void;

    /**
     * Runs every registered task that is due in the current minute.
     *
     * Meant to be triggered once a minute by the system cron. A failing task must not prevent
     * the remaining due tasks from running; failures are reported through the host application.
     */
    public function runDue(): void;
}
