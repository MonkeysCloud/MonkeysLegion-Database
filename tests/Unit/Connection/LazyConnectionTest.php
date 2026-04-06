<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Tests\Unit\Connection;

use MonkeysLegion\Database\Config\DatabaseConfig;
use MonkeysLegion\Database\Config\DsnConfig;
use MonkeysLegion\Database\Connection\LazyConnection;
use MonkeysLegion\Database\Connection\Connection;
use MonkeysLegion\Database\Types\DatabaseDriver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LazyConnection::class)]
final class LazyConnectionTest extends TestCase
{
    private function makeLazyConnection(): LazyConnection
    {
        return new LazyConnection(
            factory: function (): Connection {
                $dsn = new DsnConfig(driver: DatabaseDriver::SQLite, memory: true);
                $config = new DatabaseConfig(
                    name: 'lazy-test',
                    driver: DatabaseDriver::SQLite,
                    dsn: $dsn,
                );
                $conn = new Connection($config);
                $conn->connect();
                return $conn;
            },
            name: 'lazy-test',
            driver: DatabaseDriver::SQLite,
        );
    }

    #[Test]
    public function startsUninitialized(): void
    {
        $lazy = $this->makeLazyConnection();

        $this->assertFalse($lazy->initialized);
        $this->assertFalse($lazy->isConnected());
        $this->assertFalse($lazy->isAlive());
        $this->assertFalse($lazy->inTransaction());
    }

    #[Test]
    public function driverAndNameAvailableWithoutInitialization(): void
    {
        $lazy = $this->makeLazyConnection();

        $this->assertSame(DatabaseDriver::SQLite, $lazy->getDriver());
        $this->assertSame('lazy-test', $lazy->getName());
        $this->assertFalse($lazy->initialized); // still not initialized
    }

    #[Test]
    public function pdoTriggersInitialization(): void
    {
        $lazy = $this->makeLazyConnection();

        $pdo = $lazy->pdo();
        $this->assertTrue($lazy->initialized);
        $this->assertInstanceOf(\PDO::class, $pdo);
        $this->assertTrue($lazy->isConnected());
    }

    #[Test]
    public function queryTriggersInitialization(): void
    {
        $lazy = $this->makeLazyConnection();

        $stmt = $lazy->query('SELECT 1 as val');
        $this->assertTrue($lazy->initialized);
        $this->assertEquals(1, $stmt->fetch(\PDO::FETCH_ASSOC)['val']);
    }

    #[Test]
    public function executeTriggersInitialization(): void
    {
        $lazy = $this->makeLazyConnection();

        $lazy->execute('CREATE TABLE lazy_test (id INTEGER PRIMARY KEY)');
        $this->assertTrue($lazy->initialized);
    }

    #[Test]
    public function transactionWorksAfterInitialization(): void
    {
        $lazy = $this->makeLazyConnection();

        $result = $lazy->transaction(function ($conn) {
            $conn->execute('CREATE TABLE lazy_tx (id INTEGER PRIMARY KEY)');
            $conn->execute('INSERT INTO lazy_tx (id) VALUES (1)');
            return 42;
        });

        $this->assertSame(42, $result);
        $this->assertTrue($lazy->initialized);
    }

    #[Test]
    public function disconnectResetsInitializedState(): void
    {
        $lazy = $this->makeLazyConnection();

        $lazy->pdo(); // force init
        $this->assertTrue($lazy->initialized);

        $lazy->disconnect();
        $this->assertFalse($lazy->initialized);
        $this->assertFalse($lazy->isConnected());
    }

    #[Test]
    public function reconnectAfterDisconnect(): void
    {
        $lazy = $this->makeLazyConnection();

        $lazy->pdo();
        $lazy->disconnect();
        $this->assertFalse($lazy->initialized);

        // Access again — should re-create
        $pdo = $lazy->pdo();
        $this->assertTrue($lazy->initialized);
        $this->assertInstanceOf(\PDO::class, $pdo);
    }
}
