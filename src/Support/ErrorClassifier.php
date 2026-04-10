<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Support;

use MonkeysLegion\Database\Exceptions\AuthenticationException;
use MonkeysLegion\Database\Exceptions\ColumnNotFoundException;
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
use MonkeysLegion\Database\Types\DatabaseDriver;

/**
 * MonkeysLegion Framework — Database Package
 *
 * Classifies raw PDOExceptions into specific typed exception subclasses
 * based on the driver, SQLSTATE, and driver error codes.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class ErrorClassifier
{
    /**
     * Classify a PDOException into a specific DatabaseException subclass.
     *
     * @param array<string, mixed> $params SQL params for context
     */
    public static function classify(
        \PDOException $e,
        DatabaseDriver $driver,
        ?string $sql = null,
        array $params = [],
    ): DatabaseException {
        $sqlState        = $e->errorInfo[0] ?? ($e->getCode() ?: null);
        $driverErrorCode = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : null;
        $message         = $e->getMessage();
        $messageLower    = strtolower($message);

        // ── Connection Errors ───────────────────────────────────

        if (self::isAuthError($driver, $sqlState, $driverErrorCode, $messageLower)) {
            return new AuthenticationException(
                message: "Authentication failed: {$message}",
                previous: $e,
                driver: $driver,
            );
        }

        if (self::isConnectionLost($driver, $sqlState, $driverErrorCode, $messageLower)) {
            return new ConnectionLostException(
                message: "Connection lost: {$message}",
                previous: $e,
                driver: $driver,
            );
        }

        if (self::isConnectionFailed($driver, $sqlState, $driverErrorCode, $messageLower)) {
            return new ConnectionFailedException(
                message: "Connection failed: {$message}",
                previous: $e,
                driver: $driver,
            );
        }

        // ── Query Errors (only if SQL context available) ────────

        if ($sql !== null) {
            if (self::isDuplicateKey($driver, $sqlState, $driverErrorCode)) {
                return new DuplicateKeyException(
                    message: "Duplicate key: {$message}",
                    previous: $e,
                    driver: $driver,
                    sql: $sql,
                    params: $params,
                    sqlState: is_string($sqlState) ? $sqlState : null,
                    driverErrorCode: $driverErrorCode,
                );
            }

            if (self::isForeignKeyViolation($driver, $sqlState, $driverErrorCode)) {
                return new ForeignKeyViolationException(
                    message: "Foreign key violation: {$message}",
                    previous: $e,
                    driver: $driver,
                    sql: $sql,
                    params: $params,
                    sqlState: is_string($sqlState) ? $sqlState : null,
                    driverErrorCode: $driverErrorCode,
                );
            }

            if (self::isDeadlock($driver, $sqlState, $driverErrorCode)) {
                return new DeadlockException(
                    message: "Deadlock detected: {$message}",
                    previous: $e,
                    driver: $driver,
                    sql: $sql,
                    params: $params,
                    sqlState: is_string($sqlState) ? $sqlState : null,
                    driverErrorCode: $driverErrorCode,
                );
            }

            if (self::isLockTimeout($driver, $sqlState, $driverErrorCode)) {
                return new LockTimeoutException(
                    message: "Lock timeout: {$message}",
                    previous: $e,
                    driver: $driver,
                    sql: $sql,
                    params: $params,
                    sqlState: is_string($sqlState) ? $sqlState : null,
                    driverErrorCode: $driverErrorCode,
                );
            }

            if (self::isTableNotFound($driver, $sqlState, $driverErrorCode)) {
                $tableName = self::extractTableName($message) ?? 'unknown';
                return new TableNotFoundException(
                    tableName: $tableName,
                    driver: $driver,
                    previous: $e,
                );
            }

            if (self::isColumnNotFound($driver, $sqlState, $driverErrorCode)) {
                $columnName = self::extractColumnName($message) ?? 'unknown';
                return new ColumnNotFoundException(
                    columnName: $columnName,
                    driver: $driver,
                    previous: $e,
                );
            }

            if (self::isSyntaxError($sqlState)) {
                return new SyntaxException(
                    message: "Syntax error: {$message}",
                    previous: $e,
                    driver: $driver,
                    sql: $sql,
                    params: $params,
                    sqlState: is_string($sqlState) ? $sqlState : null,
                    driverErrorCode: $driverErrorCode,
                );
            }

            // Generic query error
            return new QueryException(
                message: $message,
                previous: $e,
                driver: $driver,
                sql: $sql,
                params: $params,
                sqlState: is_string($sqlState) ? $sqlState : null,
                driverErrorCode: $driverErrorCode,
            );
        }

        // ── Fallback ────────────────────────────────────────────

        return new DatabaseException(
            message: $message,
            previous: $e,
            driver: $driver,
        );
    }

    // ── Detection Methods ───────────────────────────────────────

    private static function isAuthError(
        DatabaseDriver $driver,
        mixed $sqlState,
        ?int $code,
        string $message,
    ): bool {
        return match ($driver) {
            DatabaseDriver::MySQL      => $code === 1045 || $code === 1044,
            DatabaseDriver::PostgreSQL => $sqlState === '28P01' || $sqlState === '28000',
            DatabaseDriver::SQLite     => false,
        };
    }

    private static function isConnectionLost(
        DatabaseDriver $driver,
        mixed $sqlState,
        ?int $code,
        string $message,
    ): bool {
        return match ($driver) {
            DatabaseDriver::MySQL => $code === 2006 || $code === 2013
                || str_contains($message, 'server has gone away'),
            DatabaseDriver::PostgreSQL => $sqlState === '57P01'
                || str_contains($message, 'terminating connection'),
            DatabaseDriver::SQLite => false,
        };
    }

    private static function isConnectionFailed(
        DatabaseDriver $driver,
        mixed $sqlState,
        ?int $code,
        string $message,
    ): bool {
        return match ($driver) {
            DatabaseDriver::MySQL => $code === 2002 || $code === 2003,
            DatabaseDriver::PostgreSQL => $sqlState === '08001' || $sqlState === '08006',
            DatabaseDriver::SQLite => str_contains($message, 'unable to open database'),
        };
    }

    private static function isDuplicateKey(
        DatabaseDriver $driver,
        mixed $sqlState,
        ?int $code,
    ): bool {
        return match ($driver) {
            DatabaseDriver::MySQL      => $code === 1062,
            DatabaseDriver::PostgreSQL => $sqlState === '23505',
            DatabaseDriver::SQLite     => $code === 19
                && ($sqlState === '23000' || $sqlState === '19'),
        };
    }

    private static function isForeignKeyViolation(
        DatabaseDriver $driver,
        mixed $sqlState,
        ?int $code,
    ): bool {
        return match ($driver) {
            DatabaseDriver::MySQL      => $code === 1452 || $code === 1451,
            DatabaseDriver::PostgreSQL => $sqlState === '23503',
            DatabaseDriver::SQLite     => $code === 19 && $sqlState === '23000',
        };
    }

    private static function isDeadlock(
        DatabaseDriver $driver,
        mixed $sqlState,
        ?int $code,
    ): bool {
        return match ($driver) {
            DatabaseDriver::MySQL      => $code === 1213,
            DatabaseDriver::PostgreSQL => $sqlState === '40001' || $sqlState === '40P01',
            DatabaseDriver::SQLite     => false,
        };
    }

    private static function isLockTimeout(
        DatabaseDriver $driver,
        mixed $sqlState,
        ?int $code,
    ): bool {
        return match ($driver) {
            DatabaseDriver::MySQL      => $code === 1205,
            DatabaseDriver::PostgreSQL => $sqlState === '55P03',
            DatabaseDriver::SQLite     => $code === 5, // SQLITE_BUSY
        };
    }

    private static function isSyntaxError(mixed $sqlState): bool
    {
        return is_string($sqlState) && str_starts_with($sqlState, '42');
    }

    private static function isTableNotFound(
        DatabaseDriver $driver,
        mixed $sqlState,
        ?int $code,
    ): bool {
        return match ($driver) {
            DatabaseDriver::MySQL      => $code === 1146,
            DatabaseDriver::PostgreSQL => $sqlState === '42P01',
            DatabaseDriver::SQLite     => false, // SQLite doesn't have table-specific error codes
        };
    }

    private static function isColumnNotFound(
        DatabaseDriver $driver,
        mixed $sqlState,
        ?int $code,
    ): bool {
        return match ($driver) {
            DatabaseDriver::MySQL      => $code === 1054,
            DatabaseDriver::PostgreSQL => $sqlState === '42703',
            DatabaseDriver::SQLite     => false,
        };
    }

    // ── Extraction Helpers ──────────────────────────────────────

    /**
     * Attempt to extract a table name from an error message.
     */
    private static function extractTableName(string $message): ?string
    {
        // MySQL: "Table 'dbname.tablename' doesn't exist"
        if (preg_match("/Table '(?:[^.]+\.)?([^']+)'/i", $message, $m)) {
            return $m[1];
        }
        // PostgreSQL: 'relation "tablename" does not exist'
        if (preg_match('/relation "([^"]+)"/i', $message, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Attempt to extract a column name from an error message.
     */
    private static function extractColumnName(string $message): ?string
    {
        // MySQL: "Unknown column 'colname' in 'field list'"
        if (preg_match("/Unknown column '([^']+)'/i", $message, $m)) {
            return $m[1];
        }
        // PostgreSQL: 'column "colname" does not exist'
        if (preg_match('/column "([^"]+)"/i', $message, $m)) {
            return $m[1];
        }
        return null;
    }
}
