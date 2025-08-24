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
            self::MYSQL->value => self::MYSQL,
            self::POSTGRESQL->value, 'postgres', 'pgsql' => self::POSTGRESQL,
            self::SQLITE->value, 'sqlite' => self::SQLITE,
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
            self::MYSQL => self::MYSQL->value,
            self::POSTGRESQL => self::POSTGRESQL->value,
            self::SQLITE => self::SQLITE->value,
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
