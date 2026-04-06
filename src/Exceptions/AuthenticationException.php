<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Exceptions;

use MonkeysLegion\Database\Types\DatabaseDriver;

/**
 * Authentication failed (wrong username/password).
 */
class AuthenticationException extends ConnectionException
{
    /** Username that was rejected (never log the password). */
    public private(set) ?string $username;

    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        ?DatabaseDriver $driver = null,
        ?string $host = null,
        ?int $port = null,
        ?string $connectionName = null,
        ?string $username = null,
    ) {
        parent::__construct($message, $code, $previous, $driver, $host, $port, $connectionName);
        $this->username = $username;
    }

    /**
     * Create from a PDOException with authentication context.
     */
    public static function forUser(
        string $username,
        DatabaseDriver $driver,
        \PDOException $previous,
        ?string $host = null,
    ): self {
        return new self(
            message: "Authentication failed for user '{$username}' on {$driver->label()}"
                . ($host ? " at {$host}" : '')
                . ": {$previous->getMessage()}",
            previous: $previous,
            driver: $driver,
            host: $host,
            username: $username,
        );
    }
}
