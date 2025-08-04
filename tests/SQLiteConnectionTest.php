<?php

declare(strict_types=1);

namespace MonkeysLegion\Database\Tests;

use MonkeysLegion\Database\SQLite\Connection;
use PDO;
use PHPUnit\Framework\TestCase;

class SQLiteConnectionTest extends TestCase
{
    private array $config;
    private string $tempDbFile;

    protected function setUp(): void
    {
        $this->tempDbFile = tempnam(sys_get_temp_dir(), 'test_sqlite_');
        $this->config = [
            'connections' => [
                'sqlite' => [
                    'dsn' => "sqlite:{$this->tempDbFile}",
                    'options' => [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    ]
                ]
            ]
        ];
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempDbFile)) {
            unlink($this->tempDbFile);
        }
    }

    public function testConstructor(): void
    {
        $connection = new Connection($this->config);
        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertFalse($connection->isConnected());
    }

    public function testConnect(): void
    {
        $connection = new Connection($this->config);
        $connection->connect();

        $this->assertTrue($connection->isConnected());
        $this->assertInstanceOf(PDO::class, $connection->pdo());
    }

    public function testConnectTwiceDoesNotReconnect(): void
    {
        $connection = new Connection($this->config);
        $connection->connect();

        $firstPdo = $connection->pdo();
        $connection->connect();
        $secondPdo = $connection->pdo();

        $this->assertSame($firstPdo, $secondPdo);
    }

    public function testDisconnect(): void
    {
        $connection = new Connection($this->config);
        $connection->connect();

        $this->assertTrue($connection->isConnected());

        $connection->disconnect();

        $this->assertFalse($connection->isConnected());
    }

    public function testPragmaSettings(): void
    {
        $connection = new Connection($this->config);
        $connection->connect();

        $pdo = $connection->pdo();

        // Test foreign keys are enabled
        $stmt = $pdo->query("PRAGMA foreign_keys");
        $result = $stmt->fetch();
        $this->assertEquals('1', $result['foreign_keys']);

        // Test journal mode is WAL
        $stmt = $pdo->query("PRAGMA journal_mode");
        $result = $stmt->fetch();
        $this->assertEquals('wal', strtolower($result['journal_mode']));
    }

    public function testPdoThrowsExceptionWhenNotConnected(): void
    {
        $connection = new Connection($this->config);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Not connected to the database.');

        $connection->pdo();
    }

    public function testIsAliveWhenConnected(): void
    {
        $connection = new Connection($this->config);
        $connection->connect();

        $this->assertTrue($connection->isAlive());
    }

    public function testIsAliveWhenNotConnected(): void
    {
        $connection = new Connection($this->config);

        $this->assertFalse($connection->isAlive());
    }

    public function testIsAliveAfterDisconnect(): void
    {
        $connection = new Connection($this->config);
        $connection->connect();

        $this->assertTrue($connection->isAlive());

        $connection->disconnect();

        $this->assertFalse($connection->isAlive());
    }

    public function testConnectWithMissingConfigThrowsException(): void
    {
        $invalidConfig = ['connections' => []];
        $connection = new Connection($invalidConfig);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SQLite connection configuration not found.');
        $connection->connect();
    }

    public function testConnectWithInvalidDsnThrowsException(): void
    {
        $invalidConfig = [
            'connections' => [
                'sqlite' => [
                    'dsn' => 'sqlite:/invalid/path/database.db',
                    'options' => []
                ]
            ]
        ];

        $connection = new Connection($invalidConfig);

        $this->expectException(\Throwable::class);
        $connection->connect();
    }
}
