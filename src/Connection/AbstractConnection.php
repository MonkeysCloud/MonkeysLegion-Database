<?php

declare(strict_types=1);

namespace MonkeysLegion\Database\Connection;

use MonkeysLegion\Database\Contracts\ConnectionInterface;
use PDO;

abstract class AbstractConnection implements ConnectionInterface
{
    /**
     * @var PDO
     */
    protected ?PDO $pdo = null;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(protected array $config) {}

    /**
     * Disconnect from the database.
     */
    public function disconnect(): void
    {
        if ($this->pdo) {
            $this->pdo = null;
        }
    }

    /**
     * Get the PDO instance.
     *
     * @throws \RuntimeException
     * @return PDO
     */
    public function pdo(): PDO
    {
        if (!$this->pdo) {
            $this->connect();
        }

        return $this->pdo;
    }

    /**
     * Check if the connection is established.
     *
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->pdo !== null;
    }

    /**
     * Check if the connection is alive and responsive.
     *
     * @return bool
     */
    public function isAlive(): bool
    {
        try {
            return (bool) $this->pdo()->query('SELECT 1');
        } catch (\Throwable $e) {
            return false;
        }
    }
}
