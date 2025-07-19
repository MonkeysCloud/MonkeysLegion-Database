<?php

declare(strict_types=1);

namespace MonkeysLegion\Database\MySQL;

use PDO;
use PDOException;

final class Connection
{
    /**
     * @var PDO
     */
    private PDO $pdo;

    /**
     * @param array $config
     * @throws PDOException
     */
    public function __construct(array $config)
    {
        $c   = $config['connections']['mysql'];
        print_r($c);
        $dsn = $c['dsn'];

        try {
            // First attempt: as-configured (e.g. "db" inside Docker)
            $this->pdo = new PDO(
                $dsn,
                $c['username'],
                $c['password'],
                $c['options']
            );
        } catch (PDOException $e) {
            // If the host isn’t resolvable (e.g. running CLI on host), fall back to 127.0.0.1
            if (str_contains($e->getMessage(), 'getaddrinfo')) {
                $dsn = preg_replace(
                    '/host=[^;]+/',
                    'host=localhost',
                    $dsn
                );

                $this->pdo = new PDO(
                    $dsn,
                    $c['username'],
                    $c['password'],
                    $c['options']
                );
            } else {
                // propagate other connection errors
                throw $e;
            }
        }

        // Enforce strict SQL and modern defaults
        $this->pdo->exec("SET NAMES utf8mb4, sql_mode='STRICT_TRANS_TABLES'");
    }

    /**
     * @return PDO
     */
    public function pdo(): PDO
    {
        return $this->pdo;
    }
}