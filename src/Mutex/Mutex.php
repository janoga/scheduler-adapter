<?php

declare(strict_types=1);

namespace Janoga\Scheduling\Mutex;

/**
 * Mutual exclusion between processes, possibly on different servers.
 *
 * Implementations must make acquire() atomic: when several processes compete for a free key,
 * exactly one of them gets a Lock.
 */
interface Mutex
{
    /**
     * Tries to take the lock and returns immediately; it never waits for the current holder.
     *
     * @param  non-empty-string  $key
     * @param  positive-int  $ttlSeconds  A lock always expires. This is what frees it when the holder
     *                                    dies without releasing it.
     * @return Lock|null the proof of ownership, or null when another holder has the lock
     */
    public function acquire(string $key, int $ttlSeconds): ?Lock;

    /**
     * Prolongs a lock that is still owned by the given Lock; the new lifetime counts from now.
     *
     * A holder that keeps extending while it works can use a short lifetime: a long run stays
     * protected, and a dead holder's lock frees itself quickly.
     *
     * @param  positive-int  $ttlSeconds
     * @return Lock|null the lock with its new expiry, or null when another holder took it over
     *                   or it was released; nothing is changed then
     */
    public function extend(Lock $lock, int $ttlSeconds): ?Lock;

    /**
     * Releases the lock only if it is still owned by the given Lock.
     *
     * @return bool false when the lock had already expired or been taken over; nothing is released then
     */
    public function release(Lock $lock): bool;

    /**
     * Whether an unexpired lock exists for the key, whoever holds it.
     *
     * @param  non-empty-string  $key
     */
    public function isLocked(string $key): bool;

    /**
     * Removes the lock regardless of its owner. An operator's tool for a stuck lock, and the
     * only option for a process that did not acquire the lock itself.
     *
     * @param  non-empty-string  $key
     */
    public function forceRelease(string $key): void;
}
