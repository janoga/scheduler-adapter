<?php

declare(strict_types=1);

namespace Janoga\Scheduling\Exception;

use InvalidArgumentException;

final class InvalidCronExpression extends InvalidArgumentException
{
    public static function wrongShape(string $expression): self
    {
        return new self("[{$expression}] is not a five-field crontab expression.");
    }

    public static function notEvaluable(string $expression, string $task): self
    {
        return new self("Task [{$task}]: the scheduler cannot evaluate the cron expression [{$expression}].");
    }
}
