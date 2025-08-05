<?php

declare(strict_types=1);

namespace MonkeysLegion\Database\Tests;

use MonkeysLegion\Database\Factory\ConnectionFactory;
use MonkeysLegion\Database\MySQL\Connection as MySQLConnection;
use MonkeysLegion\Database\PostgreSQL\Connection as PostgreSQLConnection;
use MonkeysLegion\Database\SQLite\Connection as SQLiteConnection;
use MonkeysLegion\Database\Types\DatabaseType;
use PHPUnit\Framework\TestCase;

class ConnectionFactoryTest extends TestCase
{
    public function testCreateWithMySQLConfig(): void
    {
        $config = [
            'default' => 'mysql',
            'connections' => [
                'mysql' => [
                    'dsn' => 'mysql:host=localhost;dbname=test',
                    'username' => 'root',
                    'password' => '',
                    'options' => []
                ]
            ]
        ];

        $connection = ConnectionFactory::create($config);
        $this->assertInstanceOf(MySQLConnection::class, $connection);
    }

    public function testCreateWithPostgreSQLConfig(): void
    {
        $config = [
            'default' => 'postgresql',
            'connections' => [
                'postgresql' => [
                    'dsn' => 'pgsql:host=localhost;dbname=test',
                    'username' => 'postgres',
                    'password' => '',
                    'options' => []
                ]
            ]
        ];

        $connection = ConnectionFactory::create($config);
        $this->assertInstanceOf(PostgreSQLConnection::class, $connection);
    }

    public function testCreateWithSQLiteConfig(): void
    {
        $config = [
            'default' => 'sqlite',
            'connections' => [
                'sqlite' => [
                    'dsn' => 'sqlite::memory:',
                    'options' => []
                ]
            ]
        ];

        $connection = ConnectionFactory::create($config);
        $this->assertInstanceOf(SQLiteConnection::class, $connection);
    }

    public function testCreatePrioritizesMySQLWhenMultipleConfigsExist(): void
    {
        $config = [
            'default' => 'mysql',
            'connections' => [
                'mysql' => [
                    'dsn' => 'mysql:host=localhost;dbname=test',
                    'username' => 'root',
                    'password' => '',
                    'options' => []
                ],
                'sqlite' => [
                    'dsn' => 'sqlite::memory:',
                    'options' => []
                ]
            ]
        ];

        $connection = ConnectionFactory::create($config);
        $this->assertInstanceOf(MySQLConnection::class, $connection);
    }

    public function testCreateThrowsExceptionWithNoValidConfig(): void
    {
        $config = [
            'default' => 'mysql',
            'connections' => []
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Missing config for connection type 'mysql'");

        ConnectionFactory::create($config);
    }

    public function testCreateByTypeWithMySQL(): void
    {
        $config = [
            'default' => 'mysql',
            'connections' => ['mysql' => []]
        ];

        $connection = ConnectionFactory::createByType('mysql', $config);
        $this->assertInstanceOf(MySQLConnection::class, $connection);
    }

    public function testCreateByTypeWithPostgreSQL(): void
    {
        $config = [
            'default' => 'postgresql',
            'connections' => ['postgresql' => []]
        ];

        $connection = ConnectionFactory::createByType('postgresql', $config);
        $this->assertInstanceOf(PostgreSQLConnection::class, $connection);
    }

    public function testCreateByTypeWithPostgreSQLAliases(): void
    {
        $config = [
            'default' => 'postgresql',
            'connections' => [
                'postgresql' => [],
                'postgres' => [],
                'pgsql' => []
            ]
        ];

        $connection1 = ConnectionFactory::createByType('postgres', $config);
        $connection2 = ConnectionFactory::createByType('pgsql', $config);

        $this->assertInstanceOf(PostgreSQLConnection::class, $connection1);
        $this->assertInstanceOf(PostgreSQLConnection::class, $connection2);
    }

    public function testCreateByTypeWithSQLite(): void
    {
        $config = [
            'default' => 'sqlite',
            'connections' => ['sqlite' => []]
        ];

        $connection = ConnectionFactory::createByType('sqlite', $config);
        $this->assertInstanceOf(SQLiteConnection::class, $connection);
    }

    public function testCreateByTypeThrowsExceptionForUnsupportedType(): void
    {
        $config = [
            'default' => 'oracle',
            'connections' => []
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported database type: oracle');

        ConnectionFactory::createByType('oracle', $config);
    }

    public function testCreateByEnum(): void
    {
        $config = [
            'default' => 'mysql',
            'connections' => ['mysql' => []]
        ];

        $connection = ConnectionFactory::createByEnum(DatabaseType::MYSQL, $config);
        $this->assertInstanceOf(MySQLConnection::class, $connection);
    }

    public function testCreateByEnumWithAllTypes(): void
    {
        $config = [
            'default' => 'mysql',
            'connections' => [
                'mysql' => [],
                'postgresql' => [],
                'sqlite' => []
            ]
        ];

        $mysqlConnection = ConnectionFactory::createByEnum(DatabaseType::MYSQL, $config);
        $postgresConnection = ConnectionFactory::createByEnum(DatabaseType::POSTGRESQL, $config);
        $sqliteConnection = ConnectionFactory::createByEnum(DatabaseType::SQLITE, $config);

        $this->assertInstanceOf(MySQLConnection::class, $mysqlConnection);
        $this->assertInstanceOf(PostgreSQLConnection::class, $postgresConnection);
        $this->assertInstanceOf(SQLiteConnection::class, $sqliteConnection);
    }
}
