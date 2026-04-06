<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Contracts;

/**
 * MonkeysLegion Framework — Database Package
 *
 * Optional event dispatcher for connection lifecycle events.
 * Inject via DI to enable event broadcasting; null by default.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
interface ConnectionEventDispatcherInterface
{
    /**
     * Dispatch a connection-established event.
     */
    public function onConnected(ConnectionInterface $connection): void;

    /**
     * Dispatch a connection-closed event.
     */
    public function onDisconnected(ConnectionInterface $connection): void;

    /**
     * Dispatch a connection-error event.
     */
    public function onError(ConnectionInterface $connection, \Throwable $error): void;

    /**
     * Dispatch a query-executed event (for profiling/monitoring).
     *
     * @param array<string, mixed> $params
     */
    public function onQueryExecuted(
        ConnectionInterface $connection,
        string $sql,
        array $params,
        float $durationMs,
    ): void;
}
