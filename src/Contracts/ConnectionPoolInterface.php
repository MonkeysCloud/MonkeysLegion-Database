<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Contracts;

use MonkeysLegion\Database\Support\ConnectionPoolStats;

/**
 * MonkeysLegion Framework — Database Package
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
interface ConnectionPoolInterface
{
    /**
     * Acquire a connection from the pool.
     *
     * Creates a new connection if the pool is not exhausted,
     * or reuses an idle one.
     *
     * @throws \MonkeysLegion\Database\Exceptions\PoolException
     */
    public function acquire(): ConnectionInterface;

    /**
     * Release a connection back to the pool for reuse.
     *
     * The pool may discard the connection if it's unhealthy
     * or has exceeded its max lifetime.
     */
    public function release(ConnectionInterface $connection): void;

    /**
     * Drain all idle connections from the pool.
     */
    public function drain(): void;

    /**
     * Get current pool statistics.
     */
    public function getStats(): ConnectionPoolStats;
}
