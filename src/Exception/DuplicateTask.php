<?php

declare(strict_types=1);

namespace Janoga\Scheduling\Exception;

use LogicException;

final class DuplicateTask extends LogicException
{
    public static function named(string $name): self
    {
        return new self("A task named [{$name}] is already registered.");
    }
}
