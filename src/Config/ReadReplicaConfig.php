<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Config;

use MonkeysLegion\Database\Types\ReadReplicaStrategy;

/**
 * MonkeysLegion Framework — Database Package
 *
 * Read replica configuration.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final readonly class ReadReplicaConfig
{
    /**
     * @param list<DsnConfig> $replicas    DSN configurations for each replica
     * @param ReadReplicaStrategy $strategy Selection strategy for replicas
     * @param bool $stickyAfterWrite       If true, read() returns the writer after any write in the same scope
     */
    public function __construct(
        public array $replicas,
        public ReadReplicaStrategy $strategy = ReadReplicaStrategy::RoundRobin,
        public bool $stickyAfterWrite = true,
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $dsnConfigs = [];
        $driver = null;

        // Replicas inherit the driver from the parent config
        if (isset($config['__driver'])) {
            $driver = $config['__driver'];
            unset($config['__driver']);
        }

        foreach ($config['replicas'] ?? [] as $replicaConfig) {
            if ($driver !== null) {
                $dsnConfigs[] = DsnConfig::fromArray($driver, $replicaConfig);
            }
        }

        return new self(
            replicas: $dsnConfigs,
            strategy: isset($config['strategy'])
                ? ReadReplicaStrategy::from($config['strategy'])
                : ReadReplicaStrategy::RoundRobin,
            stickyAfterWrite: (bool) ($config['sticky_after_write'] ?? true),
        );
    }
}
