<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Config;

use MonkeysLegion\Database\Exceptions\ConfigurationException;
use MonkeysLegion\Database\Types\DatabaseDriver;

/**
 * MonkeysLegion Framework — Database Package
 *
 * Immutable DSN configuration value object.
 * Replaces the entire DSN builder hierarchy with a single readonly class
 * and a PHP 8.4 property hook that computes the DSN string lazily.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final readonly class DsnConfig
{
    /**
     * @param array<string, string> $extra Additional DSN parameters
     */
    public function __construct(
        public DatabaseDriver $driver,
        public ?string $host = null,
        public ?int $port = null,
        public ?string $database = null,
        public ?string $socket = null,
        public ?string $file = null,
        public bool $memory = false,
        public ?string $charset = null,
        public ?string $sslMode = null,
        public array $extra = [],
    ) {}

    /**
     * Computed DSN string — built from the component parts.
     */
    public function dsn(): string
    {
        return match ($this->driver) {
            DatabaseDriver::MySQL      => $this->buildMySqlDsn(),
            DatabaseDriver::PostgreSQL => $this->buildPgSqlDsn(),
            DatabaseDriver::SQLite     => $this->buildSqliteDsn(),
        };
    }

    /**
     * Build from a raw config array (backward-compatible with v1 format).
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(DatabaseDriver $driver, array $config): self
    {
        return new self(
            driver: $driver,
            host: isset($config['host']) ? (string) $config['host'] : null,
            port: isset($config['port']) ? (int) $config['port'] : null,
            database: isset($config['database']) ? (string) $config['database'] : null,
            socket: isset($config['unix_socket']) ? (string) $config['unix_socket'] : null,
            file: isset($config['file']) ? (string) $config['file'] : null,
            memory: (bool) ($config['memory'] ?? false),
            charset: isset($config['charset']) ? (string) $config['charset'] : null,
            sslMode: isset($config['sslmode']) ? (string) $config['sslmode'] : null,
        );
    }

    // ── Private DSN Builders ────────────────────────────────────

    private function buildMySqlDsn(): string
    {
        $parts = [];

        if ($this->socket !== null) {
            $parts[] = "unix_socket={$this->socket}";
        } else {
            if ($this->host !== null) {
                $parts[] = "host={$this->host}";
            }
            if ($this->port !== null) {
                $parts[] = "port={$this->port}";
            }
        }

        if ($this->database !== null) {
            $parts[] = "dbname={$this->database}";
        }

        $charset = $this->charset ?? 'utf8mb4';
        $parts[] = "charset={$charset}";

        foreach ($this->extra as $key => $value) {
            $parts[] = "{$key}={$value}";
        }

        return 'mysql:' . implode(';', $parts);
    }

    private function buildPgSqlDsn(): string
    {
        $parts = [];

        if ($this->host !== null) {
            $parts[] = "host={$this->host}";
        }

        if ($this->port !== null) {
            $parts[] = "port={$this->port}";
        }

        if ($this->database !== null) {
            $parts[] = "dbname={$this->database}";
        }

        if ($this->sslMode !== null) {
            $parts[] = "sslmode={$this->sslMode}";
        }

        foreach ($this->extra as $key => $value) {
            $parts[] = "{$key}={$value}";
        }

        return 'pgsql:' . implode(';', $parts);
    }

    private function buildSqliteDsn(): string
    {
        if ($this->memory) {
            return 'sqlite::memory:';
        }

        if ($this->file !== null) {
            return 'sqlite:' . $this->file;
        }

        throw new ConfigurationException(
            'SQLite DSN requires either "file" path or "memory" = true',
            driver: DatabaseDriver::SQLite,
        );
    }
}
