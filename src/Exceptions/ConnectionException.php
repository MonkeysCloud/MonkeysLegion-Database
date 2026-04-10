<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Exceptions;

use MonkeysLegion\Database\Types\DatabaseDriver;

/**
 * Errors related to establishing, losing, or authenticating connections.
 */
class ConnectionException extends DatabaseException
{
    public private(set) ?string $host;
    public private(set) ?int $port;
    public private(set) ?string $connectionName;

    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        ?DatabaseDriver $driver = null,
        ?string $host = null,
        ?int $port = null,
        ?string $connectionName = null,
    ) {
        parent::__construct($message, $code, $previous, $driver);
        $this->host = $host;
        $this->port = $port;
        $this->connectionName = $connectionName;
    }

    /**
     * Endpoint string for logging (e.g. "localhost:3306").
     */
    public string $endpoint {
        get {
            if ($this->host === null) {
                return 'unknown';
            }
            return $this->port !== null
                ? "{$this->host}:{$this->port}"
                : $this->host;
        }
    }
}
