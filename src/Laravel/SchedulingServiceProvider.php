<?php

declare(strict_types=1);

namespace Janoga\Scheduling\Laravel;

use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Janoga\Scheduling\Clock\SystemClock;
use Janoga\Scheduling\Mutex\Mutex;
use Janoga\Scheduling\Mutex\PdoMutex;
use Janoga\Scheduling\Scheduler;

/**
 * The composition root: the one place that decides which implementations stand behind the contracts.
 */
final class SchedulingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Mutex::class, static fn (Application $app): Mutex => new PdoMutex(
            static fn (): \PDO => $app->make('db')->connection()->getPdo(),
            new SystemClock(),
        ));

        $this->app->singleton(EventMutex::class, static fn (Application $app): EventMutex => new EventMutexBridge(
            $app->make(Mutex::class),
        ));

        $this->app->singleton(Scheduler::class, static fn (Application $app): Scheduler => new LaravelScheduler(
            $app->make(Schedule::class),
            $app,
            $app->make(Kernel::class),
        ));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
