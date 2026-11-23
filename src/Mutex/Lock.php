<?php

declare(strict_types=1);

namespace Janoga\Scheduling\Mutex;

use DateTimeImmutable;

/**
 * Proof that a process acquired a lock. Only its holder can release that lock.
 */
final readonly class Lock
{
    /**
     * @param  non-empty-string  $key
     * @param  non-empty-string  $owner  Random token generated on acquisition.
     */
    public function __construct(
        public string $key,
        public string $owner,
        public DateTimeImmutable $expiresAt,
    ) {}
}
