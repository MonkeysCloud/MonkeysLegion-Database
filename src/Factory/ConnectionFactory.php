<?php

declare(strict_types=1);

namespace MonkeysLegion\Database\Factory;

use MonkeysLegion\Database\Contracts\ConnectionInterface;
use MonkeysLegion\Database\Types\DatabaseType;

final class ConnectionFactory
{
    /**
     * @param array{
     *   connections?: array<string, array{
     *     dsn?: string,
     *     file?: string,
     *     memory?: bool,
     *     username?: string,
     *     password?: string,
     *     options?: array<int, mixed>
     *   }>
     * } $config
     *
     * @return \MonkeysLegion\Database\Contracts\ConnectionInterface
     */
    public static function create(array $config): ConnectionInterface
    {
        $connections = $config['connections'] ?? [];

        foreach (DatabaseType::cases() as $type) {
            if (isset($connections[$type->value])) {
                $connectionClass = $type->getConnectionClass();
                $instance = new $connectionClass($config);

                assert($instance instanceof ConnectionInterface);
                return $instance;
            }
        }

        throw new \InvalidArgumentException('No valid database connection configuration found.');
    }

    /**
     * Create a connection instance by type string.
     * @param string $type
     * @param array{
     *     connections: array{
     *         mysql: array{
     *             dsn: string,
     *             username: string,
     *             password: string,
     *             options?: array<int, mixed>
     *         }
     *     }
     * } $config
     *
     * @return \MonkeysLegion\Database\Contracts\ConnectionInterface
     */
    public static function createByType(string $type, array $config): ConnectionInterface
    {
        $databaseType = DatabaseType::fromString($type);
        $connectionClass = $databaseType->getConnectionClass();

        $instance = new $connectionClass($config);
        assert($instance instanceof ConnectionInterface);

        return $instance;
    }

    /**
     * Create a connection instance by type string.
     * @param DatabaseType $type
     * @param array{
     *     connections: array{
     *         mysql: array{
     *             dsn: string,
     *             username: string,
     *             password: string,
     *             options?: array<int, mixed>
     *         }
     *     }
     * } $config
     *
     * @return \MonkeysLegion\Database\Contracts\ConnectionInterface
     */
    public static function createByEnum(DatabaseType $type, array $config): ConnectionInterface
    {
        $connectionClass = $type->getConnectionClass();
        $instance = new $connectionClass($config);
        assert($instance instanceof ConnectionInterface);
        return $instance;
    }
}
