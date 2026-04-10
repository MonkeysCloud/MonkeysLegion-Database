<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Connection;

use MonkeysLegion\Database\Config\DatabaseConfig;
use MonkeysLegion\Database\Contracts\ConnectionEventDispatcherInterface;
use MonkeysLegion\Database\Contracts\ConnectionInterface;
use MonkeysLegion\Database\Exceptions\ConfigurationException;
use MonkeysLegion\Database\Exceptions\TransactionException;
use MonkeysLegion\Database\Support\ErrorClassifier;
use MonkeysLegion\Database\Types\DatabaseDriver;
use MonkeysLegion\Database\Types\IsolationLevel;
use PDO;
use PDOStatement;
use Psr\Log\LoggerInterface;

/**
 * MonkeysLegion Framework — Database Package
 *
 * Unified connection class handling all drivers (MySQL, PostgreSQL, SQLite).
 * Replaces the 4-class hierarchy (Abstract + 3 drivers) with a single class
 * that uses match() for driver-specific behaviour.
 *
 * PHP 8.4 features used:
 *  • Property hooks (`get`) for computed state (`queryCount`, `uptimeSeconds`)
 *  • Asymmetric visibility (`public private(set)`) for config access
 *  • `public` properties for optional injectable collaborators (`eventDispatcher`, `logger`)
 *    – consumers assign them directly; no opaque setter methods needed
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class Connection implements ConnectionInterface
{
    private ?PDO $pdo = null;
    private int $_queryCount = 0;
    private float $connectedAt = 0.0;

    // ── PHP 8.4 — injectable collaborators as plain public properties ──────
    // Readable by anyone; writable by anyone (intended for DI / test helpers).
    // No setter method needed.

    /** Optional event dispatcher injected after construction. */
    public ?ConnectionEventDispatcherInterface $eventDispatcher = null;

    /** Optional PSR-3 logger injected after construction. */
    public ?LoggerInterface $logger = null;

    public function __construct(
        public private(set) readonly DatabaseConfig $connectionConfig,
    ) {}

    // ── PHP 8.4 Property Hooks ──────────────────────────────────

    /**
     * Number of queries executed on this connection.
     */
    public int $queryCount {
        get => $this->_queryCount;
    }

    /**
     * Seconds since this connection was established.
     */
    public float $uptimeSeconds {
        get => $this->connectedAt > 0.0
            ? microtime(true) - $this->connectedAt
            : 0.0;
    }

    // ── Connection Lifecycle ────────────────────────────────────

    public function connect(): void
    {
        if ($this->pdo !== null) {
            return;
        }

        try {
            $this->pdo = new PDO(
                $this->connectionConfig->dsn->dsn(),
                $this->connectionConfig->username,
                $this->connectionConfig->password,
                $this->connectionConfig->effectivePdoOptions(),
            );

            $this->applyDriverDefaults($this->pdo);
            $this->connectedAt = microtime(true);
            $this->_queryCount = 0;

            $this->logger?->debug('Database connection established', [
                'connection' => $this->connectionConfig->name,
                'driver'     => $this->connectionConfig->driver->value,
            ]);
            $this->eventDispatcher?->onConnected($this);
        } catch (\PDOException $e) {
            $classified = ErrorClassifier::classify($e, $this->connectionConfig->driver);
            $this->logger?->error('Database connection failed', [
                'connection' => $this->connectionConfig->name,
                'driver'     => $this->connectionConfig->driver->value,
                'error'      => $e->getMessage(),
            ]);
            throw $classified;
        }
    }

    public function disconnect(): void
    {
        if ($this->pdo === null) {
            return;
        }

        // Force-rollback any active transaction
        if ($this->pdo->inTransaction()) {
            try {
                $this->pdo->rollBack();
            } catch (\PDOException) {
                // Ignore rollback failures on disconnect
            }
        }

        $this->eventDispatcher?->onDisconnected($this);
        $this->logger?->debug('Database connection closed', [
            'connection' => $this->connectionConfig->name,
        ]);

        $this->pdo = null;
        $this->connectedAt = 0.0;
    }

    public function reconnect(): void
    {
        $this->disconnect();
        $this->connect();
    }

    public function isConnected(): bool
    {
        return $this->pdo !== null;
    }

    public function isAlive(): bool
    {
        if ($this->pdo === null) {
            return false;
        }

        try {
            $sql = $this->connectionConfig->driver->healthCheckSql();
            $result = $this->pdo->query($sql);
            return $result !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $this->connect();
        }

        return $this->pdo;
    }

    // ── Driver Info ─────────────────────────────────────────────

    public function getDriver(): DatabaseDriver
    {
        return $this->connectionConfig->driver;
    }

    public function getName(): string
    {
        return $this->connectionConfig->name;
    }

    // ── Transactions ────────────────────────────────────────────

    public function beginTransaction(?IsolationLevel $isolation = null): void
    {
        $pdo = $this->pdo();

        if ($pdo->inTransaction()) {
            throw TransactionException::alreadyActive($this->connectionConfig->driver);
        }

        if ($isolation !== null) {
            $this->setIsolationLevel($pdo, $isolation);
        }

        $pdo->beginTransaction();
    }

    public function commit(): void
    {
        $pdo = $this->pdo();

        if (!$pdo->inTransaction()) {
            throw TransactionException::notActive('commit', $this->connectionConfig->driver);
        }

        $pdo->commit();
    }

    public function rollBack(): void
    {
        $pdo = $this->pdo();

        if (!$pdo->inTransaction()) {
            throw TransactionException::notActive('rollBack', $this->connectionConfig->driver);
        }

        $pdo->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->pdo?->inTransaction() ?? false;
    }

    public function transaction(callable $callback, ?IsolationLevel $isolation = null): mixed
    {
        $this->beginTransaction($isolation);

        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            try {
                $this->rollBack();
            } catch (\Throwable) {
                // Swallow rollback failure — the original exception is more important
            }
            throw $e;
        }
    }

    // ── Raw Execution ───────────────────────────────────────────

    public function execute(string $sql, array $params = []): int
    {
        $start = hrtime(true);

        try {
            $stmt = $this->pdo()->prepare($sql);
            $stmt->execute($params);
            $this->_queryCount++;

            $durationMs = (hrtime(true) - $start) / 1_000_000;
            $this->logger?->debug('Query executed', [
                'connection'  => $this->connectionConfig->name,
                'sql'         => $sql,
                'duration_ms' => round($durationMs, 3),
            ]);
            $this->eventDispatcher?->onQueryExecuted($this, $sql, $params, $durationMs);

            return $stmt->rowCount();
        } catch (\PDOException $e) {
            $this->logger?->error('Query failed', [
                'connection' => $this->connectionConfig->name,
                'sql'        => $sql,
                'error'      => $e->getMessage(),
            ]);
            $this->eventDispatcher?->onError($this, $e);
            throw ErrorClassifier::classify($e, $this->connectionConfig->driver, $sql, $params);
        }
    }

    public function query(string $sql, array $params = []): PDOStatement
    {
        $start = hrtime(true);

        try {
            $stmt = $this->pdo()->prepare($sql);
            $stmt->execute($params);
            $this->_queryCount++;

            $durationMs = (hrtime(true) - $start) / 1_000_000;
            $this->logger?->debug('Query executed', [
                'connection'  => $this->connectionConfig->name,
                'sql'         => $sql,
                'duration_ms' => round($durationMs, 3),
            ]);
            $this->eventDispatcher?->onQueryExecuted($this, $sql, $params, $durationMs);

            return $stmt;
        } catch (\PDOException $e) {
            $this->logger?->error('Query failed', [
                'connection' => $this->connectionConfig->name,
                'sql'        => $sql,
                'error'      => $e->getMessage(),
            ]);
            $this->eventDispatcher?->onError($this, $e);
            throw ErrorClassifier::classify($e, $this->connectionConfig->driver, $sql, $params);
        }
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return $this->pdo()->lastInsertId($name);
    }

    // ── Private: Driver-Specific Setup ──────────────────────────

    private function applyDriverDefaults(PDO $pdo): void
    {
        match ($this->connectionConfig->driver) {
            DatabaseDriver::MySQL      => $this->applyMySqlDefaults($pdo),
            DatabaseDriver::PostgreSQL => $this->applyPostgreSqlDefaults($pdo),
            DatabaseDriver::SQLite     => $this->applySqliteDefaults($pdo),
        };
    }

    private function applyMySqlDefaults(PDO $pdo): void
    {
        $charset = self::validateCharset($this->connectionConfig->dsn->charset ?? 'utf8mb4');
        $pdo->exec("SET NAMES '{$charset}'");
        $pdo->exec("SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

        if ($this->connectionConfig->timezone !== 'UTC') {
            $tz = self::validateTimezone($this->connectionConfig->timezone);
            $pdo->exec("SET time_zone = '{$tz}'");
        }
    }

    private function applyPostgreSqlDefaults(PDO $pdo): void
    {
        $pdo->exec("SET NAMES 'UTF8'");
        $tz = self::validateTimezone($this->connectionConfig->timezone);
        $pdo->exec("SET timezone = '{$tz}'");
    }

    private function applySqliteDefaults(PDO $pdo): void
    {
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
    }

    private function setIsolationLevel(PDO $pdo, IsolationLevel $level): void
    {
        match ($this->connectionConfig->driver) {
            DatabaseDriver::MySQL => $pdo->exec(
                "SET SESSION TRANSACTION ISOLATION LEVEL {$level->value}"
            ),
            DatabaseDriver::PostgreSQL => $pdo->exec(
                $pdo->inTransaction()
                    ? "SET TRANSACTION ISOLATION LEVEL {$level->value}"
                    : "SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL {$level->value}"
            ),
            DatabaseDriver::SQLite => null, // SQLite has limited isolation support
        };
    }

    // ── Private: Input Validation ────────────────────────────────

    /**
     * Validate a charset string before interpolating into SQL.
     * Only alphanumeric characters and underscores are allowed.
     *
     * @throws ConfigurationException On invalid charset value.
     */
    private static function validateCharset(string $charset): string
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $charset)) {
            throw new ConfigurationException(
                "Invalid charset value '{$charset}': only alphanumeric characters and underscores are allowed.",
            );
        }

        return $charset;
    }

    /**
     * Validate a timezone string before interpolating into SQL.
     * Allows letters, digits, forward slash, space, dash, underscore, plus, colon, and dot.
     *
     * @throws ConfigurationException On invalid timezone value.
     */
    private static function validateTimezone(string $timezone): string
    {
        if (!preg_match('/^[A-Za-z0-9\/ _+:.-]+$/', $timezone)) {
            throw new ConfigurationException(
                "Invalid timezone value '{$timezone}': contains disallowed characters.",
            );
        }

        return $timezone;
    }
}

