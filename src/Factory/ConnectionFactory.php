<?php

declare(strict_types=1);

namespace MonkeysLegion\Database\Factory;

use MonkeysLegion\Database\Contracts\ConnectionInterface;
use MonkeysLegion\Database\Types\DatabaseType;

final class ConnectionFactory
{
    /**
     * @param array<string, mixed> $config
     */
    public static function create(array $config): ConnectionInterface
    {
        if (!isset($config['default'])) {
            throw new \InvalidArgumentException('No default connection configuration found.');
        }
        return self::createByType($config['default'], $config);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function createByType(string $type, array $config): ConnectionInterface
    {
        $databaseType = DatabaseType::fromString($type);
        return self::createByEnum($databaseType, $config);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function createByEnum(DatabaseType $type, array $config): ConnectionInterface
    {
        $connectionClass = $type->getConnectionClass();

        $connectionConfig = $config['connections'][$type->value]
            ?? throw new \InvalidArgumentException("Missing config for connection type '{$type->value}'");

        return self::build($connectionClass, $connectionConfig);
    }

    /**
     * @param string $connectionClass
     * @param array<string, mixed> $connectionConfig
     */
    private static function build(string $connectionClass, array $connectionConfig): ConnectionInterface
    {
        $instance = new $connectionClass($connectionConfig);
        self::assertImplementsConnection($instance, $connectionClass);
        return $instance;
    }

    /**
     * @phpstan-assert ConnectionInterface $instance
     */
    private static function assertImplementsConnection(object $instance, string $className): void
    {
        if (!$instance instanceof ConnectionInterface) {
            throw new \RuntimeException("Class {$className} must implement ConnectionInterface.");
        }
    }
}
