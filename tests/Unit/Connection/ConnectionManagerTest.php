<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Tests\Unit\Connection;

use MonkeysLegion\Database\Config\DatabaseConfig;
use MonkeysLegion\Database\Config\DsnConfig;
use MonkeysLegion\Database\Connection\ConnectionManager;
use MonkeysLegion\Database\Exceptions\ConfigurationException;
use MonkeysLegion\Database\Types\DatabaseDriver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConnectionManager::class)]
final class ConnectionManagerTest extends TestCase
{
    private function makeManager(): ConnectionManager
    {
        $dsn = new DsnConfig(driver: DatabaseDriver::SQLite, memory: true);
        $config = new DatabaseConfig(
            name: 'default',
            driver: DatabaseDriver::SQLite,
            dsn: $dsn,
        );

        return new ConnectionManager(['default' => $config]);
    }

    private function makeMultiManager(): ConnectionManager
    {
        $dsn1 = new DsnConfig(driver: DatabaseDriver::SQLite, memory: true);
        $config1 = new DatabaseConfig(name: 'primary', driver: DatabaseDriver::SQLite, dsn: $dsn1);

        $dsn2 = new DsnConfig(driver: DatabaseDriver::SQLite, memory: true);
        $config2 = new DatabaseConfig(name: 'secondary', driver: DatabaseDriver::SQLite, dsn: $dsn2);

        return new ConnectionManager([
            'primary' => $config1,
            'secondary' => $config2,
        ]);
    }

    // ── Basic Connection ────────────────────────────────────────

    #[Test]
    public function connectionReturnsLazyConnection(): void
    {
        $mgr = $this->makeManager();
        $conn = $mgr->connection();

        $this->assertSame(DatabaseDriver::SQLite, $conn->getDriver());
        $this->assertSame('default', $conn->getName());
    }

    #[Test]
    public function connectionReturnsSameInstance(): void
    {
        $mgr = $this->makeManager();
        $conn1 = $mgr->connection();
        $conn2 = $mgr->connection();

        $this->assertSame($conn1, $conn2);
    }

    #[Test]
    public function writeReturnsSameAsConnection(): void
    {
        $mgr = $this->makeManager();
        $this->assertSame($mgr->connection(), $mgr->write());
    }

    #[Test]
    public function readFallsBackToWriteWithoutReplicas(): void
    {
        $mgr = $this->makeManager();
        $this->assertSame($mgr->read(), $mgr->write());
    }

    // ── Named Connections ───────────────────────────────────────

    #[Test]
    public function defaultConnectionIsFirstConfig(): void
    {
        $mgr = $this->makeMultiManager();
        $this->assertSame('primary', $mgr->getDefaultConnectionName());
    }

    #[Test]
    public function canAccessNamedConnection(): void
    {
        $mgr = $this->makeMultiManager();
        $conn = $mgr->connection('secondary');
        $this->assertSame('secondary', $conn->getName());
    }

    #[Test]
    public function setDefaultConnection(): void
    {
        $mgr = $this->makeMultiManager();
        $mgr->setDefaultConnection('secondary');

        $this->assertSame('secondary', $mgr->getDefaultConnectionName());
        $conn = $mgr->connection();
        $this->assertSame('secondary', $conn->getName());
    }

    #[Test]
    public function setDefaultConnectionThrowsOnInvalidName(): void
    {
        $mgr = $this->makeManager();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('nonexistent');
        $mgr->setDefaultConnection('nonexistent');
    }

    #[Test]
    public function connectionThrowsOnInvalidName(): void
    {
        $mgr = $this->makeManager();

        $this->expectException(ConfigurationException::class);
        $mgr->connection('nonexistent');
    }

    // ── Disconnect ──────────────────────────────────────────────

    #[Test]
    public function disconnectReleasesConnection(): void
    {
        $mgr = $this->makeManager();
        $conn = $mgr->connection();
        $conn->pdo(); // force initialization

        $this->assertTrue($conn->isConnected());

        $mgr->disconnect();

        // After disconnect, getting connection() returns a new instance
        $conn2 = $mgr->connection();
        $this->assertNotSame($conn, $conn2);
    }

    #[Test]
    public function disconnectAllReleasesEverything(): void
    {
        $mgr = $this->makeMultiManager();

        $c1 = $mgr->connection('primary');
        $c2 = $mgr->connection('secondary');
        $c1->pdo();
        $c2->pdo();

        $mgr->disconnectAll();

        // Both should be disconnected, new instances on next access
        $c1new = $mgr->connection('primary');
        $c2new = $mgr->connection('secondary');
        $this->assertNotSame($c1, $c1new);
        $this->assertNotSame($c2, $c2new);
    }

    // ── fromArray Factory ───────────────────────────────────────

    #[Test]
    public function fromArrayBuildsManager(): void
    {
        $mgr = ConnectionManager::fromArray([
            'default' => [
                'driver' => 'sqlite',
                'memory' => true,
            ],
        ]);

        $conn = $mgr->connection();
        $this->assertSame(DatabaseDriver::SQLite, $conn->getDriver());

        // Verify it actually works
        $stmt = $conn->query('SELECT 1 as val');
        $this->assertEquals(1, $stmt->fetch(\PDO::FETCH_ASSOC)['val']);
    }

    // ── Empty Config ────────────────────────────────────────────

    #[Test]
    public function emptyConfigThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('At least one');
        new ConnectionManager([]);
    }

    // ── Stats ───────────────────────────────────────────────────

    #[Test]
    public function statsReturnsPoolStatsPerConnection(): void
    {
        $mgr = $this->makeMultiManager();
        $stats = $mgr->stats();

        $this->assertArrayHasKey('primary', $stats);
        $this->assertArrayHasKey('secondary', $stats);
        $this->assertSame(0, $stats['primary']->active);
    }

    #[Test]
    public function statsReflectsActiveConnections(): void
    {
        $mgr = $this->makeManager();
        $mgr->connection()->pdo(); // force connect

        $stats = $mgr->stats();
        $this->assertSame(1, $stats['default']->active);
    }

    // ── Sticky Write ────────────────────────────────────────────

    #[Test]
    public function stickyWriteCanBeSetAndReset(): void
    {
        $mgr = $this->makeManager();

        // Without replicas, read() always returns write() anyway
        // But we can verify the methods don't throw
        $mgr->markWritePerformed();
        $mgr->resetSticky();

        // No assertion needed — just verifying no exceptions
        $this->assertTrue(true);
    }

    // ── PHP 8.4 set-hook propagation ────────────────────────────

    #[Test]
    public function loggerSetHookPropagatesImmediatelyToOpenConnections(): void
    {
        $mgr  = $this->makeManager();
        $conn = $mgr->connection();
        // Calling pdo() forces the lazy wrapper to resolve into a real Connection
        $conn->pdo();

        $logger = new class implements \Psr\Log\LoggerInterface {
            use \Psr\Log\LoggerTrait;
            public array $records = [];
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = $message;
            }
        };

        // Assign via property — the set hook must propagate to the open Connection
        $mgr->logger = $logger;

        // Execute a query so the logger gets a record
        $conn->query('SELECT 1');

        $this->assertNotEmpty($logger->records);
    }

    #[Test]
    public function loggerAssignedBeforeConnectionOpenIsPickedUpByFactory(): void
    {
        $mgr = $this->makeManager();

        $logger = new class implements \Psr\Log\LoggerInterface {
            use \Psr\Log\LoggerTrait;
            public array $records = [];
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = $message;
            }
        };

        // Assign before any connection is opened
        $mgr->logger = $logger;

        // Now open connection — factory closure must inject the logger
        $mgr->connection()->query('SELECT 1');

        $this->assertNotEmpty($logger->records);
    }
}
