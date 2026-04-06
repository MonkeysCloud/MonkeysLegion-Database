<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Connection;

use MonkeysLegion\Database\Config\DatabaseConfig;
use MonkeysLegion\Database\Config\PoolConfig;
use MonkeysLegion\Database\Contracts\ConnectionInterface;
use MonkeysLegion\Database\Contracts\ConnectionPoolInterface;
use MonkeysLegion\Database\Exceptions\PoolException;
use MonkeysLegion\Database\Support\ConnectionPoolStats;
use MonkeysLegion\Database\Support\HealthChecker;
use SplQueue;

/**
 * MonkeysLegion Framework — Database Package
 *
 * In-memory connection pool with health monitoring, idle eviction,
 * and max-lifetime enforcement.
 *
 * PHP 8.4 features used:
 *  • `new` in property initialiser for `SplQueue` (no constructor needed)
 *  • `array_any()` for concise idle-queue checks
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class ConnectionPool implements ConnectionPoolInterface
{
    /** @var SplQueue<array{connection: ConnectionInterface, createdAt: float, lastUsedAt: float}> */
    // PHP 8.4 — `new` in property initialiser replaces constructor assignment.
    private SplQueue $idle = new SplQueue();

    private int $activeCount = 0;
    private int $createdTotal = 0;

    /**
     * Tracks the wall-clock creation time for each pooled connection,
     * keyed by spl_object_id(). This enables correct max-lifetime enforcement
     * regardless of how many times the connection has been released and reacquired.
     *
     * @var array<int, float>
     */
    private array $connectionCreatedAt = [];

    public function __construct(
        private readonly DatabaseConfig $config,
        private readonly PoolConfig $poolConfig,
    ) {}

    // ── Pool Operations ─────────────────────────────────────────

    public function acquire(): ConnectionInterface
    {
        // 1. Evict stale idle connections first
        $this->evictStale();

        // 2. Try to reuse an idle connection
        while (!$this->idle->isEmpty()) {
            $entry = $this->idle->dequeue();
            $connection = $entry['connection'];

            // Check if the connection has exceeded its max lifetime
            if ($this->exceedsMaxLifetime($entry['createdAt'])) {
                $connection->disconnect();
                unset($this->connectionCreatedAt[spl_object_id($connection)]);
                continue;
            }

            // Optionally validate the connection before returning it
            if ($this->poolConfig->validateOnAcquire && !$connection->isAlive()) {
                $connection->disconnect();
                unset($this->connectionCreatedAt[spl_object_id($connection)]);
                continue;
            }

            $this->activeCount++;
            return $connection;
        }

        // 3. Create a new connection if pool is not exhausted
        $totalInUse = $this->activeCount + $this->idle->count();
        if ($totalInUse >= $this->poolConfig->maxConnections) {
            throw new PoolException(
                "Connection pool exhausted: {$this->activeCount} active, "
                . "{$this->idle->count()} idle, max {$this->poolConfig->maxConnections}",
                driver: $this->config->driver,
            );
        }

        $connection = $this->createConnection();
        $this->activeCount++;

        return $connection;
    }

    public function release(ConnectionInterface $connection): void
    {
        $this->activeCount = max(0, $this->activeCount - 1);

        // Don't return unhealthy connections to the pool
        if (!$connection->isConnected()) {
            unset($this->connectionCreatedAt[spl_object_id($connection)]);
            return;
        }

        // Force-rollback any dangling transaction
        if ($connection->inTransaction()) {
            try {
                $connection->rollBack();
            } catch (\Throwable) {
                $connection->disconnect();
                unset($this->connectionCreatedAt[spl_object_id($connection)]);
                return;
            }
        }

        // Use the original creation timestamp so max-lifetime is always relative
        // to when the connection was first opened, not when it was last released.
        $createdAt = $this->connectionCreatedAt[spl_object_id($connection)] ?? microtime(true);

        $this->idle->enqueue([
            'connection' => $connection,
            'createdAt'  => $createdAt,
            'lastUsedAt' => microtime(true),
        ]);
    }

    public function drain(): void
    {
        while (!$this->idle->isEmpty()) {
            $entry = $this->idle->dequeue();
            $entry['connection']->disconnect();
            unset($this->connectionCreatedAt[spl_object_id($entry['connection'])]);
        }
    }

    public function warmUp(): void
    {
        $target = min($this->poolConfig->minConnections, $this->poolConfig->maxConnections);
        $current = $this->idle->count() + $this->activeCount;

        while ($current < $target) {
            $connection = $this->createConnection();
            $createdAt  = $this->connectionCreatedAt[spl_object_id($connection)];

            $this->idle->enqueue([
                'connection' => $connection,
                'createdAt'  => $createdAt,
                'lastUsedAt' => microtime(true),
            ]);

            $current++;
        }
    }

    public function getStats(): ConnectionPoolStats
    {
        return new ConnectionPoolStats(
            idle: $this->idle->count(),
            active: $this->activeCount,
            total: $this->createdTotal,
            maxSize: $this->poolConfig->maxConnections,
        );
    }

    // ── Private ─────────────────────────────────────────────────

    private function createConnection(): ConnectionInterface
    {
        $connection = new Connection($this->config);
        $connection->connect();

        // Record creation time immediately so release() and warmUp() can use it.
        $this->connectionCreatedAt[spl_object_id($connection)] = microtime(true);
        $this->createdTotal++;

        return $connection;
    }

    private function evictStale(): void
    {
        $now  = microtime(true);
        $kept = new SplQueue();

        while (!$this->idle->isEmpty()) {
            $entry = $this->idle->dequeue();

            // Check idle timeout
            if (($now - $entry['lastUsedAt']) > $this->poolConfig->idleTimeoutSeconds) {
                $entry['connection']->disconnect();
                unset($this->connectionCreatedAt[spl_object_id($entry['connection'])]);
                continue;
            }

            // Check max lifetime
            if ($this->exceedsMaxLifetime($entry['createdAt'])) {
                $entry['connection']->disconnect();
                unset($this->connectionCreatedAt[spl_object_id($entry['connection'])]);
                continue;
            }

            $kept->enqueue($entry);
        }

        $this->idle = $kept;
    }

    private function exceedsMaxLifetime(float $createdAt): bool
    {
        return (microtime(true) - $createdAt) > $this->poolConfig->maxLifetimeSeconds;
    }
}
