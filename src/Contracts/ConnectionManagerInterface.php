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
interface ConnectionManagerInterface
{
    /**
     * Get a connection by name (or the default connection).
     *
     * @throws \MonkeysLegion\Database\Exceptions\ConfigurationException
     * @throws \MonkeysLegion\Database\Exceptions\ConnectionException
     */
    public function connection(?string $name = null): ConnectionInterface;

    /**
     * Get a read-optimized connection (replica when available).
     *
     * When sticky-after-write is enabled and a write has occurred during
     * the current request/scope, this returns the write connection instead.
     */
    public function read(?string $name = null): ConnectionInterface;

    /**
     * Get the primary (write) connection.
     */
    public function write(?string $name = null): ConnectionInterface;

    /**
     * Disconnect a specific connection (or the default).
     */
    public function disconnect(?string $name = null): void;

    /**
     * Disconnect all managed connections.
     */
    public function disconnectAll(): void;

    /**
     * Force-recreate a connection (drop caches, reset state).
     */
    public function purge(?string $name = null): void;

    /**
     * Get the name of the default connection.
     */
    public function getDefaultConnectionName(): string;

    /**
     * Set the default connection name for subsequent calls.
     */
    public function setDefaultConnection(string $name): void;

    /**
     * Get pool statistics for all connections.
     *
     * @return array<string, ConnectionPoolStats>
     */
    public function stats(): array;
}
