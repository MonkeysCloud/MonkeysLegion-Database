<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Exceptions;

use MonkeysLegion\Database\Types\DatabaseDriver;

/**
 * The initial connection attempt failed (host unreachable, wrong port, etc.).
 */
class ConnectionFailedException extends ConnectionException
{
    /** @var list<string> Log of hosts/endpoints that were attempted before failure */
    public private(set) array $attemptedHosts;

    /**
     * @param list<string> $attemptedHosts
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        ?DatabaseDriver $driver = null,
        ?string $host = null,
        ?int $port = null,
        ?string $connectionName = null,
        array $attemptedHosts = [],
    ) {
        parent::__construct($message, $code, $previous, $driver, $host, $port, $connectionName);
        $this->attemptedHosts = $attemptedHosts;
    }

    /**
     * Create from a PDOException during initial connect.
     */
    public static function fromPdoException(
        \PDOException $e,
        DatabaseDriver $driver,
        ?string $host = null,
        ?int $port = null,
        ?string $connectionName = null,
    ): self {
        return new self(
            message: "Failed to connect to {$driver->label()}" . ($host ? " at {$host}" : '') . ": {$e->getMessage()}",
            previous: $e,
            driver: $driver,
            host: $host,
            port: $port,
            connectionName: $connectionName,
            attemptedHosts: $host !== null ? [$host] : [],
        );
    }
}
