# Scheduler and Mutex behind contracts

Two framework-neutral contracts, `Scheduler` and `Mutex`, with a working implementation on top of
Laravel 12. The application talks only to the contracts, so the Laravel scheduler can later be
replaced (for example by [crunz](https://github.com/crunzphp/crunz)) without touching application code.

## Layout

```
src/
  Scheduler.php            contract: add(ScheduledTask), runDue()
  ScheduledTask.php        what to run, when, and whether runs may overlap
  CronExpression.php       five-field crontab expression
  Action/RunCommand.php    run an application console command
  Action/RunJob.php        run a class implementing Job, in-process
  Job.php                  contract for such a class
  Mutex/Mutex.php          contract: acquire, extend, release, isLocked, forceRelease
  Mutex/Lock.php           proof of ownership returned by acquire()
  Mutex/PdoMutex.php       own mutex on a shared database table (plain PDO)
  Clock/SystemClock.php    PSR-20 clock used by PdoMutex
  Laravel/                 the only code that knows Laravel
    LaravelScheduler.php   Scheduler -> Illuminate\Console\Scheduling\Schedule
    EventMutexBridge.php   Laravel's EventMutex -> our Mutex
    SchedulingServiceProvider.php
demo/artisan               runnable demo (see below)
```

A test (`CoreIsFrameworkFreeTest`) fails if anything outside `src/Laravel` refers to Laravel.

## Usage

```php
use Janoga\Scheduling\Action\RunCommand;
use Janoga\Scheduling\Action\RunJob;
use Janoga\Scheduling\CronExpression;
use Janoga\Scheduling\ScheduledTask;
use Janoga\Scheduling\Scheduler;

// routes/console.php
$scheduler = app(Scheduler::class);

$scheduler->add(new ScheduledTask(
    name: 'orders.export',
    schedule: new CronExpression('*/15 * * * *'),
    action: new RunCommand('orders:export', ['eu', '--force']),
    overlapLockSeconds: 600,
));

$scheduler->add(new ScheduledTask(
    name: 'stock.sync',
    schedule: new CronExpression('0 3 * * *'),
    action: new RunJob(App\Jobs\SyncStock::class),
    timezone: new DateTimeZone('Europe/Prague'),
));
```

Run `php artisan migrate` once (it creates `scheduler_locks`) and keep Laravel's usual cron entry:

```
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

## Run it locally

Docker gives you PHP 8.5, Composer and MySQL 8.4; nothing else is needed on the host.

```
docker compose build
docker compose run --rm app composer install
docker compose run --rm app composer test                 # the whole suite against MySQL
docker compose run --rm app php demo/artisan migrate      # creates scheduler_locks
docker compose run --rm app php demo/artisan schedule:list
docker compose up                                         # demo worker, runs due tasks every minute
```

`demo/artisan` boots a throwaway Laravel application and registers two tasks through the
`Scheduler` contract: a job that runs every minute, and a command that takes 90 seconds but is
also due every minute. With its overlap lock, every second run of the command is skipped.

```
tail -f demo/demo.log
docker compose exec mysql mysql scheduler -e "SELECT lock_key, owner, FROM_UNIXTIME(expires_at) FROM scheduler_locks"
```

Without Docker (PHP 8.3+ with `pdo_sqlite`, Composer): `composer install && composer test` runs
the suite on in-memory SQLite, and `php demo/artisan ...` uses a local SQLite file.

The Laravel tests boot a real application through Orchestra Testbench and run `schedule:run`.

## Design decisions

| Decision | Alternative considered | Why |
|---|---|---|
| A task is data (`ScheduledTask`), registered with `add()` | A fluent builder like Laravel's `->command()->cron()->withoutOverlapping()` | A builder would copy one library's API into the contract. Data is validated once, is immutable, and maps to any engine. A new option is a new named constructor argument. |
| A task has a mandatory unique `name` | Derive identity from the expression and command, as Laravel does | Laravel's default mutex name is a hash of the expression and the command line, so editing either changes the lock while a run may be in progress. A name is stable and readable in logs. |
| `overlapLockSeconds` is `?int`, not a boolean | `withoutOverlapping: true` plus a default lifetime | Laravel's default lock lives 24 hours, so a crashed task blocks itself for a day. Here overlap protection cannot be switched on without choosing the lifetime. |
| `RunJob` takes a class name | A closure or an object | A class name is built only when the task runs and can be passed to another process, which an engine that runs tasks in child processes needs. |
| `RunCommand` arguments are command-line tokens | Laravel's mixed array of arguments and `--option => value` | Tokens mean the same to every console. The adapter escapes each one; Laravel leaves values starting with `--` unescaped. |
| `CronExpression` checks only the shape; the adapter asks the engine | Validate fully in the core | Full validation needs a cron library in the core. Laravel itself does not validate at all, so the adapter does it at registration instead of failing every minute at run time. |
| `Mutex::acquire()` returns a `Lock` with an owner token | `acquire(): bool` and `release(key)` | Without a token a process whose lock expired can delete the lock of the next holder. Laravel's `CacheEventMutex::forget()` does exactly that (`forceRelease`). |
| `acquire()` never waits and always takes a TTL | Blocking acquire; locks without expiry | A scheduler should skip a run, not queue behind it. The TTL is the only thing that frees a lock after a crash. |
| `extend()` lets the holder prolong its lock | One fixed lifetime per lock | A fixed lifetime has to cover the worst-case run, which makes recovery after a crash slow. With extension the lifetime can be short. crunz refreshes its locks this way while a task runs; Laravel's scheduler cannot (see the limits). |
| The mutex is plugged in through Laravel's `EventMutex` binding | Wrap every task in our own acquire/release | `Schedule` resolves `EventMutex` from the container, so this is the supported extension point; `schedule:list`, `schedule:clear-cache` and the skip events keep working. |
| `PdoMutex` on a database table | Redis (`SET NX PX`), `flock`, MySQL `GET_LOCK` | Every server already shares the database, it needs no Laravel code, and it is testable without infrastructure. Redis is the natural second implementation of the same contract. |

## Known limits

- **Not modelled:** running a task once per minute across several servers (Laravel's `onOneServer`)
  and background execution. Overlap protection only prevents two runs at the same time.
- Under Laravel nothing calls `Mutex::extend()`: the scheduler blocks while a command runs and
  `EventMutex` offers no hook for it. A run that outlives `overlapLockSeconds` can therefore
  overlap with the next one, so set it well above the worst case.
- `EventMutexBridge` can only check ownership inside the process that acquired the lock. From
  another process (`schedule:clear-cache`, `schedule:finish`) it force-releases.
- `PdoMutex` uses the application clock and the application's default connection: servers need
  synchronised clocks, and a lock must not be taken inside an open transaction.
- `PdoMutex` is tested on MySQL 8.4 and SQLite. PostgreSQL is untested, and inside a transaction
  it would abort on the duplicate-key insert that `acquire()` relies on.
- Lock keys differ by action: `scheduler/<name>` for commands, Laravel's own
  `framework/schedule-<sha1 of name>` for jobs, because `CallbackEvent` ignores a custom mutex name.

## Switching to crunz (not implemented)

A second adapter would implement the same two contracts:

- `Scheduler::add()` collects the tasks and one crunz task file returns a crunz `Schedule` built from them (`run()`, `cron()`).
- `RunJob` needs a console entry point that runs a job by class name, because crunz starts each task as a separate process.
- `Mutex` is wrapped as a Symfony Lock store and passed to crunz's `preventOverlapping($store)`;
  `extend()` backs the store's `putOffExpiration()`, which crunz uses to refresh the lock of a running task.
- `Scheduler::runDue()` starts crunz's `schedule:run`.
