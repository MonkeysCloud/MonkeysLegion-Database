<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Connection;

use MonkeysLegion\Database\Config\DatabaseConfig;
use MonkeysLegion\Database\Config\DsnConfig;
use MonkeysLegion\Database\Contracts\ConnectionEventDispatcherInterface;
use MonkeysLegion\Database\Contracts\ConnectionInterface;
use MonkeysLegion\Database\Contracts\ConnectionManagerInterface;
use MonkeysLegion\Database\Exceptions\ConfigurationException;
use MonkeysLegion\Database\Support\ConnectionPoolStats;
use MonkeysLegion\Database\Types\ReadReplicaStrategy;
use Psr\Log\LoggerInterface;

/**
 * MonkeysLegion Framework — Database Package
 *
 * Primary entry point for managing database connections.
 * Replaces ConnectionFactory and adds:
 *   • Read/write splitting with sticky-after-write
 *   • Lazy connection creation (PDO not allocated until first use)
 *   • Round-robin / random / least-connections replica selection
 *   • Optional event dispatching and PSR-3 logging
 *
 * PHP 8.4 features used:
 *  • Property `set` hooks on `$eventDispatcher` and `$logger` — assigning either
 *    property automatically propagates the new value to every already-open
 *    `Connection` instance held by the manager. No separate setter method needed.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class ConnectionManager implements ConnectionManagerInterface
{
    /** @var array<string, ConnectionInterface> Active write connections */
    private array $writeConnections = [];

    /** @var array<string, list<ConnectionInterface>> Active replica connections */
    private array $replicaConnections = [];

    /** @var array<string, int> Round-robin index per connection name */
    private array $roundRobinIndex = [];

    /** Per-scope sticky flag: once a write occurs, reads go to the writer */
    private bool $stickyWrite = false;

    private string $defaultName;

    // ── PHP 8.4 — `set` hooks propagate collaborators to open connections ──

    /**
     * Optional event dispatcher.
     *
     * Assigning this property automatically forwards the value to every
     * `Connection` already held by the manager. Future connections receive it
     * via the factory closure that captures `$this->eventDispatcher`.
     */
    public ?ConnectionEventDispatcherInterface $eventDispatcher = null {
        set(?ConnectionEventDispatcherInterface $dispatcher) {
            $this->eventDispatcher = $dispatcher;
            $this->propagateToOpenConnections(
                static function (Connection $c) use ($dispatcher): void {
                    $c->eventDispatcher = $dispatcher;
                },
            );
        }
    }

    /**
     * Optional PSR-3 logger.
     *
     * Assigning this property automatically forwards the value to every
     * `Connection` already held by the manager.
     */
    public ?LoggerInterface $logger = null {
        set(?LoggerInterface $logger) {
            $this->logger = $logger;
            $this->propagateToOpenConnections(
                static function (Connection $c) use ($logger): void {
                    $c->logger = $logger;
                },
            );
        }
    }

    /**
     * @param array<string, DatabaseConfig> $configs Connection configs keyed by name
     */
    public function __construct(
        private readonly array $configs,
    ) {
        $keys = array_keys($this->configs);
        if (empty($keys)) {
            throw new ConfigurationException('At least one database connection must be configured');
        }
        $this->defaultName = $keys[0];
    }

    // ── Factory Method ──────────────────────────────────────────

    /**
     * Build a ConnectionManager from raw config arrays (backward compat).
     *
     * @param array<string, array<string, mixed>> $rawConfigs
     */
    public static function fromArray(array $rawConfigs): self
    {
        $configs = [];
        foreach ($rawConfigs as $name => $raw) {
            $configs[$name] = DatabaseConfig::fromArray($name, $raw);
        }
        return new self($configs);
    }

    // ── Connection Access ───────────────────────────────────────

    public function connection(?string $name = null): ConnectionInterface
    {
        return $this->write($name);
    }

    public function read(?string $name = null): ConnectionInterface
    {
        $name ??= $this->defaultName;
        $config = $this->resolveConfig($name);

        // Sticky-after-write: return the writer after any write in this scope
        if ($this->stickyWrite && $config->readReplica?->stickyAfterWrite) {
            return $this->write($name);
        }

        // No replicas configured → fall through to the primary
        if ($config->readReplica === null || empty($config->readReplica->replicas)) {
            return $this->write($name);
        }

        return $this->resolveReplica($name, $config);
    }

    public function write(?string $name = null): ConnectionInterface
    {
        $name ??= $this->defaultName;

        if (!isset($this->writeConnections[$name])) {
            $config = $this->resolveConfig($name);

            $this->writeConnections[$name] = new LazyConnection(
                factory: function () use ($config): ConnectionInterface {
                    $conn = new Connection($config);
                    $conn->eventDispatcher = $this->eventDispatcher;
                    $conn->logger          = $this->logger;
                    $conn->connect();
                    return $conn;
                },
                name: $name,
                driver: $config->driver,
            );
        }

        return $this->writeConnections[$name];
    }

    public function disconnect(?string $name = null): void
    {
        $name ??= $this->defaultName;

        if (isset($this->writeConnections[$name])) {
            $this->writeConnections[$name]->disconnect();
            unset($this->writeConnections[$name]);
        }

        if (isset($this->replicaConnections[$name])) {
            foreach ($this->replicaConnections[$name] as $replica) {
                $replica->disconnect();
            }
            unset($this->replicaConnections[$name]);
        }
    }

    public function disconnectAll(): void
    {
        foreach (array_keys($this->writeConnections) as $name) {
            $this->disconnect($name);
        }

        // Catch any replica-only connections
        foreach (array_keys($this->replicaConnections) as $name) {
            foreach ($this->replicaConnections[$name] as $replica) {
                $replica->disconnect();
            }
        }
        $this->replicaConnections = [];
    }

    public function purge(?string $name = null): void
    {
        $this->disconnect($name);
    }

    public function getDefaultConnectionName(): string
    {
        return $this->defaultName;
    }

    public function setDefaultConnection(string $name): void
    {
        if (!isset($this->configs[$name])) {
            throw new ConfigurationException(
                "No database configuration found for connection '{$name}'",
            );
        }
        $this->defaultName = $name;
    }

    public function stats(): array
    {
        $stats = [];
        foreach (array_keys($this->configs) as $name) {
            $writeActive = isset($this->writeConnections[$name])
                && $this->writeConnections[$name]->isConnected();

            $replicaCount = 0;
            if (isset($this->replicaConnections[$name])) {
                foreach ($this->replicaConnections[$name] as $replica) {
                    if ($replica->isConnected()) {
                        $replicaCount++;
                    }
                }
            }

            $stats[$name] = new ConnectionPoolStats(
                idle: 0,
                active: ($writeActive ? 1 : 0) + $replicaCount,
                total: ($writeActive ? 1 : 0) + $replicaCount,
                maxSize: $this->configs[$name]->pool->maxConnections,
            );
        }
        return $stats;
    }

    // ── Sticky Write ────────────────────────────────────────────

    /**
     * Mark that a write has occurred — subsequent reads will use the primary.
     */
    public function markWritePerformed(): void
    {
        $this->stickyWrite = true;
    }

    /**
     * Reset the sticky-after-write flag (e.g. at request boundary).
     */
    public function resetSticky(): void
    {
        $this->stickyWrite = false;
    }

    // ── Private ─────────────────────────────────────────────────

    private function resolveConfig(string $name): DatabaseConfig
    {
        if (!isset($this->configs[$name])) {
            throw new ConfigurationException(
                "No database configuration found for connection '{$name}'. "
                . 'Available: ' . implode(', ', array_keys($this->configs)),
            );
        }
        return $this->configs[$name];
    }

    private function resolveReplica(string $name, DatabaseConfig $config): ConnectionInterface
    {
        $replicaConfig = $config->readReplica;
        if ($replicaConfig === null || empty($replicaConfig->replicas)) {
            return $this->write($name);
        }

        // Initialize replica connections on first access
        if (!isset($this->replicaConnections[$name])) {
            $this->replicaConnections[$name] = [];

            foreach ($replicaConfig->replicas as $i => $replicaDsn) {
                $replicaDbConfig = new DatabaseConfig(
                    name: "{$name}-replica-{$i}",
                    driver: $config->driver,
                    dsn: $replicaDsn,
                    username: $config->username,
                    password: $config->password,
                    pdoOptions: $config->pdoOptions,
                    timezone: $config->timezone,
                    pool: $config->pool,
                );

                $this->replicaConnections[$name][] = new LazyConnection(
                    factory: function () use ($replicaDbConfig): ConnectionInterface {
                        $conn = new Connection($replicaDbConfig);
                        $conn->eventDispatcher = $this->eventDispatcher;
                        $conn->logger          = $this->logger;
                        $conn->connect();
                        return $conn;
                    },
                    name: $replicaDbConfig->name,
                    driver: $config->driver,
                );
            }
        }

        $replicas = $this->replicaConnections[$name];
        if (empty($replicas)) {
            return $this->write($name);
        }

        return match ($replicaConfig->strategy) {
            ReadReplicaStrategy::RoundRobin       => $this->roundRobin($name, $replicas),
            ReadReplicaStrategy::Random           => $replicas[array_rand($replicas)],
            ReadReplicaStrategy::LeastConnections => $this->leastConnections($replicas),
        };
    }

    /**
     * @param list<ConnectionInterface> $replicas
     */
    private function roundRobin(string $name, array $replicas): ConnectionInterface
    {
        $index = $this->roundRobinIndex[$name] ?? 0;
        $replica = $replicas[$index % count($replicas)];
        $this->roundRobinIndex[$name] = $index + 1;
        return $replica;
    }

    /**
     * @param list<ConnectionInterface> $replicas
     */
    private function leastConnections(array $replicas): ConnectionInterface
    {
        // Prefer uninitialized lazy connections (they have 0 load)
        $uninitialized = array_filter(
            $replicas,
            static fn(ConnectionInterface $c) => $c instanceof LazyConnection && !$c->initialized,
        );

        if (!empty($uninitialized)) {
            return $uninitialized[array_rand($uninitialized)];
        }

        // All initialized — fall back to random
        return $replicas[array_rand($replicas)];
    }

    /**
     * Apply a callback to every concrete `Connection` currently held
     * (both write and replica slots, skipping unresolved lazy wrappers).
     *
     * @param callable(Connection): void $callback
     */
    private function propagateToOpenConnections(callable $callback): void
    {
        foreach ($this->writeConnections as $conn) {
            if ($conn instanceof Connection) {
                $callback($conn);
            }
        }
        foreach ($this->replicaConnections as $replicas) {
            foreach ($replicas as $conn) {
                if ($conn instanceof Connection) {
                    $callback($conn);
                }
            }
        }
    }
}


use MonkeysLegion\Database\Config\DatabaseConfig;
use MonkeysLegion\Database\Config\DsnConfig;
use MonkeysLegion\Database\Contracts\ConnectionEventDispatcherInterface;
use MonkeysLegion\Database\Contracts\ConnectionInterface;
use MonkeysLegion\Database\Contracts\ConnectionManagerInterface;
use MonkeysLegion\Database\Exceptions\ConfigurationException;
use MonkeysLegion\Database\Support\ConnectionPoolStats;
use MonkeysLegion\Database\Types\ReadReplicaStrategy;

/**
 * MonkeysLegion Framework — Database Package
 *
 * Primary entry point for managing database connections.
 * Replaces ConnectionFactory and adds:
 *   • Read/write splitting with sticky-after-write
 *   • Lazy connection creation (PDO not allocated until first use)
 *   • Round-robin / random / least-connections replica selection
 *   • Optional event dispatching
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class ConnectionManager implements ConnectionManagerInterface
{
    /** @var array<string, ConnectionInterface> Active write connections */
    private array $writeConnections = [];

    /** @var array<string, list<ConnectionInterface>> Active replica connections */
    private array $replicaConnections = [];

    /** @var array<string, int> Round-robin index per connection name */
    private array $roundRobinIndex = [];

    /** Per-scope sticky flag: once a write occurs, reads go to the writer */
    private bool $stickyWrite = false;

    private string $defaultName;

    private ?ConnectionEventDispatcherInterface $eventDispatcher = null;

    /**
     * @param array<string, DatabaseConfig> $configs Connection configs keyed by name
     */
    public function __construct(
        private readonly array $configs,
    ) {
        $keys = array_keys($this->configs);
        if (empty($keys)) {
            throw new ConfigurationException('At least one database connection must be configured');
        }
        $this->defaultName = $keys[0];
    }

    // ── Factory Method ──────────────────────────────────────────

    /**
     * Build a ConnectionManager from raw config arrays (backward compat).
     *
     * @param array<string, array<string, mixed>> $rawConfigs
     */
    public static function fromArray(array $rawConfigs): self
    {
        $configs = [];
        foreach ($rawConfigs as $name => $raw) {
            $configs[$name] = DatabaseConfig::fromArray($name, $raw);
        }
        return new self($configs);
    }

    // ── Connection Access ───────────────────────────────────────

    public function connection(?string $name = null): ConnectionInterface
    {
        return $this->write($name);
    }

    public function read(?string $name = null): ConnectionInterface
    {
        $name ??= $this->defaultName;
        $config = $this->resolveConfig($name);

        // Sticky-after-write: return the writer after any write in this scope
        if ($this->stickyWrite && $config->readReplica?->stickyAfterWrite) {
            return $this->write($name);
        }

        // No replicas configured → fall through to the primary
        if ($config->readReplica === null || empty($config->readReplica->replicas)) {
            return $this->write($name);
        }

        return $this->resolveReplica($name, $config);
    }

    public function write(?string $name = null): ConnectionInterface
    {
        $name ??= $this->defaultName;

        if (!isset($this->writeConnections[$name])) {
            $config = $this->resolveConfig($name);

            $this->writeConnections[$name] = new LazyConnection(
                factory: function () use ($config): ConnectionInterface {
                    $conn = new Connection($config);
                    if ($this->eventDispatcher !== null) {
                        $conn->setEventDispatcher($this->eventDispatcher);
                    }
                    $conn->connect();
                    return $conn;
                },
                name: $name,
                driver: $config->driver,
            );
        }

        return $this->writeConnections[$name];
    }

    public function disconnect(?string $name = null): void
    {
        $name ??= $this->defaultName;

        if (isset($this->writeConnections[$name])) {
            $this->writeConnections[$name]->disconnect();
            unset($this->writeConnections[$name]);
        }

        if (isset($this->replicaConnections[$name])) {
            foreach ($this->replicaConnections[$name] as $replica) {
                $replica->disconnect();
            }
            unset($this->replicaConnections[$name]);
        }
    }

    public function disconnectAll(): void
    {
        foreach (array_keys($this->writeConnections) as $name) {
            $this->disconnect($name);
        }

        // Catch any replica-only connections
        foreach (array_keys($this->replicaConnections) as $name) {
            foreach ($this->replicaConnections[$name] as $replica) {
                $replica->disconnect();
            }
        }
        $this->replicaConnections = [];
    }

    public function purge(?string $name = null): void
    {
        $this->disconnect($name);
    }

    public function getDefaultConnectionName(): string
    {
        return $this->defaultName;
    }

    public function setDefaultConnection(string $name): void
    {
        if (!isset($this->configs[$name])) {
            throw new ConfigurationException(
                "No database configuration found for connection '{$name}'",
            );
        }
        $this->defaultName = $name;
    }

    public function stats(): array
    {
        $stats = [];
        foreach (array_keys($this->configs) as $name) {
            $writeActive = isset($this->writeConnections[$name])
                && $this->writeConnections[$name]->isConnected();

            $replicaCount = 0;
            if (isset($this->replicaConnections[$name])) {
                foreach ($this->replicaConnections[$name] as $replica) {
                    if ($replica->isConnected()) {
                        $replicaCount++;
                    }
                }
            }

            $stats[$name] = new ConnectionPoolStats(
                idle: 0,
                active: ($writeActive ? 1 : 0) + $replicaCount,
                total: ($writeActive ? 1 : 0) + $replicaCount,
                maxSize: $this->configs[$name]->pool->maxConnections,
            );
        }
        return $stats;
    }

    // ── Sticky Write ────────────────────────────────────────────

    /**
     * Mark that a write has occurred — subsequent reads will use the primary.
     */
    public function markWritePerformed(): void
    {
        $this->stickyWrite = true;
    }

    /**
     * Reset the sticky-after-write flag (e.g. at request boundary).
     */
    public function resetSticky(): void
    {
        $this->stickyWrite = false;
    }

    // ── Event Dispatcher ────────────────────────────────────────

    /**
     * Inject an optional event dispatcher for all managed connections.
     */
    public function setEventDispatcher(ConnectionEventDispatcherInterface $dispatcher): void
    {
        $this->eventDispatcher = $dispatcher;

        // Propagate to existing connections
        foreach ($this->writeConnections as $conn) {
            if ($conn instanceof LazyConnection) {
                // Will be applied when the connection is resolved
                continue;
            }
            if ($conn instanceof Connection) {
                $conn->setEventDispatcher($dispatcher);
            }
        }
    }

    // ── Private ─────────────────────────────────────────────────

    private function resolveConfig(string $name): DatabaseConfig
    {
        if (!isset($this->configs[$name])) {
            throw new ConfigurationException(
                "No database configuration found for connection '{$name}'. "
                . 'Available: ' . implode(', ', array_keys($this->configs)),
            );
        }
        return $this->configs[$name];
    }

    private function resolveReplica(string $name, DatabaseConfig $config): ConnectionInterface
    {
        $replicaConfig = $config->readReplica;
        if ($replicaConfig === null || empty($replicaConfig->replicas)) {
            return $this->write($name);
        }

        // Initialize replica connections on first access
        if (!isset($this->replicaConnections[$name])) {
            $this->replicaConnections[$name] = [];

            foreach ($replicaConfig->replicas as $i => $replicaDsn) {
                $replicaDbConfig = new DatabaseConfig(
                    name: "{$name}-replica-{$i}",
                    driver: $config->driver,
                    dsn: $replicaDsn,
                    username: $config->username,
                    password: $config->password,
                    pdoOptions: $config->pdoOptions,
                    timezone: $config->timezone,
                    pool: $config->pool,
                );

                $this->replicaConnections[$name][] = new LazyConnection(
                    factory: function () use ($replicaDbConfig): ConnectionInterface {
                        $conn = new Connection($replicaDbConfig);
                        if ($this->eventDispatcher !== null) {
                            $conn->setEventDispatcher($this->eventDispatcher);
                        }
                        $conn->connect();
                        return $conn;
                    },
                    name: $replicaDbConfig->name,
                    driver: $config->driver,
                );
            }
        }

        $replicas = $this->replicaConnections[$name];
        if (empty($replicas)) {
            return $this->write($name);
        }

        return match ($replicaConfig->strategy) {
            ReadReplicaStrategy::RoundRobin       => $this->roundRobin($name, $replicas),
            ReadReplicaStrategy::Random           => $replicas[array_rand($replicas)],
            ReadReplicaStrategy::LeastConnections => $this->leastConnections($replicas),
        };
    }

    /**
     * @param list<ConnectionInterface> $replicas
     */
    private function roundRobin(string $name, array $replicas): ConnectionInterface
    {
        $index = $this->roundRobinIndex[$name] ?? 0;
        $replica = $replicas[$index % count($replicas)];
        $this->roundRobinIndex[$name] = $index + 1;
        return $replica;
    }

    /**
     * @param list<ConnectionInterface> $replicas
     */
    private function leastConnections(array $replicas): ConnectionInterface
    {
        // For lazy connections, prefer those not yet initialized
        $uninitialized = array_filter(
            $replicas,
            static fn(ConnectionInterface $c) => $c instanceof LazyConnection && !$c->initialized,
        );

        if (!empty($uninitialized)) {
            return $uninitialized[array_rand($uninitialized)];
        }

        // All initialized — fall back to random
        return $replicas[array_rand($replicas)];
    }
}
