<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Tests\Unit\Exceptions;

use MonkeysLegion\Database\Exceptions\AuthenticationException;
use MonkeysLegion\Database\Exceptions\ColumnNotFoundException;
use MonkeysLegion\Database\Exceptions\ConfigurationException;
use MonkeysLegion\Database\Exceptions\ConnectionException;
use MonkeysLegion\Database\Exceptions\ConnectionFailedException;
use MonkeysLegion\Database\Exceptions\ConnectionLostException;
use MonkeysLegion\Database\Exceptions\DatabaseException;
use MonkeysLegion\Database\Exceptions\DeadlockException;
use MonkeysLegion\Database\Exceptions\DuplicateKeyException;
use MonkeysLegion\Database\Exceptions\ForeignKeyViolationException;
use MonkeysLegion\Database\Exceptions\LockTimeoutException;
use MonkeysLegion\Database\Exceptions\PoolException;
use MonkeysLegion\Database\Exceptions\QueryException;
use MonkeysLegion\Database\Exceptions\SchemaException;
use MonkeysLegion\Database\Exceptions\SyntaxException;
use MonkeysLegion\Database\Exceptions\TableNotFoundException;
use MonkeysLegion\Database\Exceptions\TransactionException;
use MonkeysLegion\Database\Support\ConnectionPoolStats;
use MonkeysLegion\Database\Types\DatabaseDriver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExceptionHierarchyTest extends TestCase
{
    // ── Hierarchy ───────────────────────────────────────────────

    #[Test]
    public function databaseExceptionIsRuntimeException(): void
    {
        $e = new DatabaseException('test', driver: DatabaseDriver::MySQL);
        $this->assertInstanceOf(\RuntimeException::class, $e);
        $this->assertSame(DatabaseDriver::MySQL, $e->driver);
    }

    #[Test]
    public function connectionExceptionCarriesEndpoint(): void
    {
        $e = new ConnectionException(
            message: 'fail',
            host: '192.168.1.100',
            port: 5432,
            connectionName: 'primary',
        );

        $this->assertSame('192.168.1.100', $e->host);
        $this->assertSame(5432, $e->port);
        $this->assertSame('primary', $e->connectionName);
        $this->assertSame('192.168.1.100:5432', $e->endpoint);
        $this->assertInstanceOf(DatabaseException::class, $e);
    }

    #[Test]
    public function connectionExceptionEndpointWithoutPort(): void
    {
        $e = new ConnectionException(host: 'myhost');
        $this->assertSame('myhost', $e->endpoint);
    }

    #[Test]
    public function connectionExceptionEndpointUnknown(): void
    {
        $e = new ConnectionException();
        $this->assertSame('unknown', $e->endpoint);
    }

    // ── ConnectionFailedException ───────────────────────────────

    #[Test]
    public function connectionFailedCarriesAttemptedHosts(): void
    {
        $e = new ConnectionFailedException(
            message: 'fail',
            attemptedHosts: ['host1', 'host2'],
        );

        $this->assertSame(['host1', 'host2'], $e->attemptedHosts);
        $this->assertInstanceOf(ConnectionException::class, $e);
    }

    #[Test]
    public function connectionFailedFromPdoException(): void
    {
        $pdo = new \PDOException('Connection refused');
        $e = ConnectionFailedException::fromPdoException(
            $pdo,
            DatabaseDriver::MySQL,
            host: 'dbhost',
            port: 3306,
        );

        $this->assertStringContainsString('MySQL', $e->getMessage());
        $this->assertStringContainsString('dbhost', $e->getMessage());
        $this->assertSame($pdo, $e->getPrevious());
        $this->assertSame(['dbhost'], $e->attemptedHosts);
    }

    // ── ConnectionLostException ─────────────────────────────────

    #[Test]
    public function connectionLostCarriesUptimeAndTransaction(): void
    {
        $e = new ConnectionLostException(
            message: 'gone',
            uptimeBeforeLoss: 123.5,
            wasInTransaction: true,
        );

        $this->assertSame(123.5, $e->uptimeBeforeLoss);
        $this->assertTrue($e->wasInTransaction);
        $this->assertFalse($e->retryable); // was in transaction → not retryable
    }

    #[Test]
    public function connectionLostRetryableWhenNoTransaction(): void
    {
        $e = new ConnectionLostException(
            message: 'gone',
            wasInTransaction: false,
        );

        $this->assertTrue($e->retryable);
    }

    // ── AuthenticationException ─────────────────────────────────

    #[Test]
    public function authenticationForUser(): void
    {
        $inner = new \PDOException('Access denied');
        $e = AuthenticationException::forUser(
            'appuser',
            DatabaseDriver::MySQL,
            $inner,
            'dbhost',
        );

        $this->assertStringContainsString('appuser', $e->getMessage());
        $this->assertSame('appuser', $e->username);
        $this->assertSame('dbhost', $e->host);
    }

    // ── QueryException ──────────────────────────────────────────

    #[Test]
    public function queryExceptionCarriesSqlContext(): void
    {
        $e = new QueryException(
            message: 'error',
            sql: 'SELECT * FROM users WHERE id = :id',
            params: [':id' => 42],
            sqlState: '42000',
            driverErrorCode: 1064,
            driver: DatabaseDriver::MySQL,
        );

        $this->assertSame('SELECT * FROM users WHERE id = :id', $e->sql);
        $this->assertSame([':id' => 42], $e->params);
        $this->assertSame('42000', $e->sqlState);
        $this->assertSame(1064, $e->driverErrorCode);
    }

    #[Test]
    public function queryExceptionDebugSqlInterpolatesParams(): void
    {
        $e = new QueryException(
            message: 'error',
            sql: 'SELECT * FROM users WHERE name = :name AND age = :age',
            params: [':name' => "O'Brien", ':age' => 30],
        );

        $debug = $e->debugSql;
        $this->assertStringContainsString("'O\\'Brien'", $debug);
        $this->assertStringContainsString('30', $debug);
        $this->assertStringNotContainsString(':name', $debug);
        $this->assertStringNotContainsString(':age', $debug);
    }

    // ── DuplicateKeyException ───────────────────────────────────

    #[Test]
    public function duplicateKeyForConstraint(): void
    {
        $inner = new \PDOException('Duplicate entry');
        $inner->errorInfo = ['23000', 1062, 'Duplicate'];

        $e = DuplicateKeyException::forConstraint(
            'users_email_unique',
            'INSERT INTO users (email) VALUES (:email)',
            [':email' => 'test@test.com'],
            DatabaseDriver::MySQL,
            $inner,
            'email',
        );

        $this->assertSame('users_email_unique', $e->constraintName);
        $this->assertSame('email', $e->duplicateColumn);
        $this->assertStringContainsString('users_email_unique', $e->getMessage());
        $this->assertInstanceOf(QueryException::class, $e);
    }

    // ── ForeignKeyViolationException ────────────────────────────

    #[Test]
    public function fkViolationCarriesRefInfo(): void
    {
        $e = new ForeignKeyViolationException(
            message: 'a foreign key constraint fails',
            constraintName: 'orders_user_id_fk',
            referencingColumn: 'user_id',
            referencedTable: 'users',
        );

        $this->assertSame('orders_user_id_fk', $e->constraintName);
        $this->assertSame('user_id', $e->referencingColumn);
        $this->assertSame('users', $e->referencedTable);
        $this->assertTrue($e->isParentMissing);
    }

    // ── DeadlockException ───────────────────────────────────────

    #[Test]
    public function deadlockIsRetryable(): void
    {
        $e = new DeadlockException(
            message: 'deadlock',
            retryAttempt: 1,
            maxRetries: 3,
        );

        $this->assertTrue($e->retryable);
        $this->assertTrue($e->canRetry);
        $this->assertSame(1, $e->retryAttempt);
        $this->assertSame(3, $e->maxRetries);
    }

    #[Test]
    public function deadlockCannotRetryWhenExhausted(): void
    {
        $e = new DeadlockException(
            message: 'deadlock',
            retryAttempt: 3,
            maxRetries: 3,
        );

        $this->assertTrue($e->retryable); // inherently retryable...
        $this->assertFalse($e->canRetry); // ...but attempts exhausted
    }

    // ── LockTimeoutException ────────────────────────────────────

    #[Test]
    public function lockTimeoutCarriesTimeoutInfo(): void
    {
        $e = new LockTimeoutException(
            message: 'timeout',
            timeoutSeconds: 30.5,
            blockingProcessId: '12345',
        );

        $this->assertSame(30.5, $e->timeoutSeconds);
        $this->assertSame('12345', $e->blockingProcessId);
        $this->assertTrue($e->retryable);
    }

    // ── SyntaxException ─────────────────────────────────────────

    #[Test]
    public function syntaxExceptionIsNeverRetryable(): void
    {
        $e = new SyntaxException(
            message: 'syntax error at position 42',
            errorPosition: 42,
            nearToken: 'FORM',
        );

        $this->assertFalse($e->retryable);
        $this->assertSame(42, $e->errorPosition);
        $this->assertSame('FORM', $e->nearToken);
    }

    // ── SchemaException + subclasses ────────────────────────────

    #[Test]
    public function tableNotFoundCarriesContext(): void
    {
        $e = new TableNotFoundException('orders', DatabaseDriver::MySQL, 'myapp');

        $this->assertSame('orders', $e->tableName);
        $this->assertSame('myapp', $e->schema);
        $this->assertSame('myapp.orders', $e->qualifiedName);
        $this->assertStringContainsString('orders', $e->getMessage());
        $this->assertInstanceOf(SchemaException::class, $e);
    }

    #[Test]
    public function tableNotFoundQualifiedNameWithoutSchema(): void
    {
        $e = new TableNotFoundException('orders');
        $this->assertSame('orders', $e->qualifiedName);
    }

    #[Test]
    public function columnNotFoundCarriesContext(): void
    {
        $e = new ColumnNotFoundException('email', 'users', DatabaseDriver::PostgreSQL);

        $this->assertSame('email', $e->columnName);
        $this->assertSame('users', $e->tableName);
        $this->assertSame('users.email', $e->qualifiedName);
        $this->assertStringContainsString('email', $e->getMessage());
        $this->assertStringContainsString('users', $e->getMessage());
    }

    // ── TransactionException ────────────────────────────────────

    #[Test]
    public function transactionAlreadyActive(): void
    {
        $e = TransactionException::alreadyActive(DatabaseDriver::MySQL, 2);

        $this->assertSame(2, $e->nestingLevel);
        $this->assertSame('begin', $e->operation);
        $this->assertStringContainsString('nesting level 2', $e->getMessage());
    }

    #[Test]
    public function transactionNotActive(): void
    {
        $e = TransactionException::notActive('commit', DatabaseDriver::PostgreSQL);

        $this->assertSame(0, $e->nestingLevel);
        $this->assertSame('commit', $e->operation);
        $this->assertStringContainsString('Cannot commit', $e->getMessage());
    }

    // ── ConfigurationException ──────────────────────────────────

    #[Test]
    public function configurationMissingKey(): void
    {
        $e = ConfigurationException::missingKey('driver', 'primary');

        $this->assertSame('primary', $e->connectionName);
        $this->assertSame('driver', $e->configKey);
        $this->assertStringContainsString('driver', $e->getMessage());
        $this->assertStringContainsString('primary', $e->getMessage());
    }

    #[Test]
    public function configurationMissingExtension(): void
    {
        $e = ConfigurationException::missingExtension(DatabaseDriver::MySQL);

        $this->assertSame(DatabaseDriver::MySQL, $e->driver);
        $this->assertStringContainsString('pdo_mysql', $e->getMessage());
    }

    // ── PoolException ───────────────────────────────────────────

    #[Test]
    public function poolExhausted(): void
    {
        $stats = new ConnectionPoolStats(idle: 0, active: 10, total: 15, maxSize: 10);
        $e = PoolException::exhausted($stats, DatabaseDriver::MySQL, 'primary');

        $this->assertSame($stats, $e->poolStats);
        $this->assertSame('primary', $e->connectionName);
        $this->assertStringContainsString('exhausted', $e->getMessage());
        $this->assertStringContainsString('10 active', $e->getMessage());
    }

    #[Test]
    public function poolHealthCheckFailed(): void
    {
        $e = PoolException::healthCheckFailed('Connection refused', DatabaseDriver::PostgreSQL);

        $this->assertStringContainsString('Connection refused', $e->getMessage());
        $this->assertSame(DatabaseDriver::PostgreSQL, $e->driver);
    }
}
