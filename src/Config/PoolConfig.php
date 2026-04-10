<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Config;

/**
 * MonkeysLegion Framework — Database Package
 *
 * Connection pool configuration.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final readonly class PoolConfig
{
    public function __construct(
        public int $minConnections = 1,
        public int $maxConnections = 10,
        public int $idleTimeoutSeconds = 300,
        public int $maxLifetimeSeconds = 3600,
        public int $healthCheckIntervalSeconds = 30,
        public bool $validateOnAcquire = true,
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            minConnections: (int) ($config['min_connections'] ?? 1),
            maxConnections: (int) ($config['max_connections'] ?? 10),
            idleTimeoutSeconds: (int) ($config['idle_timeout'] ?? 300),
            maxLifetimeSeconds: (int) ($config['max_lifetime'] ?? 3600),
            healthCheckIntervalSeconds: (int) ($config['health_check_interval'] ?? 30),
            validateOnAcquire: (bool) ($config['validate_on_acquire'] ?? true),
        );
    }
}
