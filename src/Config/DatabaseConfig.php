<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Config;

use MonkeysLegion\Database\Exceptions\ConfigurationException;
use MonkeysLegion\Database\Types\DatabaseDriver;

/**
 * MonkeysLegion Framework — Database Package
 *
 * Immutable, typed database connection configuration.
 * Replaces raw associative arrays used in v1.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final readonly class DatabaseConfig
{
    /**
     * @param array<int, mixed> $pdoOptions PDO constructor options
     */
    public function __construct(
        public string $name,
        public DatabaseDriver $driver,
        public DsnConfig $dsn,
        public ?string $username = null,
        public ?string $password = null,
        public array $pdoOptions = [],
        public string $timezone = 'UTC',
        public PoolConfig $pool = new PoolConfig(),
        public ?ReadReplicaConfig $readReplica = null,
    ) {}

    /**
     * Default PDO attributes merged with user-provided options.
     *
     * @return array<int, mixed>
     */
    public function effectivePdoOptions(): array
    {
        $defaults = [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        // User options override defaults
        return $this->pdoOptions + $defaults;
    }

    /**
     * Build a DatabaseConfig from a raw config array.
     *
     * Supports the v1 config format for backward compatibility:
     * ```php
     * [
     *     'driver'   => 'mysql',
     *     'host'     => 'localhost',
     *     'port'     => 3306,
     *     'database' => 'myapp',
     *     'username' => 'root',
     *     'password' => 'secret',
     * ]
     * ```
     *
     * @param string              $name   Connection name
     * @param array<string, mixed> $config Raw configuration array
     *
     * @throws ConfigurationException If required fields are missing
     */
    public static function fromArray(string $name, array $config): self
    {
        if (!isset($config['driver'])) {
            throw new ConfigurationException(
                "Database config '{$name}' is missing the required 'driver' key",
            );
        }

        $driver = DatabaseDriver::fromString((string) $config['driver']);

        if (!$driver->isExtensionLoaded()) {
            throw new ConfigurationException(
                "PHP extension '{$driver->requiredExtension()}' is not loaded for driver '{$driver->label()}'",
                driver: $driver,
            );
        }

        // Build DSN config from explicit object, nested array, or flat top-level keys
        $dsnConfig = match (true) {
            isset($config['dsn']) && $config['dsn'] instanceof DsnConfig => $config['dsn'],
            isset($config['dsn']) && is_array($config['dsn'])           => DsnConfig::fromArray($driver, $config['dsn']),
            default                                                      => DsnConfig::fromArray($driver, $config),
        };

        // Pool config
        $poolConfig = isset($config['pool']) && is_array($config['pool'])
            ? PoolConfig::fromArray($config['pool'])
            : new PoolConfig();

        // Read replica config
        $replicaConfig = null;
        if (isset($config['read']) && is_array($config['read'])) {
            $config['read']['__driver'] = $driver;
            $replicaConfig = ReadReplicaConfig::fromArray($config['read']);
        }

        // PDO options
        $pdoOptions = [];
        if (isset($config['options']) && is_array($config['options'])) {
            $pdoOptions = $config['options'];
        }

        return new self(
            name: $name,
            driver: $driver,
            dsn: $dsnConfig,
            username: isset($config['username']) ? (string) $config['username'] : null,
            password: isset($config['password']) ? (string) $config['password'] : null,
            pdoOptions: $pdoOptions,
            timezone: isset($config['timezone']) ? (string) $config['timezone'] : 'UTC',
            pool: $poolConfig,
            readReplica: $replicaConfig,
        );
    }
}
