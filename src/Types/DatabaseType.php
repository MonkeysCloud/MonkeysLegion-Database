<?php

declare(strict_types=1);

namespace MonkeysLegion\Database\Types;

use MonkeysLegion\Database\DSN\AbstractDsnBuilder;
use MonkeysLegion\Database\DSN\MySQLDsnBuilder;
use MonkeysLegion\Database\DSN\PostgreSQLDsnBuilder;
use MonkeysLegion\Database\DSN\SQLiteDsnBuilder;

enum DatabaseType: string
{
    case MYSQL = 'mysql';
    case POSTGRESQL = 'postgresql';
    case SQLITE = 'sqlite';

    public static function fromString(string $type): self
    {
        return match (strtolower($type)) {
            'mysql' => self::MYSQL,
            'postgresql', 'postgres', 'pgsql' => self::POSTGRESQL,
            'sqlite' => self::SQLITE,
            default => throw new \InvalidArgumentException("Unsupported database type: {$type}")
        };
    }

    public function getConnectionClass(): string
    {
        return match ($this) {
            self::MYSQL => \MonkeysLegion\Database\MySQL\Connection::class,
            self::POSTGRESQL => \MonkeysLegion\Database\PostgreSQL\Connection::class,
            self::SQLITE => \MonkeysLegion\Database\SQLite\Connection::class,
        };
    }

    public function getConfigKey(): string
    {
        return $this->value;
    }

    public function getDriverName(): string
    {
        return match ($this) {
            self::MYSQL => 'mysql',
            self::POSTGRESQL => 'pgsql',
            self::SQLITE => 'sqlite',
        };
    }

    public function getDsnBuilder(): AbstractDsnBuilder
    {
        return match ($this) {
            self::MYSQL => new MySQLDsnBuilder(),
            self::POSTGRESQL => new PostgreSQLDsnBuilder(),
            self::SQLITE => new SQLiteDsnBuilder(),
        };
    }
}
