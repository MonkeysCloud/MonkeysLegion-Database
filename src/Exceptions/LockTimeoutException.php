<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Exceptions;

use MonkeysLegion\Database\Types\DatabaseDriver;

/**
 * A lock wait timeout was exceeded.
 *
 * Unlike deadlocks, lock timeouts may or may not be retryable
 * depending on whether the blocking lock has been released.
 */
class LockTimeoutException extends QueryException
{
    /** Timeout value in seconds that was exceeded. */
    public private(set) ?float $timeoutSeconds;

    /** Identifier of the blocking transaction/process, if detectable. */
    public private(set) ?string $blockingProcessId;

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
        ?float $timeoutSeconds = null,
        ?string $blockingProcessId = null,
    ) {
        parent::__construct($message, $code, $previous, $driver, $sql, $params, $sqlState, $driverErrorCode);
        $this->timeoutSeconds = $timeoutSeconds;
        $this->blockingProcessId = $blockingProcessId;
    }

    /**
     * Lock timeouts MAY be retryable (unlike deadlocks which definitely are).
     * The caller should decide based on their domain logic.
     */
    public bool $retryable {
        get => true;
    }
}
