<?php

declare(strict_types=1);

namespace Janoga\Scheduling;

use Janoga\Scheduling\Exception\InvalidCronExpression;
use Stringable;

/**
 * A classic five-field crontab expression: minute, hour, day of month, month, day of week.
 *
 * Only the shape is checked here, so the core needs no cron library. Whether the values make
 * sense ("61 * * * *") is decided by the engine behind the adapter when the task is registered.
 * Macros such as "@daily" and a seconds field are rejected on purpose: not every engine has them.
 */
final readonly class CronExpression implements Stringable
{
    public string $value;

    public function __construct(string $expression)
    {
        $fields = preg_split('/\s+/', trim($expression), -1, PREG_SPLIT_NO_EMPTY);

        if ($fields === false || count($fields) !== 5) {
            throw InvalidCronExpression::wrongShape($expression);
        }

        foreach ($fields as $field) {
            if (preg_match('/^[0-9A-Za-z*\/,\-?#]+$/', $field) !== 1) {
                throw InvalidCronExpression::wrongShape($expression);
            }
        }

        $this->value = implode(' ', $fields);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
