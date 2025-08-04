<?php

declare(strict_types=1);

namespace MonkeysLegion\Database\Support;

use PDO;
use PDOException;

class ConnectionHelper
{
    /**
     * Attempt to create PDO connection with host fallback to localhost.
     *
     * @param string $dsn
     * @param string $username
     * @param string $password
     * @param array<int, mixed> $options
     * @return PDO
     * @throws PDOException
     */
    public static function createWithHostFallback(
        string $dsn,
        string $username = '',
        string $password = '',
        array $options = []
    ): PDO {
        try {
            // First attempt: as-configured
            return new PDO($dsn, $username, $password, $options);
        } catch (PDOException $e) {
            // If the host isn't resolvable, fall back to localhost
            if (str_contains($e->getMessage(), 'getaddrinfo') || str_contains($e->getMessage(), 'Connection refused')) {
                $fallbackDsn = preg_replace(
                    '/host=[^;]+/',
                    'host=localhost',
                    $dsn
                );

                if ($fallbackDsn === null) {
                    throw new \InvalidArgumentException('Invalid DSN format for fallback.');
                }

                return new PDO($fallbackDsn, $username, $password, $options);
            }

            // Propagate other connection errors
            throw $e;
        }
    }
}
