<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Support;

use MonkeysLegion\Database\Contracts\ConnectionInterface;

/**
 * MonkeysLegion Framework — Database Package
 *
 * Reusable health check logic for database connections.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class HealthChecker
{
    /**
     * Run a health check against a connection.
     *
     * @return HealthCheckResult
     */
    public static function check(ConnectionInterface $connection): HealthCheckResult
    {
        if (!$connection->isConnected()) {
            return new HealthCheckResult(
                healthy: false,
                reason: 'Connection is not established',
                latencyMs: 0.0,
            );
        }

        $start = hrtime(true);

        try {
            $sql = $connection->getDriver()->healthCheckSql();
            $connection->query($sql);

            $latencyMs = (hrtime(true) - $start) / 1_000_000;

            return new HealthCheckResult(
                healthy: true,
                latencyMs: round($latencyMs, 3),
            );
        } catch (\Throwable $e) {
            $latencyMs = (hrtime(true) - $start) / 1_000_000;

            return new HealthCheckResult(
                healthy: false,
                reason: $e->getMessage(),
                latencyMs: round($latencyMs, 3),
            );
        }
    }
}
