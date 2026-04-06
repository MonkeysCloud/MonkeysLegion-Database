<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Connection;

use MonkeysLegion\Database\Contracts\ConnectionInterface;
use MonkeysLegion\Database\Types\DatabaseDriver;
use MonkeysLegion\Database\Types\IsolationLevel;
use PDO;
use PDOStatement;

/**
 * MonkeysLegion Framework — Database Package
 *
 * Lazy-initialized connection using the proxy pattern.
 * Defers PDO construction until first actual use — a major performance win
 * for CLI commands, workers, and request handlers that may never touch the DB.
 *
 * Uses a factory closure to create the underlying connection on demand.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class LazyConnection implements ConnectionInterface
{
    private ?ConnectionInterface $inner = null;

    /**
     * @param \Closure(): ConnectionInterface $factory Creates the real connection on demand
     * @param string $name                            Connection name for identification
     * @param DatabaseDriver $driver                  Driver type (known at config time)
     */
    public function __construct(
        private readonly \Closure $factory,
        private readonly string $name,
        private readonly DatabaseDriver $driver,
    ) {}

    // ── Lifecycle ───────────────────────────────────────────────

    public function connect(): void
    {
        $this->resolve()->connect();
    }

    public function disconnect(): void
    {
        if ($this->inner !== null) {
            $this->inner->disconnect();
            $this->inner = null;
        }
    }

    public function reconnect(): void
    {
        $this->resolve()->reconnect();
    }

    public function isConnected(): bool
    {
        return $this->inner !== null && $this->inner->isConnected();
    }

    public function isAlive(): bool
    {
        return $this->inner !== null && $this->inner->isAlive();
    }

    public function pdo(): PDO
    {
        return $this->resolve()->pdo();
    }

    // ── Driver Info ─────────────────────────────────────────────

    public function getDriver(): DatabaseDriver
    {
        return $this->driver;
    }

    public function getName(): string
    {
        return $this->name;
    }

    // ── Transactions ────────────────────────────────────────────

    public function beginTransaction(?IsolationLevel $isolation = null): void
    {
        $this->resolve()->beginTransaction($isolation);
    }

    public function commit(): void
    {
        $this->resolve()->commit();
    }

    public function rollBack(): void
    {
        $this->resolve()->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->inner !== null && $this->inner->inTransaction();
    }

    public function transaction(callable $callback, ?IsolationLevel $isolation = null): mixed
    {
        return $this->resolve()->transaction($callback, $isolation);
    }

    // ── Raw Execution ───────────────────────────────────────────

    public function execute(string $sql, array $params = []): int
    {
        return $this->resolve()->execute($sql, $params);
    }

    public function query(string $sql, array $params = []): PDOStatement
    {
        return $this->resolve()->query($sql, $params);
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return $this->resolve()->lastInsertId($name);
    }

    // ── Internal ────────────────────────────────────────────────

    /**
     * Whether the underlying connection has been initialized.
     */
    public bool $initialized {
        get => $this->inner !== null;
    }

    /**
     * Proxy for Connection::$queryCount.
     * Returns 0 if the connection has not been initialized.
     */
    public int $queryCount {
        get => $this->inner instanceof Connection
            ? $this->inner->queryCount
            : 0;
    }

    /**
     * Proxy for Connection::$uptimeSeconds.
     * Returns 0.0 if the connection has not been initialized.
     */
    public float $uptimeSeconds {
        get => $this->inner instanceof Connection
            ? $this->inner->uptimeSeconds
            : 0.0;
    }

    /**
     * Resolve the underlying connection, creating it on first call.
     */
    private function resolve(): ConnectionInterface
    {
        return $this->inner ??= ($this->factory)();
    }
}
