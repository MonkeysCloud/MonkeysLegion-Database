<?php

declare(strict_types=1);

namespace MonkeysLegion\Database\Factory;

use MonkeysLegion\Database\Contracts\ConnectionInterface;
use MonkeysLegion\Database\Types\DatabaseType;

final class ConnectionFactory
{
    /**
     * Create a connection using the "default" connection type from the config.
     *
     * @param array{
     *     default?: string,
     *     connections: array<string, array{
     *         dsn?: string,
     *         file?: string,
     *         memory?: bool,
     *         username?: string,
     *         password?: string,
     *         options?: array<int, mixed>
     *     }>
     * } $config
     *
     * @return ConnectionInterface
     */
    public static function create(array $config): ConnectionInterface
    {
        if (!isset($config['default'])) {
            throw new \InvalidArgumentException('No default connection configuration found.');
        }

        return self::createByType($config['default'], $config);
    }

    /**
     * Create a connection instance by connection type string.
     *
     * @param string $type
     * @param array{
     *     default: string,
     *     connections: array<string, array{
     *         dsn?: string,
     *         file?: string,
     *         memory?: bool,
     *         username?: string,
     *         password?: string,
     *         options?: array<int, mixed>
     *     }>
     * } $config
     *
     * @return ConnectionInterface
     */
    public static function createByType(string $type, array $config): ConnectionInterface
    {
        $databaseType = DatabaseType::fromString($type);
        $connectionClass = $databaseType->getConnectionClass();

        $connectionConfig = $config['connections'][$type]
            ?? throw new \InvalidArgumentException("Missing config for connection type '{$type}'");

        $instance = new $connectionClass($connectionConfig);

        if (!$instance instanceof ConnectionInterface) {
            throw new \RuntimeException("Class {$connectionClass} must implement ConnectionInterface.");
        }

        return $instance;
    }

    /**
     * Create a connection instance by `DatabaseType` enum.
     *
     * @param DatabaseType $type
     * @param array{
     *     default: string,
     *     connections: array<string, array{
     *         dsn?: string,
     *         file?: string,
     *         memory?: bool,
     *         username?: string,
     *         password?: string,
     *         options?: array<int, mixed>
     *     }>
     * } $config
     *
     * @return ConnectionInterface
     */
    public static function createByEnum(DatabaseType $type, array $config): ConnectionInterface
    {
        $connectionClass = $type->getConnectionClass();

        $connectionConfig = $config['connections'][$type->value]
            ?? throw new \InvalidArgumentException("Missing config for connection type '{$type->value}'");

        $instance = new $connectionClass($connectionConfig);

        if (!$instance instanceof ConnectionInterface) {
            throw new \RuntimeException("Class {$connectionClass} must implement ConnectionInterface.");
        }

        return $instance;
    }
}
