<?php

declare(strict_types=1);

namespace MonkeysLegion\Database\DSN;

use MonkeysLegion\Database\Types\DatabaseType;

class MySQLDsnBuilder extends AbstractDsnBuilder
{
    protected function getDatabaseType(): DatabaseType
    {
        return DatabaseType::MYSQL;
    }

    public function host(string $host): static
    {
        $this->parameters['host'] = $host;
        return $this;
    }

    public function port(int $port): static
    {
        $this->parameters['port'] = $port;
        return $this;
    }

    public function database(string $database): static
    {
        $this->parameters['dbname'] = $database;
        return $this;
    }

    public function charset(string $charset = 'utf8mb4'): static
    {
        $this->parameters['charset'] = $charset;
        return $this;
    }

    public function unixSocket(string $socket): static
    {
        $this->parameters['unix_socket'] = $socket;
        return $this;
    }

    public static function create(): static
    {
        /** @phpstan-ignore-next-line */
        return new static();
    }

    public static function localhost(string $database, int $port = 3306): static
    {
        return static::create()
            ->host('localhost')
            ->port($port)
            ->database($database)
            ->charset();
    }

    public static function docker(string $database, string $host = 'db', int $port = 3306): static
    {
        return static::create()
            ->host($host)
            ->port($port)
            ->database($database)
            ->charset();
    }
}
