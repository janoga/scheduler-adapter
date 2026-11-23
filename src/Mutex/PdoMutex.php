<?php

declare(strict_types=1);

namespace Janoga\Scheduling\Mutex;

use Closure;
use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;
use Psr\Clock\ClockInterface;
use RuntimeException;

/**
 * Mutex backed by one table in a database every server shares.
 *
 * Atomicity comes from the primary key on lock_key: of several concurrent INSERTs for the same
 * key only one succeeds. An expired lock is taken over with a conditional UPDATE, again atomic.
 *
 * Expected table (see database/migrations):
 *   lock_key VARCHAR(191) PRIMARY KEY, owner CHAR(32) NOT NULL, expires_at BIGINT NOT NULL
 *
 * Expiry uses the application clock, so the servers' clocks have to be in sync (NTP).
 */
final class PdoMutex implements Mutex
{
    private const MAX_KEY_LENGTH = 191;

    private ?PDO $pdo = null;

    /**
     * @param  Closure(): PDO  $connection  Called on first use, so building the mutex never opens a
     *                                      database connection.
     */
    public function __construct(
        private readonly Closure $connection,
        private readonly ClockInterface $clock,
        private readonly string $table = 'scheduler_locks',
    ) {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
            throw new InvalidArgumentException("[{$table}] is not a valid table name.");
        }
    }

    public function acquire(string $key, int $ttlSeconds): ?Lock
    {
        $this->assertKey($key);

        if ($ttlSeconds < 1) {
            throw new InvalidArgumentException('A lock must live for at least one second.');
        }

        $now = $this->clock->now();
        $expiresAt = $now->modify("+{$ttlSeconds} seconds");
        $owner = bin2hex(random_bytes(16));

        $inserted = $this->run(
            "INSERT INTO {$this->table} (lock_key, owner, expires_at) VALUES (?, ?, ?)",
            [$key, $owner, $expiresAt->getTimestamp()],
            tolerateDuplicateKey: true,
        );

        if ($inserted === null) {
            $takenOver = $this->run(
                "UPDATE {$this->table} SET owner = ?, expires_at = ? WHERE lock_key = ? AND expires_at <= ?",
                [$owner, $expiresAt->getTimestamp(), $key, $now->getTimestamp()],
            );

            if ($takenOver?->rowCount() !== 1) {
                return null;
            }
        }

        return new Lock($key, $owner, $expiresAt);
    }

    public function extend(Lock $lock, int $ttlSeconds): ?Lock
    {
        if ($ttlSeconds < 1) {
            throw new InvalidArgumentException('A lock must live for at least one second.');
        }

        $expiresAt = $this->clock->now()->modify("+{$ttlSeconds} seconds");

        $updated = $this->run(
            "UPDATE {$this->table} SET expires_at = ? WHERE lock_key = ? AND owner = ?",
            [$expiresAt->getTimestamp(), $lock->key, $lock->owner],
        );

        // MySQL reports changed rows, not matched ones: extending to the expiry the lock already
        // has changes nothing and counts as zero, so ownership is confirmed with a read.
        if ($updated?->rowCount() !== 1 && ! $this->isOwnedBy($lock)) {
            return null;
        }

        return new Lock($lock->key, $lock->owner, $expiresAt);
    }

    public function release(Lock $lock): bool
    {
        $deleted = $this->run(
            "DELETE FROM {$this->table} WHERE lock_key = ? AND owner = ?",
            [$lock->key, $lock->owner],
        );

        return $deleted?->rowCount() === 1;
    }

    public function isLocked(string $key): bool
    {
        $this->assertKey($key);

        $found = $this->run(
            "SELECT 1 FROM {$this->table} WHERE lock_key = ? AND expires_at > ?",
            [$key, $this->clock->now()->getTimestamp()],
        );

        return $found?->fetchColumn() !== false;
    }

    public function forceRelease(string $key): void
    {
        $this->assertKey($key);

        $this->run("DELETE FROM {$this->table} WHERE lock_key = ?", [$key]);
    }

    /**
     * @param  list<string|int>  $bindings
     * @return PDOStatement|null null only for a tolerated duplicate key
     */
    private function run(string $sql, array $bindings, bool $tolerateDuplicateKey = false): ?PDOStatement
    {
        $statement = null;

        try {
            $statement = $this->pdo()->prepare($sql);

            if ($statement !== false && $statement->execute($bindings)) {
                return $statement;
            }

            $sqlState = $statement === false ? $this->pdo()->errorCode() : $statement->errorCode();
        } catch (PDOException $exception) {
            $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        }

        // SQLSTATE class 23 = integrity constraint violation, here always the primary key.
        if ($tolerateDuplicateKey && str_starts_with((string) $sqlState, '23')) {
            return null;
        }

        throw new RuntimeException(
            "Mutex query failed with SQLSTATE [{$sqlState}].",
            previous: $exception ?? null,
        );
    }

    private function isOwnedBy(Lock $lock): bool
    {
        $found = $this->run(
            "SELECT 1 FROM {$this->table} WHERE lock_key = ? AND owner = ?",
            [$lock->key, $lock->owner],
        );

        return $found?->fetchColumn() !== false;
    }

    private function pdo(): PDO
    {
        return $this->pdo ??= ($this->connection)();
    }

    private function assertKey(string $key): void
    {
        if ($key === '' || strlen($key) > self::MAX_KEY_LENGTH) {
            throw new InvalidArgumentException('A lock key must be 1-'.self::MAX_KEY_LENGTH.' bytes long.');
        }
    }
}
