<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Exceptions;

use MonkeysLegion\Database\Types\DatabaseDriver;

/**
 * A unique or primary key constraint was violated.
 */
class DuplicateKeyException extends QueryException
{
    /** The constraint name that was violated (e.g. "users_email_unique"). */
    public private(set) ?string $constraintName;

    /** The column(s) that caused the duplicate (when detectable). */
    public private(set) ?string $duplicateColumn;

    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        ?DatabaseDriver $driver = null,
        string $sql = '',
        array $params = [],
        ?string $sqlState = null,
        ?int $driverErrorCode = null,
        ?string $constraintName = null,
        ?string $duplicateColumn = null,
    ) {
        parent::__construct($message, $code, $previous, $driver, $sql, $params, $sqlState, $driverErrorCode);
        $this->constraintName = $constraintName;
        $this->duplicateColumn = $duplicateColumn;
    }

    /**
     * Create from a constraint violation with context.
     *
     * @param array<string, mixed> $params
     */
    public static function forConstraint(
        string $constraintName,
        string $sql,
        array $params,
        DatabaseDriver $driver,
        \PDOException $previous,
        ?string $column = null,
    ): self {
        return new self(
            message: "Duplicate key violation on constraint '{$constraintName}'"
                . ($column ? " (column: {$column})" : '')
                . ": {$previous->getMessage()}",
            previous: $previous,
            driver: $driver,
            sql: $sql,
            params: $params,
            sqlState: $previous->errorInfo[0] ?? null,
            driverErrorCode: isset($previous->errorInfo[1]) ? (int) $previous->errorInfo[1] : null,
            constraintName: $constraintName,
            duplicateColumn: $column,
        );
    }
}
