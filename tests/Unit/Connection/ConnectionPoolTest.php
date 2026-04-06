<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Tests\Unit\Connection;

use MonkeysLegion\Database\Config\DatabaseConfig;
use MonkeysLegion\Database\Config\DsnConfig;
use MonkeysLegion\Database\Config\PoolConfig;
use MonkeysLegion\Database\Connection\ConnectionPool;
use MonkeysLegion\Database\Exceptions\PoolException;
use MonkeysLegion\Database\Types\DatabaseDriver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConnectionPool::class)]
final class ConnectionPoolTest extends TestCase
{
    private function makePool(int $max = 5, bool $validate = false): ConnectionPool
    {
        $dsn = new DsnConfig(driver: DatabaseDriver::SQLite, memory: true);
        $config = new DatabaseConfig(
            name: 'pool-test',
            driver: DatabaseDriver::SQLite,
            dsn: $dsn,
        );
        $poolConfig = new PoolConfig(
            maxConnections: $max,
            validateOnAcquire: $validate,
        );

        return new ConnectionPool($config, $poolConfig);
    }

    #[Test]
    public function acquireCreatesConnection(): void
    {
        $pool = $this->makePool();
        $conn = $pool->acquire();

        $this->assertTrue($conn->isConnected());
        $this->assertSame(DatabaseDriver::SQLite, $conn->getDriver());
    }

    #[Test]
    public function acquireAndReleaseReusesConnection(): void
    {
        $pool = $this->makePool();

        $conn1 = $pool->acquire();
        $pool->release($conn1);

        $conn2 = $pool->acquire();
        // Should reuse the released connection (same PDO instance)
        $this->assertSame($conn1, $conn2);
    }

    #[Test]
    public function poolExhaustedThrows(): void
    {
        $pool = $this->makePool(max: 2);

        $pool->acquire();
        $pool->acquire();

        $this->expectException(PoolException::class);
        $this->expectExceptionMessage('exhausted');
        $pool->acquire();
    }

    #[Test]
    public function releaseRollsBackDanglingTransaction(): void
    {
        $pool = $this->makePool();
        $conn = $pool->acquire();

        $conn->execute('CREATE TABLE pool_test (id INTEGER PRIMARY KEY)');
        $conn->beginTransaction();
        $conn->execute('INSERT INTO pool_test (id) VALUES (1)');

        // Release should auto-rollback
        $pool->release($conn);

        // Re-acquire and verify the insert was rolled back
        $conn2 = $pool->acquire();
        $stmt = $conn2->query('SELECT COUNT(*) as cnt FROM pool_test');
        $this->assertSame(0, (int) $stmt->fetch(\PDO::FETCH_ASSOC)['cnt']);
    }

    #[Test]
    public function drainDisconnectsAllIdle(): void
    {
        $pool = $this->makePool();

        $c1 = $pool->acquire();
        $c2 = $pool->acquire();
        $pool->release($c1);
        $pool->release($c2);

        $stats = $pool->getStats();
        $this->assertSame(2, $stats->idle);

        $pool->drain();

        $stats = $pool->getStats();
        $this->assertSame(0, $stats->idle);
    }

    #[Test]
    public function getStatsReturnsAccurateMetrics(): void
    {
        $pool = $this->makePool(max: 10);

        $stats = $pool->getStats();
        $this->assertSame(0, $stats->idle);
        $this->assertSame(0, $stats->active);
        $this->assertSame(10, $stats->maxSize);

        $c1 = $pool->acquire();
        $c2 = $pool->acquire();

        $stats = $pool->getStats();
        $this->assertSame(0, $stats->idle);
        $this->assertSame(2, $stats->active);

        $pool->release($c1);

        $stats = $pool->getStats();
        $this->assertSame(1, $stats->idle);
        $this->assertSame(1, $stats->active);
    }

    #[Test]
    public function releaseDiscardsDisconnectedConnection(): void
    {
        $pool = $this->makePool();
        $conn = $pool->acquire();
        $conn->disconnect(); // mark as unhealthy

        $pool->release($conn);

        // Should not be in idle pool since it was disconnected
        $stats = $pool->getStats();
        $this->assertSame(0, $stats->idle);
    }
}
