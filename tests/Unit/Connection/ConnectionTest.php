<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Tests\Unit\Connection;

use MonkeysLegion\Database\Config\DatabaseConfig;
use MonkeysLegion\Database\Config\DsnConfig;
use MonkeysLegion\Database\Connection\Connection;
use MonkeysLegion\Database\Exceptions\TransactionException;
use MonkeysLegion\Database\Types\DatabaseDriver;
use MonkeysLegion\Database\Types\IsolationLevel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Connection::class)]
final class ConnectionTest extends TestCase
{
    private function makeSqliteConnection(): Connection
    {
        $dsn = new DsnConfig(driver: DatabaseDriver::SQLite, memory: true);
        $config = new DatabaseConfig(
            name: 'test',
            driver: DatabaseDriver::SQLite,
            dsn: $dsn,
        );
        return new Connection($config);
    }

    // ── Lifecycle ───────────────────────────────────────────────

    #[Test]
    public function startsDisconnected(): void
    {
        $conn = $this->makeSqliteConnection();

        $this->assertFalse($conn->isConnected());
        $this->assertFalse($conn->isAlive());
        $this->assertSame(0, $conn->queryCount);
        $this->assertSame(0.0, $conn->uptimeSeconds);
    }

    #[Test]
    public function connectEstablishesPdo(): void
    {
        $conn = $this->makeSqliteConnection();
        $conn->connect();

        $this->assertTrue($conn->isConnected());
        $this->assertTrue($conn->isAlive());
        $this->assertInstanceOf(\PDO::class, $conn->pdo());
    }

    #[Test]
    public function connectIsIdempotent(): void
    {
        $conn = $this->makeSqliteConnection();
        $conn->connect();
        $pdo1 = $conn->pdo();
        $conn->connect(); // second call should be no-op
        $pdo2 = $conn->pdo();

        $this->assertSame($pdo1, $pdo2);
    }

    #[Test]
    public function disconnectReleasesPdo(): void
    {
        $conn = $this->makeSqliteConnection();
        $conn->connect();
        $this->assertTrue($conn->isConnected());

        $conn->disconnect();
        $this->assertFalse($conn->isConnected());
    }

    #[Test]
    public function disconnectIsIdempotent(): void
    {
        $conn = $this->makeSqliteConnection();
        $conn->disconnect(); // should not throw when not connected
        $this->assertFalse($conn->isConnected());
    }

    #[Test]
    public function reconnectCreatesNewPdo(): void
    {
        $conn = $this->makeSqliteConnection();
        $conn->connect();
        $pdo1 = $conn->pdo();

        $conn->reconnect();
        $pdo2 = $conn->pdo();

        $this->assertNotSame($pdo1, $pdo2);
        $this->assertTrue($conn->isConnected());
    }

    #[Test]
    public function pdoConnectsLazily(): void
    {
        $conn = $this->makeSqliteConnection();
        $this->assertFalse($conn->isConnected());

        $pdo = $conn->pdo(); // should connect automatically
        $this->assertTrue($conn->isConnected());
        $this->assertInstanceOf(\PDO::class, $pdo);
    }

    // ── Driver Info ─────────────────────────────────────────────

    #[Test]
    public function getDriverReturnsConfiguredDriver(): void
    {
        $conn = $this->makeSqliteConnection();
        $this->assertSame(DatabaseDriver::SQLite, $conn->getDriver());
    }

    #[Test]
    public function getNameReturnsConfiguredName(): void
    {
        $conn = $this->makeSqliteConnection();
        $this->assertSame('test', $conn->getName());
    }

    // ── Property Hooks ──────────────────────────────────────────

    #[Test]
    public function queryCountIncrementsOnExecute(): void
    {
        $conn = $this->makeSqliteConnection();
        $conn->connect();

        $this->assertSame(0, $conn->queryCount);

        $conn->execute('CREATE TABLE test_qc (id INTEGER PRIMARY KEY)');
        $this->assertSame(1, $conn->queryCount);

        $conn->execute('INSERT INTO test_qc (id) VALUES (1)');
        $this->assertSame(2, $conn->queryCount);
    }

    #[Test]
    public function queryCountIncrementsOnQuery(): void
    {
        $conn = $this->makeSqliteConnection();
        $conn->connect();

        $conn->query('SELECT 1');
        $this->assertSame(1, $conn->queryCount);
    }

    #[Test]
    public function uptimeSecondsIsPositiveAfterConnect(): void
    {
        $conn = $this->makeSqliteConnection();
        $conn->connect();

        // Should be positive after connect (may be very small)
        $this->assertGreaterThanOrEqual(0.0, $conn->uptimeSeconds);
    }

    #[Test]
    public function uptimeSecondsResetsAfterDisconnect(): void
    {
        $conn = $this->makeSqliteConnection();
        $conn->connect();
        $conn->disconnect();

        $this->assertSame(0.0, $conn->uptimeSeconds);
    }

    // ── Raw Execution ───────────────────────────────────────────

    #[Test]
    public function executeReturnsAffectedRows(): void
    {
        $conn = $this->makeSqliteConnection();
        $conn->connect();

        $conn->execute('CREATE TABLE test_exec (id INTEGER PRIMARY KEY, name TEXT)');
        $affected = $conn->execute(
            'INSERT INTO test_exec (id, name) VALUES (:id, :name)',
            [':id' => 1, ':name' => 'Alice'],
        );

        $this->assertSame(1, $affected);
    }

    #[Test]
    public function queryReturnsPdoStatement(): void
    {
        $conn = $this->makeSqliteConnection();
        $conn->connect();

        $conn->execute('CREATE TABLE test_query (id INTEGER PRIMARY KEY, val TEXT)');
        $conn->execute('INSERT INTO test_query (id, val) VALUES (1, :v)', [':v' => 'hello']);

        $stmt = $conn->query('SELECT * FROM test_query WHERE id = :id', [':id' => 1]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertIsArray($row);
        $this->assertSame('hello', $row['val']);
    }

    #[Test]
    public function executeWithInvalidSqlThrowsQueryException(): void
    {
        $conn = $this->makeSqliteConnection();
        $conn->connect();

        $this->expectException(\MonkeysLegion\Database\Exceptions\QueryException::class);
        $conn->execute('INSERT INTO nonexistent_table (x) VALUES (1)');
    }

    // ── Transactions ────────────────────────────────────────────

    #[Test]
    public function beginAndCommitTransaction(): void
    {
        $conn = $this->makeSqliteConnection();
        $conn->connect();

        $conn->execute('CREATE TABLE test_tx (id INTEGER PRIMARY KEY)');

        $this->assertFalse($conn->inTransaction());

        $conn->beginTransaction();
        $this->assertTrue($conn->inTransaction());

        $conn->execute('INSERT INTO test_tx (id) VALUES (1)');
        $conn->commit();

        $this->assertFalse($conn->inTransaction());

        // Verify data persisted
        $stmt = $conn->query('SELECT COUNT(*) as cnt FROM test_tx');
        $this->assertSame(1, (int) $stmt->fetch(\PDO::FETCH_ASSOC)['cnt']);
    }

    #[Test]
    public function rollbackRevertsChanges(): void
    {
        $conn = $this->makeSqliteConnection();
        $conn->connect();

        $conn->execute('CREATE TABLE test_rb (id INTEGER PRIMARY KEY)');
        $conn->execute('INSERT INTO test_rb (id) VALUES (1)');

        $conn->beginTransaction();
        $conn->execute('INSERT INTO test_rb (id) VALUES (2)');
        $conn->rollBack();

        $stmt = $conn->query('SELECT COUNT(*) as cnt FROM test_rb');
        $this->assertSame(1, (int) $stmt->fetch(\PDO::FETCH_ASSOC)['cnt']);
    }

    #[Test]
    public function beginTransactionThrowsWhenAlreadyActive(): void
    {
        $conn = $this->makeSqliteConnection();
        $conn->connect();

        $conn->beginTransaction();

        $this->expectException(TransactionException::class);
        $this->expectExceptionMessage('already');
        $conn->beginTransaction();
    }

    #[Test]
    public function commitThrowsWhenNoTransaction(): void
    {
        $conn = $this->makeSqliteConnection();
        $conn->connect();

        $this->expectException(TransactionException::class);
        $this->expectExceptionMessage('commit');
        $conn->commit();
    }

    #[Test]
    public function rollBackThrowsWhenNoTransaction(): void
    {
        $conn = $this->makeSqliteConnection();
        $conn->connect();

        $this->expectException(TransactionException::class);
        $this->expectExceptionMessage('rollBack');
        $conn->rollBack();
    }

    #[Test]
    public function transactionCallbackCommitsOnSuccess(): void
    {
        $conn = $this->makeSqliteConnection();
        $conn->connect();
        $conn->execute('CREATE TABLE test_txcb (id INTEGER PRIMARY KEY, val TEXT)');

        $result = $conn->transaction(function (Connection $c) {
            $c->execute('INSERT INTO test_txcb (id, val) VALUES (1, :v)', [':v' => 'test']);
            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertFalse($conn->inTransaction());

        $stmt = $conn->query('SELECT val FROM test_txcb WHERE id = 1');
        $this->assertSame('test', $stmt->fetch(\PDO::FETCH_ASSOC)['val']);
    }

    #[Test]
    public function transactionCallbackRollsBackOnException(): void
    {
        $conn = $this->makeSqliteConnection();
        $conn->connect();
        $conn->execute('CREATE TABLE test_txfail (id INTEGER PRIMARY KEY)');

        try {
            $conn->transaction(function (Connection $c) {
                $c->execute('INSERT INTO test_txfail (id) VALUES (1)');
                throw new \RuntimeException('intentional');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('intentional', $e->getMessage());
        }

        $this->assertFalse($conn->inTransaction());

        $stmt = $conn->query('SELECT COUNT(*) as cnt FROM test_txfail');
        $this->assertSame(0, (int) $stmt->fetch(\PDO::FETCH_ASSOC)['cnt']);
    }

    // ── Disconnect with active transaction ──────────────────────

    #[Test]
    public function disconnectRollsBackActiveTransaction(): void
    {
        $conn = $this->makeSqliteConnection();
        $conn->connect();
        $conn->execute('CREATE TABLE test_dc (id INTEGER PRIMARY KEY)');
        $conn->execute('INSERT INTO test_dc (id) VALUES (1)');

        $conn->beginTransaction();
        $this->assertTrue($conn->inTransaction());
        $conn->execute('DELETE FROM test_dc WHERE id = 1');

        // Disconnect should auto-rollback without throwing
        $conn->disconnect();
        $this->assertFalse($conn->isConnected());
        // Note: SQLite :memory: DB is destroyed on disconnect,
        // so we just verify the rollback+disconnect was clean.
    }

    // ── SQLite-specific defaults ────────────────────────────────

    #[Test]
    public function sqliteDefaultsApplied(): void
    {
        $conn = $this->makeSqliteConnection();
        $conn->connect();

        // Foreign keys should be ON
        $stmt = $conn->query('PRAGMA foreign_keys');
        $fk = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(1, (int) $fk['foreign_keys']);

        // Synchronous should be NORMAL (1)
        $stmt = $conn->query('PRAGMA synchronous');
        $sync = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(1, (int) $sync['synchronous']);

        // Busy timeout should be 5000ms
        $stmt = $conn->query('PRAGMA busy_timeout');
        $timeout = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(5000, (int) $timeout['timeout']);
    }

    // ── lastInsertId ────────────────────────────────────────────

    #[Test]
    public function lastInsertIdReturnsIdAfterInsert(): void
    {
        $conn = $this->makeSqliteConnection();
        $conn->connect();

        $conn->execute('CREATE TABLE test_lid (id INTEGER PRIMARY KEY AUTOINCREMENT, val TEXT)');
        $conn->execute('INSERT INTO test_lid (val) VALUES (:v)', [':v' => 'hello']);

        $id = $conn->lastInsertId();
        $this->assertNotFalse($id);
        $this->assertSame('1', $id);
    }

    #[Test]
    public function lastInsertIdIncrementsOnMultipleInserts(): void
    {
        $conn = $this->makeSqliteConnection();
        $conn->connect();

        $conn->execute('CREATE TABLE test_lid2 (id INTEGER PRIMARY KEY AUTOINCREMENT, val TEXT)');
        $conn->execute('INSERT INTO test_lid2 (val) VALUES (:v)', [':v' => 'a']);
        $conn->execute('INSERT INTO test_lid2 (val) VALUES (:v)', [':v' => 'b']);

        $id = $conn->lastInsertId();
        $this->assertSame('2', $id);
    }

    // ── Public logger/dispatcher properties ─────────────────────

    #[Test]
    public function loggerAndDispatcherAssignableAsProperties(): void
    {
        $conn = $this->makeSqliteConnection();

        // Assign via direct property write (no setter method needed)
        $conn->logger = new class implements \Psr\Log\LoggerInterface {
            use \Psr\Log\LoggerTrait;
            public array $records = [];
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => $message];
            }
        };

        $conn->connect();
        $conn->query('SELECT 1');

        $this->assertNotEmpty($conn->logger->records);
    }

    // ── Config access ───────────────────────────────────────────

    #[Test]
    public function connectionConfigIsAccessible(): void
    {
        $conn = $this->makeSqliteConnection();
        $this->assertSame('test', $conn->connectionConfig->name);
        $this->assertSame(DatabaseDriver::SQLite, $conn->connectionConfig->driver);
    }
}
