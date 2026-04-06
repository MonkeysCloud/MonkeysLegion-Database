<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Exceptions;

use MonkeysLegion\Database\Types\DatabaseDriver;

/**
 * SQL syntax error detected by the database server.
 */
class SyntaxException extends QueryException
{
    /** Character position in the SQL where the error was detected (if available). */
    public private(set) ?int $errorPosition;

    /** The specific keyword or token near the error (when parseable). */
    public private(set) ?string $nearToken;

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
        ?int $errorPosition = null,
        ?string $nearToken = null,
    ) {
        parent::__construct($message, $code, $previous, $driver, $sql, $params, $sqlState, $driverErrorCode);
        $this->errorPosition = $errorPosition;
        $this->nearToken = $nearToken;
    }

    /**
     * Syntax errors are NEVER retryable — the SQL must be fixed.
     */
    public bool $retryable {
        get => false;
    }
}
