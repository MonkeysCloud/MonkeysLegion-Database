<?php

declare(strict_types=1);

namespace MonkeysLegion\Database\DSN;

use MonkeysLegion\Database\Types\DatabaseType;

class SQLiteDsnBuilder extends AbstractDsnBuilder
{
    protected function getDatabaseType(): DatabaseType
    {
        return DatabaseType::SQLITE;
    }

    public function file(string $path): static
    {
        $this->parameters = ['path' => $path];
        return $this;
    }

    public function memory(): static
    {
        $this->parameters = ['path' => ':memory:'];
        return $this;
    }

    protected function buildParameterString(): string
    {
        return implode('', $this->parameters);
    }

    public static function create(): static
    {
        /** @phpstan-ignore-next-line */
        return new static();
    }

    public static function inMemory(): static
    {
        return static::create()->memory();
    }

    public static function fromFile(string $path): static
    {
        return static::create()->file($path);
    }

    public static function temporary(): static
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'sqlite_');
        return static::create()->file($tempFile);
    }
}
