<?php

declare(strict_types=1);

namespace Janoga\Scheduling\Tests\Unit;

use Janoga\Scheduling\Mutex\PdoMutex;
use Janoga\Scheduling\Tests\Fixtures\FrozenClock;
use PDO;
use PHPUnit\Framework\TestCase;

final class PdoMutexTest extends TestCase
{
    private const TABLE = 'scheduler_locks_test';

    private PDO $pdo;

    private FrozenClock $clock;

    private PdoMutex $mutex;

    protected function setUp(): void
    {
        $this->pdo = self::connect();
        $this->pdo->exec('DROP TABLE IF EXISTS '.self::TABLE);
        $this->pdo->exec(
            'CREATE TABLE '.self::TABLE.' (lock_key VARCHAR(191) PRIMARY KEY, owner CHAR(32) NOT NULL, expires_at BIGINT NOT NULL)'
        );
        $this->clock = new FrozenClock();
        $this->mutex = new PdoMutex(fn (): PDO => $this->pdo, $this->clock, self::TABLE);
    }

    /**
     * In-memory SQLite unless the environment (Docker) points the tests at MySQL.
     */
    private static function connect(): PDO
    {
        if (getenv('DB_CONNECTION') !== 'mysql') {
            return new PDO('sqlite::memory:');
        }

        return new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s', getenv('DB_HOST'), getenv('DB_PORT') ?: '3306', getenv('DB_DATABASE')),
            getenv('DB_USERNAME') ?: 'root',
            getenv('DB_PASSWORD') ?: '',
        );
    }

    public function test_only_one_holder_gets_a_free_key(): void
    {
        $first = $this->mutex->acquire('report', 60);
        $second = $this->mutex->acquire('report', 60);

        self::assertNotNull($first);
        self::assertNull($second);
        self::assertTrue($this->mutex->isLocked('report'));
        self::assertNotNull($this->mutex->acquire('another-key', 60));
    }

    public function test_release_frees_the_key_for_the_next_holder(): void
    {
        $lock = $this->mutex->acquire('report', 60);
        self::assertNotNull($lock);

        self::assertTrue($this->mutex->release($lock));
        self::assertFalse($this->mutex->isLocked('report'));
        self::assertNotNull($this->mutex->acquire('report', 60));
    }

    public function test_an_expired_lock_is_taken_over_and_its_stale_owner_cannot_release_it(): void
    {
        $stale = $this->mutex->acquire('report', 60);
        self::assertNotNull($stale);

        $this->clock->advance(59);
        self::assertNull($this->mutex->acquire('report', 60), 'The lock is still valid one second before expiry.');

        $this->clock->advance(1);
        self::assertFalse($this->mutex->isLocked('report'));
        $current = $this->mutex->acquire('report', 60);
        self::assertNotNull($current);

        self::assertFalse($this->mutex->release($stale));
        self::assertTrue($this->mutex->isLocked('report'), 'The stale owner must not release the new holder\'s lock.');
        self::assertTrue($this->mutex->release($current));
    }

    public function test_the_holder_can_extend_its_lock_beyond_the_original_lifetime(): void
    {
        $lock = $this->mutex->acquire('report', 60);
        self::assertNotNull($lock);

        $this->clock->advance(50);
        $extended = $this->mutex->extend($lock, 60);

        self::assertNotNull($extended);
        self::assertEquals($this->clock->now()->modify('+60 seconds'), $extended->expiresAt);

        $this->clock->advance(30);
        self::assertNull($this->mutex->acquire('report', 60), 'Still held 80 seconds after it was acquired.');
        self::assertTrue($this->mutex->release($extended));
    }

    public function test_extending_to_the_expiry_the_lock_already_has_still_succeeds(): void
    {
        $lock = $this->mutex->acquire('report', 60);
        self::assertNotNull($lock);

        self::assertNotNull($this->mutex->extend($lock, 60));
    }

    public function test_a_lock_that_was_taken_over_cannot_be_extended(): void
    {
        $stale = $this->mutex->acquire('report', 60);
        self::assertNotNull($stale);

        $this->clock->advance(60);
        $current = $this->mutex->acquire('report', 60);
        self::assertNotNull($current);

        self::assertNull($this->mutex->extend($stale, 600));

        $this->clock->advance(60);
        self::assertFalse($this->mutex->isLocked('report'), 'The new holder\'s expiry was not touched.');
    }

    public function test_force_release_ignores_ownership(): void
    {
        $this->mutex->acquire('report', 60);

        $this->mutex->forceRelease('report');

        self::assertFalse($this->mutex->isLocked('report'));
    }

    public function test_the_connection_is_opened_on_first_use_not_on_construction(): void
    {
        $opened = 0;
        $mutex = new PdoMutex(function () use (&$opened): PDO {
            $opened++;

            return $this->pdo;
        }, $this->clock, self::TABLE);

        self::assertSame(0, $opened);

        $mutex->isLocked('report');
        $mutex->isLocked('report');

        self::assertSame(1, $opened);
    }
}
