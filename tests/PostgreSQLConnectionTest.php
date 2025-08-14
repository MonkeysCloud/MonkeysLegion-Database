<?php

declare(strict_types=1);

namespace MonkeysLegion\Database\Tests;

use MonkeysLegion\Database\PostgreSQL\Connection;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

class PostgreSQLConnectionTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $config;

    protected function setUp(): void
    {
        $this->config = [
            'connections' => [
                'postgresql' => [
                    'dsn' => 'pgsql:host=localhost;dbname=test',
                    'username' => 'postgres',
                    'password' => '',
                    'options' => [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    ]
                ]
            ]
        ];
    }

    public function testConstructor(): void
    {
        $connection = new Connection($this->config);
        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertFalse($connection->isConnected());
    }

    public function testIsConnectedReturnsFalseInitially(): void
    {
        $connection = new Connection($this->config);
        $this->assertFalse($connection->isConnected());
    }

    public function testPdoThrowsExceptionWhenNotConnected(): void
    {
        $connection = new Connection($this->config);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Not connected to the database.');

        $connection->pdo();
    }

    public function testDisconnectWhenNotConnected(): void
    {
        $connection = new Connection($this->config);
        $connection->disconnect();

        $this->assertFalse($connection->isConnected());
    }

    public function testConnectWithInvalidConfig(): void
    {
        $invalidConfig = [
            'connections' => [
                'postgresql' => [
                    'dsn' => 'pgsql:host=nonexistent;dbname=test',
                    'username' => 'invalid',
                    'password' => 'invalid',
                    'options' => []
                ]
            ]
        ];

        $connection = new Connection($invalidConfig);

        $this->expectException(PDOException::class);
        $connection->connect();
    }

    public function testHostFallbackOnConnectionError(): void
    {
        $configWithBadHost = [
            'connections' => [
                'postgresql' => [
                    'dsn' => 'pgsql:host=badhost;dbname=test',
                    'username' => 'postgres',
                    'password' => '',
                    'options' => []
                ]
            ]
        ];

        $connection = new Connection($configWithBadHost);

        // This should attempt fallback to localhost, but still fail if PostgreSQL isn't running
        $this->expectException(PDOException::class);
        $connection->connect();
    }

    public function testIsAliveWhenNotConnected(): void
    {
        $connection = new Connection($this->config);

        $this->assertFalse($connection->isAlive());
    }

    public function testConnectWithMissingConfigThrowsException(): void
    {
        $invalidConfig = ['connections' => []];
        $connection = new Connection($invalidConfig);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PostgreSQL connection configuration not found.');
        $connection->connect();
    }

    public function testConnectionRefusedFallback(): void
    {
        $configWithLocalhost = [
            'connections' => [
                'postgresql' => [
                    'dsn' => 'pgsql:host=localhost;port=9999;dbname=test',
                    'username' => 'postgres',
                    'password' => '',
                    'options' => []
                ]
            ]
        ];

        $connection = new Connection($configWithLocalhost);

        $this->expectException(PDOException::class);
        $connection->connect();
    }
}
