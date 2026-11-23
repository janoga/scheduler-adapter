<?php

declare(strict_types=1);

namespace Janoga\Scheduling\Laravel;

use Cron\CronExpression as CronEngine;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ProcessUtils;
use Janoga\Scheduling\Action\RunCommand;
use Janoga\Scheduling\Action\RunJob;
use Janoga\Scheduling\Exception\DuplicateTask;
use Janoga\Scheduling\Exception\InvalidCronExpression;
use Janoga\Scheduling\ScheduledTask;
use Janoga\Scheduling\Scheduler;

/**
 * Translates neutral task definitions into Laravel schedule events.
 */
final class LaravelScheduler implements Scheduler
{
    /** @var array<string, true> */
    private array $names = [];

    public function __construct(
        private readonly Schedule $schedule,
        private readonly Container $container,
        private readonly Kernel $console,
    ) {}

    public function add(ScheduledTask $task): void
    {
        if (isset($this->names[$task->name])) {
            throw DuplicateTask::named($task->name);
        }

        // Laravel stores the expression as a string and parses it on every run,
        // so without this check a bad expression would fail once a minute in production.
        if (! CronEngine::isValidExpression($task->schedule->value)) {
            throw InvalidCronExpression::notEvaluable($task->schedule->value, $task->name);
        }

        $event = $this->toEvent($task->action)
            ->name($task->name)
            ->cron($task->schedule->value);

        if ($task->timezone !== null) {
            $event->timezone($task->timezone);
        }

        if ($task->overlapLockSeconds !== null) {
            // Laravel names a command's mutex after its expression and command line, so editing
            // either one changes the lock's identity. A callback's mutex is already named after
            // the task name set above, and it ignores this resolver.
            $event->createMutexNameUsing("scheduler/{$task->name}");

            // Laravel counts in minutes; rounding up keeps the lock at least as long as requested.
            $event->withoutOverlapping((int) ceil($task->overlapLockSeconds / 60));
        }

        $this->names[$task->name] = true;
    }

    public function runDue(): void
    {
        $this->console->call('schedule:run');
    }

    private function toEvent(RunCommand|RunJob $action): Event
    {
        if ($action instanceof RunJob) {
            return $this->schedule->call(function () use ($action): void {
                $this->container->make($action->job)->handle();
            });
        }

        // The command runs as "php artisan ..." in a child process, hence the shell escaping.
        $arguments = array_map(ProcessUtils::escapeArgument(...), $action->arguments);

        return $this->schedule->command(implode(' ', [$action->command, ...$arguments]));
    }
}
