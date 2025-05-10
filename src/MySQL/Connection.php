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
        $c = $config['connections']['mysql'];

        $this->pdo = new PDO(
            $c['dsn'],
            $c['username'],
            $c['password'],
            $c['options']
        );

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