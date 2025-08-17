<?php

declare(strict_types=1);

namespace MonkeysLegion\Database\PostgreSQL;

use MonkeysLegion\Database\Connection\AbstractConnection;
use MonkeysLegion\Database\DSN\PostgreSQLDsnBuilder;
use MonkeysLegion\Database\Support\ConnectionHelper;

final class Connection extends AbstractConnection
{
    public function connect(): void
    {
        if ($this->pdo) {
            return;
        }

        if (empty($this->config) && !isset($this->config['dsn'])) {
            throw new \InvalidArgumentException('PostgreSQL connection configuration not found.');
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

        // Set default encoding and timezone
        $this->pdo->exec("SET NAMES 'UTF8'");
        $this->pdo->exec("SET timezone = 'UTC'");
    }

    /**
     * @param array{
     *     host?: string,
     *     port?: int,
     *     database?: string,
     *     sslmode?: string,
     *     options?: array<int, mixed>
     * } $config
     *
     * @return string
     */
    private function buildDsn(array $config): string
    {
        $builder = PostgreSQLDsnBuilder::create();

        if (isset($config['host'])) {
            $builder->host($config['host']);
        }

        if (isset($config['port'])) {
            $builder->port($config['port']);
        }

        if (isset($config['database'])) {
            $builder->database($config['database']);
        }

        if (isset($config['sslmode'])) {
            $builder->sslMode($config['sslmode']);
        }

        if (isset($config['options'])) {
            $options = [];
            // Check if associative array (key-value pairs)
            $isAssoc = array_keys($config['options']) !== range(0, count($config['options']) - 1);
            if ($isAssoc) {
                foreach ($config['options'] as $key => $value) {
                    $options[] = '-c ' . $key . '=' . $value;
                }
            } else {
                // Assume each element is a valid option fragment
                foreach ($config['options'] as $opt) {
                    $options[] = (string)$opt;
                }
            }
            $builder->options(implode(' ', $options));
        }

        return $builder->build();
    }
}
