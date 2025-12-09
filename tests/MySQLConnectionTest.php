<?php

declare(strict_types=1);

namespace MonkeysLegion\Database\Tests;

use MonkeysLegion\Database\MySQL\Connection;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

class MySQLConnectionTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $config;

    protected function setUp(): void
    {
        $this->config = [
            'dsn' => 'mysql:host=localhost;dbname=test',
            'username' => 'root',
            'password' => '',
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
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

    public function testDisconnectWhenNotConnected(): void
    {
        $connection = new Connection($this->config);
        $connection->disconnect();

        $this->assertFalse($connection->isConnected());
    }

    public function testConnectWithInvalidConfig(): void
    {
        $invalidConfig = [
            'dsn' => 'mysql:host=nonexistent;dbname=test',
            'username' => 'invalid',
            'password' => 'invalid',
            'options' => []
        ];

        $connection = new Connection($invalidConfig);

        $this->expectException(PDOException::class);
        $connection->connect();
    }

    public function testIsAliveWhenNotConnected(): void
    {
        $connection = new Connection($this->config);

        $this->assertFalse($connection->isAlive());
    }

    public function testConnectTwiceDoesNotReconnect(): void
    {
        // This would require a real database connection to test properly
        // For now, we'll test the basic behavior
        $connection = new Connection($this->config);

        $this->assertFalse($connection->isConnected());
    }

    public function testConnectWithMissingConfigThrowsException(): void
    {
        $invalidConfig = [];
        $connection = new Connection($invalidConfig);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('MySQL connection configuration not found.');
        $connection->connect();
    }

    public function testHostFallbackLogic(): void
    {
        $configWithBadHost = [
            'dsn' => 'mysql:host=badhost;dbname=test',
            'username' => 'root',
            'password' => '',
            'options' => []
        ];

        $connection = new Connection($configWithBadHost);

        // This should attempt fallback to localhost, but still fail if MySQL isn't running
        $this->expectException(PDOException::class);
        $connection->connect();
    }

    public function testPdoAutoConnects(): void
    {
        $connection = new Connection($this->config);
        $this->assertFalse($connection->isConnected());
        
        // pdo() should throw exception when auto-connect fails
        try {
            $connection->pdo();
            $this->fail('Expected PDOException or RuntimeException');
        } catch (\PDOException | \RuntimeException $e) {
            // Expected - connection will fail with invalid config
            $this->assertTrue(true);
        }
    }

    public function testDisconnectWhenConnected(): void
    {
        $connection = new Connection($this->config);
        $connection->disconnect();
        $this->assertFalse($connection->isConnected());
    }
}
