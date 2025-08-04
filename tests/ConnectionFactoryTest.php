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
        $config = ['connections' => []];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No valid database connection configuration found.');

        ConnectionFactory::create($config);
    }

    public function testCreateByTypeWithMySQL(): void
    {
        $config = ['connections' => ['mysql' => []]];

        $connection = ConnectionFactory::createByType('mysql', $config);
        $this->assertInstanceOf(MySQLConnection::class, $connection);
    }

    public function testCreateByTypeWithPostgreSQL(): void
    {
        $config = ['connections' => ['postgresql' => []]];

        $connection = ConnectionFactory::createByType('postgresql', $config);
        $this->assertInstanceOf(PostgreSQLConnection::class, $connection);
    }

    public function testCreateByTypeWithPostgreSQLAliases(): void
    {
        $config = ['connections' => ['postgresql' => []]];

        $connection1 = ConnectionFactory::createByType('postgres', $config);
        $connection2 = ConnectionFactory::createByType('pgsql', $config);

        $this->assertInstanceOf(PostgreSQLConnection::class, $connection1);
        $this->assertInstanceOf(PostgreSQLConnection::class, $connection2);
    }

    public function testCreateByTypeWithSQLite(): void
    {
        $config = ['connections' => ['sqlite' => []]];

        $connection = ConnectionFactory::createByType('sqlite', $config);
        $this->assertInstanceOf(SQLiteConnection::class, $connection);
    }

    public function testCreateByTypeThrowsExceptionForUnsupportedType(): void
    {
        $config = [];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported database type: oracle');

        ConnectionFactory::createByType('oracle', $config);
    }

    public function testCreateByEnum(): void
    {
        $config = ['connections' => ['mysql' => []]];

        $connection = ConnectionFactory::createByEnum(DatabaseType::MYSQL, $config);
        $this->assertInstanceOf(MySQLConnection::class, $connection);
    }

    public function testCreateByEnumWithAllTypes(): void
    {
        $config = ['connections' => []];

        $mysqlConnection = ConnectionFactory::createByEnum(DatabaseType::MYSQL, $config);
        $postgresConnection = ConnectionFactory::createByEnum(DatabaseType::POSTGRESQL, $config);
        $sqliteConnection = ConnectionFactory::createByEnum(DatabaseType::SQLITE, $config);

        $this->assertInstanceOf(MySQLConnection::class, $mysqlConnection);
        $this->assertInstanceOf(PostgreSQLConnection::class, $postgresConnection);
        $this->assertInstanceOf(SQLiteConnection::class, $sqliteConnection);
    }
}
