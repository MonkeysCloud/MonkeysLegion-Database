<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Exceptions;

use MonkeysLegion\Database\Support\ConnectionPoolStats;
use MonkeysLegion\Database\Types\DatabaseDriver;

/**
 * Connection pool errors (exhaustion, health check failures).
 */
class PoolException extends DatabaseException
{
    /** Pool stats at the time of the error. */
    public private(set) ?ConnectionPoolStats $poolStats;

    /** The connection name/pool that generated the error. */
    public private(set) ?string $connectionName;

    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        ?DatabaseDriver $driver = null,
        ?ConnectionPoolStats $poolStats = null,
        ?string $connectionName = null,
    ) {
        parent::__construct($message, $code, $previous, $driver);
        $this->poolStats = $poolStats;
        $this->connectionName = $connectionName;
    }

    /**
     * Create for an exhausted pool — no connections available.
     */
    public static function exhausted(
        ConnectionPoolStats $stats,
        DatabaseDriver $driver,
        ?string $connectionName = null,
    ): self {
        return new self(
            message: "Connection pool exhausted: {$stats->active} active, "
                . "{$stats->idle} idle, max {$stats->maxSize}. "
                . "Utilization: " . round($stats->utilization() * 100, 1) . '%',
            driver: $driver,
            poolStats: $stats,
            connectionName: $connectionName,
        );
    }

    /**
     * Create for a health check failure during pool maintenance.
     */
    public static function healthCheckFailed(
        string $reason,
        DatabaseDriver $driver,
        ?string $connectionName = null,
    ): self {
        return new self(
            message: "Pool health check failed: {$reason}",
            driver: $driver,
            connectionName: $connectionName,
        );
    }
}
