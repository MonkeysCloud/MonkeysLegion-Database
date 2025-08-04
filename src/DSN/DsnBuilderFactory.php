<?php

declare(strict_types=1);

namespace MonkeysLegion\Database\DSN;

use MonkeysLegion\Database\Types\DatabaseType;

class DsnBuilderFactory
{
    public static function create(DatabaseType $type): AbstractDsnBuilder
    {
        return match ($type) {
            DatabaseType::MYSQL => new MySQLDsnBuilder(),
            DatabaseType::POSTGRESQL => new PostgreSQLDsnBuilder(),
            DatabaseType::SQLITE => new SQLiteDsnBuilder(),
        };
    }

    public static function createByString(string $type): AbstractDsnBuilder
    {
        $databaseType = DatabaseType::fromString($type);
        return static::create($databaseType);
    }

    public static function mysql(): MySQLDsnBuilder
    {
        return new MySQLDsnBuilder();
    }

    public static function postgresql(): PostgreSQLDsnBuilder
    {
        return new PostgreSQLDsnBuilder();
    }

    public static function sqlite(): SQLiteDsnBuilder
    {
        return new SQLiteDsnBuilder();
    }
}
