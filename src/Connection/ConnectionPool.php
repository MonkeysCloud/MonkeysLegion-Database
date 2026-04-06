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
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class ConnectionPool implements ConnectionPoolInterface
{
    /** @var SplQueue<array{connection: ConnectionInterface, createdAt: float, lastUsedAt: float}> */
    private SplQueue $idle;

    private int $activeCount = 0;
    private int $createdTotal = 0;

    public function __construct(
        private readonly DatabaseConfig $config,
        private readonly PoolConfig $poolConfig,
    ) {
        $this->idle = new SplQueue();
    }

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
                continue;
            }

            // Optionally validate the connection before returning it
            if ($this->poolConfig->validateOnAcquire && !$connection->isAlive()) {
                $connection->disconnect();
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
        $this->createdTotal++;

        return $connection;
    }

    public function release(ConnectionInterface $connection): void
    {
        $this->activeCount = max(0, $this->activeCount - 1);

        // Don't return unhealthy connections to the pool
        if (!$connection->isConnected()) {
            return;
        }

        // Force-rollback any dangling transaction
        if ($connection->inTransaction()) {
            try {
                $connection->rollBack();
            } catch (\Throwable) {
                $connection->disconnect();
                return;
            }
        }

        $this->idle->enqueue([
            'connection' => $connection,
            'createdAt'  => microtime(true),
            'lastUsedAt' => microtime(true),
        ]);
    }

    public function drain(): void
    {
        while (!$this->idle->isEmpty()) {
            $entry = $this->idle->dequeue();
            $entry['connection']->disconnect();
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
        return $connection;
    }

    private function evictStale(): void
    {
        $now = microtime(true);
        $kept = new SplQueue();

        while (!$this->idle->isEmpty()) {
            $entry = $this->idle->dequeue();

            // Check idle timeout
            $idleSeconds = $now - $entry['lastUsedAt'];
            if ($idleSeconds > $this->poolConfig->idleTimeoutSeconds) {
                $entry['connection']->disconnect();
                continue;
            }

            // Check max lifetime
            if ($this->exceedsMaxLifetime($entry['createdAt'])) {
                $entry['connection']->disconnect();
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
