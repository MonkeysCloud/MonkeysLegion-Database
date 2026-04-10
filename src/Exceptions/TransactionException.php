<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Exceptions;

use MonkeysLegion\Database\Types\DatabaseDriver;

/**
 * Transaction-related errors (not active, nesting violations, etc.).
 */
class TransactionException extends DatabaseException
{
    /** Current transaction nesting depth when the error occurred. */
    public private(set) int $nestingLevel;

    /** The operation that was attempted (begin, commit, rollback, savepoint). */
    public private(set) string $operation;

    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        ?DatabaseDriver $driver = null,
        int $nestingLevel = 0,
        string $operation = 'unknown',
    ) {
        parent::__construct($message, $code, $previous, $driver);
        $this->nestingLevel = $nestingLevel;
        $this->operation = $operation;
    }

    /**
     * Create for a "begin" that was called when already in a transaction.
     */
    public static function alreadyActive(DatabaseDriver $driver, int $nestingLevel = 1): self
    {
        return new self(
            message: "Cannot begin transaction: already at nesting level {$nestingLevel}",
            driver: $driver,
            nestingLevel: $nestingLevel,
            operation: 'begin',
        );
    }

    /**
     * Create for a "commit"/"rollback" that was called with no active transaction.
     */
    public static function notActive(string $operation, DatabaseDriver $driver): self
    {
        return new self(
            message: "Cannot {$operation}: no active transaction",
            driver: $driver,
            nestingLevel: 0,
            operation: $operation,
        );
    }
}
