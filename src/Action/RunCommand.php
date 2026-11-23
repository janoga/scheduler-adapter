<?php

declare(strict_types=1);

namespace Janoga\Scheduling\Action;

use InvalidArgumentException;

final readonly class RunCommand
{
    /**
     * @param  non-empty-string  $command  Name of an application console command, e.g. "orders:export".
     * @param  list<string>  $arguments  Arguments and options exactly as typed on a command line, one
     *                                   token each: ['eu', '--force', '--chunk=500']. The adapter is
     *                                   responsible for escaping them.
     */
    public function __construct(
        public string $command,
        public array $arguments = [],
    ) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9:._-]*$/', $command) !== 1) {
            throw new InvalidArgumentException("[{$command}] is not a valid console command name.");
        }

        if (! array_is_list($arguments)) {
            throw new InvalidArgumentException('Command arguments must be a list of command-line tokens.');
        }

        foreach ($arguments as $argument) {
            if (! is_string($argument)) {
                throw new InvalidArgumentException('Command arguments must be strings.');
            }
        }
    }
}
