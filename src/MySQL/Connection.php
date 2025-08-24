<?php

declare(strict_types=1);

namespace MonkeysLegion\Database\MySQL;

use MonkeysLegion\Database\Connection\AbstractConnection;
use MonkeysLegion\Database\DSN\MySQLDsnBuilder;
use MonkeysLegion\Database\Support\ConnectionHelper;

final class Connection extends AbstractConnection
{
    public function connect(): void
    {
        if ($this->pdo) {
            return;
        }

        if (empty($this->config)) {
            throw new \InvalidArgumentException('MySQL connection configuration not found.');
        }

        $c = $this->config;

        // Use provided DSN or build one from components
        $dsn = $c['dsn'] ?? $this->buildDsn($c);

        // Use helper for connection with host fallback
        $this->pdo = ConnectionHelper::createWithHostFallback(
            $dsn,
            $c['username'] ?? '',
            $c['password'] ?? '',
            $c['options'] ?? []
        );

        // Enforce strict SQL and modern defaults
        $this->pdo->exec("SET NAMES utf8mb4, sql_mode='STRICT_TRANS_TABLES'");
    }

    /**
     * @param array{
     *     host?: string,
     *     port?: int,
     *     database?: string,
     *     charset?: string,
     *     unix_socket?: string
     * } $config
     *
     * @return string
     */
    private function buildDsn(array $config): string
    {
        $builder = MySQLDsnBuilder::create();

        if (isset($config['host'])) {
            $builder->host($config['host']);
        }

        if (isset($config['port'])) {
            $builder->port($config['port']);
        }

        if (isset($config['database'])) {
            $builder->database($config['database']);
        }

        if (isset($config['charset'])) {
            $builder->charset($config['charset']);
        }

        if (isset($config['unix_socket'])) {
            $builder->unixSocket($config['unix_socket']);
        }

        return $builder->build();
    }
}
