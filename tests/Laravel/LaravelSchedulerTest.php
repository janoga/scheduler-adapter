<?php

declare(strict_types=1);

namespace Janoga\Scheduling\Tests\Laravel;

use DateTimeZone;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Janoga\Scheduling\Action\RunCommand;
use Janoga\Scheduling\Action\RunJob;
use Janoga\Scheduling\CronExpression;
use Janoga\Scheduling\Exception\DuplicateTask;
use Janoga\Scheduling\Exception\InvalidCronExpression;
use Janoga\Scheduling\Laravel\SchedulingServiceProvider;
use Janoga\Scheduling\Mutex\Mutex;
use Janoga\Scheduling\ScheduledTask;
use Janoga\Scheduling\Scheduler;
use Janoga\Scheduling\Tests\Fixtures\CountingJob;
use Janoga\Scheduling\Tests\Fixtures\FailingJob;
use Orchestra\Testbench\TestCase;

final class LaravelSchedulerTest extends TestCase
{
    use RefreshDatabase;

    private Scheduler $scheduler;

    protected function setUp(): void
    {
        parent::setUp();

        CountingJob::reset();
        $this->scheduler = $this->app->make(Scheduler::class);
    }

    protected function getPackageProviders($app): array
    {
        return [SchedulingServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.timezone', 'UTC');
        // In-memory SQLite unless the environment (Docker) points the tests at a real database.
        $app['config']->set('database.default', env('DB_CONNECTION', 'testing'));
    }

    public function test_a_job_runs_in_the_minute_its_expression_matches_and_not_before(): void
    {
        $this->scheduler->add(new ScheduledTask('counter', new CronExpression('5 10 * * *'), new RunJob(CountingJob::class)));

        $this->travelTo('2026-11-23 10:04:00');
        $this->scheduler->runDue();
        self::assertSame(0, CountingJob::$runs);

        $this->travelTo('2026-11-23 10:05:00');
        $this->scheduler->runDue();
        self::assertSame(1, CountingJob::$runs);
    }

    public function test_the_expression_is_evaluated_in_the_task_timezone(): void
    {
        $this->scheduler->add(new ScheduledTask(
            name: 'morning-report',
            schedule: new CronExpression('0 9 * * *'),
            action: new RunJob(CountingJob::class),
            timezone: new DateTimeZone('Europe/Prague'),
        ));

        $this->travelTo('2026-11-23 09:00:00');
        $this->scheduler->runDue();
        self::assertSame(0, CountingJob::$runs, '09:00 UTC is 10:00 in Prague.');

        $this->travelTo('2026-11-23 08:00:00');
        $this->scheduler->runDue();
        self::assertSame(1, CountingJob::$runs);
    }

    public function test_overlap_protection_holds_our_mutex_during_the_run_and_skips_while_it_is_held_elsewhere(): void
    {
        $this->scheduler->add(new ScheduledTask(
            name: 'export',
            schedule: new CronExpression('* * * * *'),
            action: new RunJob(CountingJob::class),
            overlapLockSeconds: 90,
        ));
        $mutex = $this->app->make(Mutex::class);
        $key = $this->onlyEvent()->mutexName();

        $lockedWhileRunning = null;
        CountingJob::$whileRunning = static function () use ($mutex, $key, &$lockedWhileRunning): void {
            $lockedWhileRunning = $mutex->isLocked($key);
        };

        $this->scheduler->runDue();

        self::assertSame(1, CountingJob::$runs);
        self::assertTrue($lockedWhileRunning);
        self::assertFalse($mutex->isLocked($key), 'The lock is released when the run finishes.');

        $heldByAnotherProcess = $mutex->acquire($key, 60);
        self::assertNotNull($heldByAnotherProcess);
        $this->scheduler->runDue();
        self::assertSame(1, CountingJob::$runs, 'The run is skipped while another process holds the lock.');

        $mutex->release($heldByAnotherProcess);
        $this->scheduler->runDue();
        self::assertSame(2, CountingJob::$runs);
    }

    public function test_the_lock_lifetime_is_rounded_up_to_laravels_whole_minutes(): void
    {
        $this->scheduler->add(new ScheduledTask(
            name: 'export',
            schedule: new CronExpression('* * * * *'),
            action: new RunJob(CountingJob::class),
            overlapLockSeconds: 90,
        ));

        self::assertSame(2, $this->onlyEvent()->expiresAt);
    }

    public function test_a_failing_job_does_not_stop_the_other_due_jobs(): void
    {
        $this->scheduler->add(new ScheduledTask('broken', new CronExpression('* * * * *'), new RunJob(FailingJob::class)));
        $this->scheduler->add(new ScheduledTask('counter', new CronExpression('* * * * *'), new RunJob(CountingJob::class)));

        $this->scheduler->runDue();

        self::assertSame(1, CountingJob::$runs);
    }

    public function test_a_command_becomes_an_artisan_call_with_escaped_arguments_and_a_name_based_lock(): void
    {
        $this->scheduler->add(new ScheduledTask(
            name: 'orders.export',
            schedule: new CronExpression('*/15 * * * *'),
            action: new RunCommand('orders:export', ['eu', '--force', '--note=it\'s; rm -rf /']),
            overlapLockSeconds: 600,
        ));

        $event = $this->onlyEvent();

        self::assertStringEndsWith(
            "artisan' orders:export 'eu' '--force' '--note=it'\\''s; rm -rf /'",
            (string) $event->command,
        );
        self::assertSame('*/15 * * * *', $event->expression);
        self::assertSame('orders.export', $event->description);
        self::assertSame('scheduler/orders.export', $event->mutexName());
        self::assertSame(10, $event->expiresAt);
    }

    public function test_a_duplicate_name_is_rejected_at_registration(): void
    {
        $task = new ScheduledTask('counter', new CronExpression('* * * * *'), new RunJob(CountingJob::class));
        $this->scheduler->add($task);

        $this->expectException(DuplicateTask::class);

        $this->scheduler->add($task);
    }

    public function test_an_expression_the_engine_cannot_evaluate_is_rejected_at_registration(): void
    {
        $this->expectException(InvalidCronExpression::class);

        $this->scheduler->add(new ScheduledTask('never', new CronExpression('61 * * * *'), new RunJob(CountingJob::class)));
    }

    public function test_laravels_clear_cache_command_removes_a_lock_left_by_another_process(): void
    {
        $this->scheduler->add(new ScheduledTask(
            name: 'export',
            schedule: new CronExpression('* * * * *'),
            action: new RunJob(CountingJob::class),
            overlapLockSeconds: 3600,
        ));
        $mutex = $this->app->make(Mutex::class);
        $key = $this->onlyEvent()->mutexName();
        $mutex->acquire($key, 3600);

        $this->artisan('schedule:clear-cache')->assertSuccessful();

        self::assertFalse($mutex->isLocked($key));
    }

    private function onlyEvent(): Event
    {
        $events = $this->app->make(Schedule::class)->events();
        self::assertCount(1, $events);

        return $events[0];
    }
}
