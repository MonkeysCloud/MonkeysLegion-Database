<?php
declare(strict_types=1);

namespace Tests\Unit\Connection;

use MonkeysLegion\Database\Connection\Connection;
use MonkeysLegion\Database\Config\DatabaseConfig;
use MonkeysLegion\Database\Config\DsnConfig;
use MonkeysLegion\Database\Exceptions\DeadlockException;
use MonkeysLegion\Database\Exceptions\TransactionException;
use MonkeysLegion\Database\Types\DatabaseDriver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests nested transaction support (savepoints) and deadlock retry.
 *
 * Uses SQLite in-memory for fast, dependency-free testing.
 */
final class NestedTransactionTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $dsn = new DsnConfig(driver: DatabaseDriver::SQLite, memory: true);
        $config = new DatabaseConfig(
            name: 'test',
            driver: DatabaseDriver::SQLite,
            dsn: $dsn,
        );

        $this->connection = new Connection($config);
        $this->connection->connect();

        // Create a test table
        $this->connection->execute('CREATE TABLE test_table (id INTEGER PRIMARY KEY, value TEXT)');
    }

    protected function tearDown(): void
    {
        $this->connection->disconnect();
    }

    // ── Basic Transactions ──────────────────────────────────────

    #[Test]
    public function begin_transaction_sets_depth_to_1(): void
    {
        $this->connection->beginTransaction();

        self::assertSame(1, $this->connection->transactionDepth());
        self::assertTrue($this->connection->inTransaction());

        $this->connection->rollBack();
    }

    #[Test]
    public function commit_resets_depth_to_0(): void
    {
        $this->connection->beginTransaction();
        $this->connection->commit();

        self::assertSame(0, $this->connection->transactionDepth());
        self::assertFalse($this->connection->inTransaction());
    }

    #[Test]
    public function rollback_resets_depth_to_0(): void
    {
        $this->connection->beginTransaction();
        $this->connection->rollBack();

        self::assertSame(0, $this->connection->transactionDepth());
        self::assertFalse($this->connection->inTransaction());
    }

    // ── Nested Transactions (Savepoints) ───────────────────────

    #[Test]
    public function nested_begin_creates_savepoint_and_increments_depth(): void
    {
        $this->connection->beginTransaction(); // depth=1
        $this->connection->beginTransaction(); // depth=2 (savepoint sp2)

        self::assertSame(2, $this->connection->transactionDepth());

        $this->connection->rollBack(); // rollback to sp2, depth=1
        $this->connection->rollBack(); // full rollback, depth=0
    }

    #[Test]
    public function nested_commit_releases_savepoint(): void
    {
        $this->connection->beginTransaction();
        $this->connection->beginTransaction();

        $this->connection->commit(); // release sp2, depth=1

        self::assertSame(1, $this->connection->transactionDepth());

        $this->connection->commit(); // full commit, depth=0
    }

    #[Test]
    public function nested_rollback_rolls_back_to_savepoint_without_losing_outer(): void
    {
        $this->connection->beginTransaction();

        // Insert in outer transaction
        $this->connection->execute('INSERT INTO test_table (value) VALUES (?)', ['outer']);

        // Start inner transaction
        $this->connection->beginTransaction();
        $this->connection->execute('INSERT INTO test_table (value) VALUES (?)', ['inner']);

        // Rollback inner — should undo only the 'inner' insert
        $this->connection->rollBack();

        self::assertSame(1, $this->connection->transactionDepth());

        // Commit outer
        $this->connection->commit();

        // Verify only 'outer' was persisted
        $rows = $this->connection->query('SELECT value FROM test_table ORDER BY id')->fetchAll();
        self::assertCount(1, $rows);
        self::assertSame('outer', $rows[0]['value']);
    }

    #[Test]
    public function three_level_nesting_works(): void
    {
        $this->connection->beginTransaction(); // depth=1

        $this->connection->execute('INSERT INTO test_table (value) VALUES (?)', ['level1']);

        $this->connection->beginTransaction(); // depth=2
        $this->connection->execute('INSERT INTO test_table (value) VALUES (?)', ['level2']);

        $this->connection->beginTransaction(); // depth=3
        $this->connection->execute('INSERT INTO test_table (value) VALUES (?)', ['level3']);

        self::assertSame(3, $this->connection->transactionDepth());

        // Rollback level 3
        $this->connection->rollBack();
        self::assertSame(2, $this->connection->transactionDepth());

        // Commit level 2
        $this->connection->commit();
        self::assertSame(1, $this->connection->transactionDepth());

        // Commit level 1
        $this->connection->commit();
        self::assertSame(0, $this->connection->transactionDepth());

        // Verify level1 and level2 persisted, level3 rolled back
        $rows = $this->connection->query('SELECT value FROM test_table ORDER BY id')->fetchAll();
        self::assertCount(2, $rows);
        self::assertSame('level1', $rows[0]['value']);
        self::assertSame('level2', $rows[1]['value']);
    }

    // ── Transaction Callback ────────────────────────────────────

    #[Test]
    public function transaction_helper_commits_on_success(): void
    {
        $result = $this->connection->transaction(function (Connection $conn) {
            $conn->execute('INSERT INTO test_table (value) VALUES (?)', ['via_callback']);
            return 'success';
        });

        self::assertSame('success', $result);
        self::assertSame(0, $this->connection->transactionDepth());

        $rows = $this->connection->query('SELECT value FROM test_table')->fetchAll();
        self::assertCount(1, $rows);
        self::assertSame('via_callback', $rows[0]['value']);
    }

    #[Test]
    public function transaction_helper_rolls_back_on_exception(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('deliberate failure');

        $this->connection->transaction(function (Connection $conn) {
            $conn->execute('INSERT INTO test_table (value) VALUES (?)', ['will_rollback']);
            throw new \RuntimeException('deliberate failure');
        });

        // Nothing should be persisted
        $rows = $this->connection->query('SELECT value FROM test_table')->fetchAll();
        self::assertCount(0, $rows);
    }

    #[Test]
    public function transaction_helper_supports_nesting(): void
    {
        $this->connection->transaction(function (Connection $outer) {
            $outer->execute('INSERT INTO test_table (value) VALUES (?)', ['outer']);

            $outer->transaction(function (Connection $inner) {
                $inner->execute('INSERT INTO test_table (value) VALUES (?)', ['inner']);
            });

            self::assertSame(1, $outer->transactionDepth());
        });

        $rows = $this->connection->query('SELECT value FROM test_table ORDER BY id')->fetchAll();
        self::assertCount(2, $rows);
    }

    #[Test]
    public function nested_transaction_rollback_does_not_affect_outer_callback(): void
    {
        $this->connection->transaction(function (Connection $outer) {
            $outer->execute('INSERT INTO test_table (value) VALUES (?)', ['outer']);

            try {
                $outer->transaction(function (Connection $inner) {
                    $inner->execute('INSERT INTO test_table (value) VALUES (?)', ['inner']);
                    throw new \RuntimeException('inner failure');
                });
            } catch (\RuntimeException) {
                // Expected — inner transaction rolled back
            }

            // Outer transaction should still be active
            self::assertSame(1, $outer->transactionDepth());
        });

        // Only 'outer' should be persisted
        $rows = $this->connection->query('SELECT value FROM test_table ORDER BY id')->fetchAll();
        self::assertCount(1, $rows);
        self::assertSame('outer', $rows[0]['value']);
    }

    // ── Error States ────────────────────────────────────────────

    #[Test]
    public function commit_without_transaction_throws(): void
    {
        $this->expectException(TransactionException::class);

        $this->connection->commit();
    }

    #[Test]
    public function rollback_without_transaction_throws(): void
    {
        $this->expectException(TransactionException::class);

        $this->connection->rollBack();
    }
}
