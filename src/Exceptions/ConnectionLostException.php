<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Exceptions;

use MonkeysLegion\Database\Types\DatabaseDriver;

/**
 * A previously working connection was lost (server gone, timeout, etc.).
 */
class ConnectionLostException extends ConnectionException
{
    /** Seconds the connection had been alive before being lost. */
    public private(set) float $uptimeBeforeLoss;

    /** Whether the connection was inside a transaction when lost. */
    public private(set) bool $wasInTransaction;

    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        ?DatabaseDriver $driver = null,
        ?string $host = null,
        ?int $port = null,
        ?string $connectionName = null,
        float $uptimeBeforeLoss = 0.0,
        bool $wasInTransaction = false,
    ) {
        parent::__construct($message, $code, $previous, $driver, $host, $port, $connectionName);
        $this->uptimeBeforeLoss = $uptimeBeforeLoss;
        $this->wasInTransaction = $wasInTransaction;
    }

    /**
     * Suggests whether the operation can safely be retried.
     *
     * Safe to retry only if we were NOT inside a transaction
     * (retrying mid-transaction could cause data inconsistency).
     */
    public bool $retryable {
        get => !$this->wasInTransaction;
    }
}
