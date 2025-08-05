<?php

declare(strict_types=1);

namespace MonkeysLegion\Database\Contracts;

use PDO;

interface ConnectionInterface
{
    public function connect(): void;
    public function disconnect(): void;
    public function isConnected(): bool;
    public function isAlive(): bool;
    public function pdo(): PDO;
}
