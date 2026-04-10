<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Exceptions;

use MonkeysLegion\Database\Types\DatabaseDriver;

/**
 * A deadlock was detected and the transaction was aborted.
 *
 * Deadlocks are always retryable — the database has already rolled back
 * the victim transaction, so you can safely retry the entire operation.
 */
class DeadlockException extends QueryException
{
    /** Number of times this operation has been retried so far. */
    public private(set) int $retryAttempt;

    /** Maximum number of retries allowed for deadlock recovery. */
    public private(set) int $maxRetries;

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
        int $retryAttempt = 0,
        int $maxRetries = 3,
    ) {
        parent::__construct($message, $code, $previous, $driver, $sql, $params, $sqlState, $driverErrorCode);
        $this->retryAttempt = $retryAttempt;
        $this->maxRetries = $maxRetries;
    }

    /**
     * Whether retry attempts are still available.
     */
    public bool $canRetry {
        get => $this->retryAttempt < $this->maxRetries;
    }

    /**
     * Deadlocks are inherently retryable (the DB already rolled back).
     */
    public bool $retryable {
        get => true;
    }
}
