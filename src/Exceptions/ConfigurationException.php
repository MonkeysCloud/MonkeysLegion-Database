<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Exceptions;

use MonkeysLegion\Database\Types\DatabaseDriver;

/**
 * Configuration-related errors (invalid DSN, missing driver extension, etc.).
 */
class ConfigurationException extends DatabaseException
{
    /** The connection name that has the configuration problem. */
    public private(set) ?string $connectionName;

    /** The config key that is invalid or missing (if applicable). */
    public private(set) ?string $configKey;

    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        ?DatabaseDriver $driver = null,
        ?string $connectionName = null,
        ?string $configKey = null,
    ) {
        parent::__construct($message, $code, $previous, $driver);
        $this->connectionName = $connectionName;
        $this->configKey = $configKey;
    }

    /**
     * Create for a missing required config key.
     */
    public static function missingKey(string $key, string $connectionName): self
    {
        return new self(
            message: "Database config '{$connectionName}' is missing required key '{$key}'",
            connectionName: $connectionName,
            configKey: $key,
        );
    }

    /**
     * Create for a missing PHP extension.
     */
    public static function missingExtension(DatabaseDriver $driver): self
    {
        return new self(
            message: "PHP extension '{$driver->requiredExtension()}' is not loaded for driver '{$driver->label()}'",
            driver: $driver,
        );
    }
}
