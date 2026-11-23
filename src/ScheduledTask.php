<?php

declare(strict_types=1);

namespace Janoga\Scheduling;

use DateTimeZone;
use InvalidArgumentException;
use Janoga\Scheduling\Action\RunCommand;
use Janoga\Scheduling\Action\RunJob;

final readonly class ScheduledTask
{
    /**
     * @param  non-empty-string  $name  Unique and stable. It identifies the task in logs, and the
     *                                  overlap lock is keyed by it rather than by the schedule or
     *                                  the action, which may change between deployments.
     * @param  DateTimeZone|null  $timezone  Zone the expression is evaluated in; null means the
     *                                       scheduler's default.
     * @param  positive-int|null  $overlapLockSeconds  Null allows overlapping runs. A number prevents
     *                                                 them and is the longest the lock may live if the
     *                                                 process dies before releasing it, so it must
     *                                                 exceed the longest expected run.
     */
    public function __construct(
        public string $name,
        public CronExpression $schedule,
        public RunCommand|RunJob $action,
        public ?DateTimeZone $timezone = null,
        public ?int $overlapLockSeconds = null,
    ) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,99}$/', $name) !== 1) {
            throw new InvalidArgumentException(
                "Task name [{$name}] must be 1-100 characters of letters, digits, '.', '_', ':' or '-'."
            );
        }

        if ($overlapLockSeconds !== null && $overlapLockSeconds < 1) {
            throw new InvalidArgumentException('The overlap lock lifetime must be at least one second.');
        }
    }
}
