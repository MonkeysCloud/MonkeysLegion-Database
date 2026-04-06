<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Tests\Unit\Support;

use MonkeysLegion\Database\Exceptions\AuthenticationException;
use MonkeysLegion\Database\Exceptions\ConnectionFailedException;
use MonkeysLegion\Database\Exceptions\ConnectionLostException;
use MonkeysLegion\Database\Exceptions\DatabaseException;
use MonkeysLegion\Database\Exceptions\DeadlockException;
use MonkeysLegion\Database\Exceptions\DuplicateKeyException;
use MonkeysLegion\Database\Exceptions\ForeignKeyViolationException;
use MonkeysLegion\Database\Exceptions\LockTimeoutException;
use MonkeysLegion\Database\Exceptions\QueryException;
use MonkeysLegion\Database\Exceptions\SyntaxException;
use MonkeysLegion\Database\Exceptions\TableNotFoundException;
use MonkeysLegion\Database\Support\ErrorClassifier;
use MonkeysLegion\Database\Types\DatabaseDriver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ErrorClassifier::class)]
final class ErrorClassifierTest extends TestCase
{
    // ── MySQL Classification ────────────────────────────────────

    #[Test]
    public function mysqlDuplicateKey1062(): void
    {
        $pdo = $this->makePdoException('Duplicate entry', '23000', 1062);
        $e = ErrorClassifier::classify($pdo, DatabaseDriver::MySQL, 'INSERT INTO t', []);

        $this->assertInstanceOf(DuplicateKeyException::class, $e);
        $this->assertSame('INSERT INTO t', $e->sql);
    }

    #[Test]
    public function mysqlForeignKey1452(): void
    {
        $pdo = $this->makePdoException('FK error', '23000', 1452);
        $e = ErrorClassifier::classify($pdo, DatabaseDriver::MySQL, 'INSERT INTO t', []);

        $this->assertInstanceOf(ForeignKeyViolationException::class, $e);
    }

    #[Test]
    public function mysqlDeadlock1213(): void
    {
        $pdo = $this->makePdoException('Deadlock found', '40001', 1213);
        $e = ErrorClassifier::classify($pdo, DatabaseDriver::MySQL, 'UPDATE t SET x=1', []);

        $this->assertInstanceOf(DeadlockException::class, $e);
    }

    #[Test]
    public function mysqlLockTimeout1205(): void
    {
        $pdo = $this->makePdoException('Lock wait timeout', '40001', 1205);
        $e = ErrorClassifier::classify($pdo, DatabaseDriver::MySQL, 'UPDATE t SET x=1', []);

        $this->assertInstanceOf(LockTimeoutException::class, $e);
    }

    #[Test]
    public function mysqlAuth1045(): void
    {
        $pdo = $this->makePdoException('Access denied', '28000', 1045);
        $e = ErrorClassifier::classify($pdo, DatabaseDriver::MySQL);

        $this->assertInstanceOf(AuthenticationException::class, $e);
    }

    #[Test]
    public function mysqlConnectionFailed2002(): void
    {
        $pdo = $this->makePdoException('Connection refused', '08001', 2002);
        $e = ErrorClassifier::classify($pdo, DatabaseDriver::MySQL);

        $this->assertInstanceOf(ConnectionFailedException::class, $e);
    }

    #[Test]
    public function mysqlServerGoneAway2006(): void
    {
        $pdo = $this->makePdoException('server has gone away', '08S01', 2006);
        $e = ErrorClassifier::classify($pdo, DatabaseDriver::MySQL);

        $this->assertInstanceOf(ConnectionLostException::class, $e);
    }

    #[Test]
    public function mysqlTableNotFound1146(): void
    {
        $pdo = $this->makePdoException('Table not found', '42S02', 1146);
        $e = ErrorClassifier::classify($pdo, DatabaseDriver::MySQL, 'SELECT * FROM missing');

        $this->assertInstanceOf(TableNotFoundException::class, $e);
    }

    // ── PostgreSQL Classification ───────────────────────────────

    #[Test]
    public function pgsqlDuplicateKey23505(): void
    {
        $pdo = $this->makePdoException('unique violation', '23505', 0);
        $e = ErrorClassifier::classify($pdo, DatabaseDriver::PostgreSQL, 'INSERT INTO t', []);

        $this->assertInstanceOf(DuplicateKeyException::class, $e);
    }

    #[Test]
    public function pgsqlForeignKey23503(): void
    {
        $pdo = $this->makePdoException('fk violation', '23503', 0);
        $e = ErrorClassifier::classify($pdo, DatabaseDriver::PostgreSQL, 'INSERT INTO t', []);

        $this->assertInstanceOf(ForeignKeyViolationException::class, $e);
    }

    #[Test]
    public function pgsqlDeadlock40P01(): void
    {
        $pdo = $this->makePdoException('deadlock detected', '40P01', 0);
        $e = ErrorClassifier::classify($pdo, DatabaseDriver::PostgreSQL, 'UPDATE t', []);

        $this->assertInstanceOf(DeadlockException::class, $e);
    }

    #[Test]
    public function pgsqlAuth28P01(): void
    {
        $pdo = $this->makePdoException('password authentication failed', '28P01', 0);
        $e = ErrorClassifier::classify($pdo, DatabaseDriver::PostgreSQL);

        $this->assertInstanceOf(AuthenticationException::class, $e);
    }

    // ── Syntax Errors (SQLSTATE 42xxx) ──────────────────────────

    #[Test]
    public function syntaxError42000(): void
    {
        $pdo = $this->makePdoException('near "FORM": syntax error', '42000', 1064);
        $e = ErrorClassifier::classify($pdo, DatabaseDriver::MySQL, 'FORM users', []);

        $this->assertInstanceOf(SyntaxException::class, $e);
    }

    // ── Fallback ────────────────────────────────────────────────

    #[Test]
    public function unknownErrorFallsToQueryException(): void
    {
        $pdo = $this->makePdoException('Unknown error', '99999', 99999);
        $e = ErrorClassifier::classify($pdo, DatabaseDriver::MySQL, 'SELECT 1', []);

        $this->assertInstanceOf(QueryException::class, $e);
        $this->assertNotInstanceOf(DuplicateKeyException::class, $e);
    }

    #[Test]
    public function unknownErrorWithoutSqlFallsToDatabaseException(): void
    {
        $pdo = $this->makePdoException('Unknown error', '99999', 99999);
        $e = ErrorClassifier::classify($pdo, DatabaseDriver::MySQL);

        $this->assertInstanceOf(DatabaseException::class, $e);
        $this->assertNotInstanceOf(QueryException::class, $e);
    }

    // ── SQLite ──────────────────────────────────────────────────

    #[Test]
    public function sqliteBusyIsLockTimeout(): void
    {
        $pdo = $this->makePdoException('database is locked', 'HY000', 5);
        $e = ErrorClassifier::classify($pdo, DatabaseDriver::SQLite, 'INSERT INTO t', []);

        $this->assertInstanceOf(LockTimeoutException::class, $e);
    }

    // ── Helpers ─────────────────────────────────────────────────

    private function makePdoException(string $msg, string $sqlState, int $code): \PDOException
    {
        $e = new \PDOException($msg);
        $e->errorInfo = [$sqlState, $code, $msg];
        return $e;
    }
}
