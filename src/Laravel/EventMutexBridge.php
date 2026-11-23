<?php

declare(strict_types=1);

namespace Janoga\Scheduling\Laravel;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Janoga\Scheduling\Mutex\Lock;
use Janoga\Scheduling\Mutex\Mutex;

/**
 * Makes Laravel's scheduler take its overlap locks from our Mutex instead of the cache.
 *
 * Laravel resolves EventMutex from the container when it builds the Schedule, so binding this
 * class there replaces the default CacheEventMutex for every scheduled event in the application.
 */
final class EventMutexBridge implements EventMutex
{
    /** @var array<string, Lock> locks acquired by this process, by key */
    private array $held = [];

    public function __construct(private readonly Mutex $mutex) {}

    public function create(Event $event): bool
    {
        $lock = $this->mutex->acquire($event->mutexName(), $event->expiresAt * 60);

        if ($lock === null) {
            return false;
        }

        $this->held[$lock->key] = $lock;

        return true;
    }

    public function exists(Event $event): bool
    {
        return $this->mutex->isLocked($event->mutexName());
    }

    public function forget(Event $event): void
    {
        $key = $event->mutexName();
        $lock = $this->held[$key] ?? null;
        unset($this->held[$key]);

        if ($lock !== null) {
            $this->mutex->release($lock);

            return;
        }

        // Laravel also calls forget() from processes that never acquired the lock:
        // "schedule:clear-cache", and "schedule:finish" after a task that ran in the background.
        $this->mutex->forceRelease($key);
    }
}
