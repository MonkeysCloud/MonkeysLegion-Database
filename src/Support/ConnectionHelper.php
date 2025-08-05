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
     * @param array<string> $fallbackErrorPatterns
     * @return PDO
     * @throws PDOException
     */
    public static function createWithHostFallback(
        string $dsn,
        string $username = '',
        string $password = '',
        array $options = [],
        array $fallbackErrorPatterns = ['getaddrinfo', 'Connection refused']
    ): PDO {
        try {
            // First attempt: as-configured
            return new PDO($dsn, $username, $password, $options);
        } catch (PDOException $e) {
            // If the host isn't resolvable, fall back to localhost
            $shouldFallback = false;
            foreach ($fallbackErrorPatterns as $pattern) {
                // If pattern starts and ends with '/', treat as regex
                if (strlen($pattern) > 2 && $pattern[0] === '/' && substr($pattern, -1) === '/') {
                    if (preg_match($pattern, $e->getMessage())) {
                        $shouldFallback = true;
                        break;
                    }
                } else {
                    if (str_contains($e->getMessage(), $pattern)) {
                        $shouldFallback = true;
                        break;
                    }
                }
            }
            if ($shouldFallback) {
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
