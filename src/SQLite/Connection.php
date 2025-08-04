<?php

declare(strict_types=1);

namespace MonkeysLegion\Database\SQLite;

use MonkeysLegion\Database\Connection\AbstractConnection;
use MonkeysLegion\Database\DSN\SQLiteDsnBuilder;
use MonkeysLegion\Database\Types\DatabaseType;
use PDO;

final class Connection extends AbstractConnection
{
    public function connect(): void
    {
        if ($this->pdo) {
            return;
        }

        if (!isset($this->config['connections'][DatabaseType::SQLITE->value])) {
            throw new \InvalidArgumentException('SQLite connection configuration not found.');
        }

        $c = $this->config['connections'][DatabaseType::SQLITE->value];

        // Use provided DSN or build one from components
        $dsn = $c['dsn'] ?? $this->buildDsn($c);

        $this->pdo = new PDO(
            $dsn,
            null,
            null,
            $c['options'] ?? []
        );

        // Enable foreign key constraints and set journal mode
        $this->pdo->exec("PRAGMA foreign_keys = ON");
        $this->pdo->exec("PRAGMA journal_mode = WAL");
    }

    /**
     * @param array{
     *     file?: string,
     *     memory?: bool
     * } $config
     *
     * @return string
     *
     * @throws \InvalidArgumentException If neither 'file' nor 'memory' is provided
     */
    private function buildDsn(array $config): string
    {
        $builder = SQLiteDsnBuilder::create();

        if (isset($config['file'])) {
            $builder->file($config['file']);
        } elseif (isset($config['memory']) && $config['memory'] === true) {
            $builder->memory();
        } else {
            throw new \InvalidArgumentException('SQLite configuration must specify either "file" path or "memory" => true.');
        }

        return $builder->build();
    }
}
