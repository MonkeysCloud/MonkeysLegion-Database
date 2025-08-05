<?php

declare(strict_types=1);

namespace MonkeysLegion\Database\DSN;

use MonkeysLegion\Database\Types\DatabaseType;

class PostgreSQLDsnBuilder extends AbstractDsnBuilder
{
    protected function getDatabaseType(): DatabaseType
    {
        return DatabaseType::POSTGRESQL;
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

    public function user(string $user): static
    {
        $this->parameters['user'] = $user;
        return $this;
    }

    public function password(string $password): static
    {
        $this->parameters['password'] = $password;
        return $this;
    }

    public function sslMode(string $mode): static
    {
        $this->parameters['sslmode'] = $mode;
        return $this;
    }

    public function options(string $options): static
    {
        $this->parameters['options'] = $options;
        return $this;
    }

    public static function create(): static
    {
        /** @phpstan-ignore-next-line */
        return new static();
    }

    public static function localhost(string $database, int $port = 5432): static
    {
        return static::create()
            ->host('localhost')
            ->port($port)
            ->database($database);
    }

    public static function docker(string $database, string $host = 'postgres', int $port = 5432): static
    {
        return static::create()
            ->host($host)
            ->port($port)
            ->database($database);
    }
}
