<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Contracts;

use MonkeysLegion\Database\Types\DatabaseDriver;
use MonkeysLegion\Database\Types\IsolationLevel;
use PDO;
use PDOStatement;

/**
 * MonkeysLegion Framework — Database Package
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
interface ConnectionInterface
{
    // ── Connection Lifecycle ────────────────────────────────────

    /**
     * Establish the database connection.
     *
     * @throws \MonkeysLegion\Database\Exceptions\ConnectionException
     */
    public function connect(): void;

    /**
     * Close the database connection and release resources.
     */
    public function disconnect(): void;

    /**
     * Drop and re-establish the connection.
     *
     * @throws \MonkeysLegion\Database\Exceptions\ConnectionException
     */
    public function reconnect(): void;

    /**
     * Whether a PDO instance is currently held (connection was created).
     */
    public function isConnected(): bool;

    /**
     * Whether the connection is responsive (executes a health-check query).
     */
    public function isAlive(): bool;

    /**
     * Get the underlying PDO instance, connecting lazily if necessary.
     *
     * @throws \MonkeysLegion\Database\Exceptions\ConnectionException
     */
    public function pdo(): PDO;

    // ── Driver Info ─────────────────────────────────────────────

    /**
     * The database driver for this connection.
     */
    public function getDriver(): DatabaseDriver;

    /**
     * The connection name (e.g. "default", "replica-1").
     */
    public function getName(): string;

    // ── Observability ───────────────────────────────────────────

    /**
     * Total number of queries executed on this connection.
     */
    public int $queryCount { get; }

    /**
     * Seconds elapsed since the connection was established (0.0 if disconnected).
     */
    public float $uptimeSeconds { get; }

    // ── Transaction Support ─────────────────────────────────────

    /**
     * Start a new database transaction.
     *
     * @throws \MonkeysLegion\Database\Exceptions\TransactionException
     */
    public function beginTransaction(?IsolationLevel $isolation = null): void;

    /**
     * Commit the current transaction.
     *
     * @throws \MonkeysLegion\Database\Exceptions\TransactionException
     */
    public function commit(): void;

    /**
     * Roll back the current transaction.
     *
     * @throws \MonkeysLegion\Database\Exceptions\TransactionException
     */
    public function rollBack(): void;

    /**
     * Whether a transaction is currently active.
     */
    public function inTransaction(): bool;

    /**
     * Execute a callback within a transaction.
     *
     * Commits on success, rolls back on any throwable.
     *
     * @template T
     *
     * @param callable(self): T $callback
     *
     * @return T
     *
     * @throws \Throwable Re-throws after rollback
     */
    public function transaction(callable $callback, ?IsolationLevel $isolation = null): mixed;

    // ── Raw Execution ───────────────────────────────────────────

    /**
     * Execute a statement and return the number of affected rows.
     *
     * @param string              $sql    SQL with named or positional placeholders
     * @param array<string,mixed> $params Bound parameters
     *
     * @return int Affected row count
     *
     * @throws \MonkeysLegion\Database\Exceptions\QueryException
     */
    public function execute(string $sql, array $params = []): int;

    /**
     * Execute a query and return the PDOStatement for fetching.
     *
     * @param string              $sql    SQL with named or positional placeholders
     * @param array<string,mixed> $params Bound parameters
     *
     * @throws \MonkeysLegion\Database\Exceptions\QueryException
     */
    public function query(string $sql, array $params = []): PDOStatement;

    /**
     * Return the ID of the last inserted row or sequence value.
     *
     * @param string|null $name Name of the sequence object (required for PostgreSQL).
     *
     * @return string|false The ID string, or false if not supported / no insert occurred.
     */
    public function lastInsertId(?string $name = null): string|false;
}
