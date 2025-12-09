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

    /**
     * Configure from an array that may contain 'path', 'memory', and other options
     * @param array<string, mixed> $config
     * @return static
     */
    public function fromConfig(array $config): static
    {
        // Handle path or memory setting
        if (isset($config['memory']) && $config['memory'] === true) {
            $this->memory();
        } elseif (isset($config['path'])) {
            $this->file((string)$config['path']);
        }

        // Handle other SQLite options
        $options = array_diff_key($config, ['path' => null, 'memory' => null]);
        foreach ($options as $key => $value) {
            $this->setOption($key, $value);
        }

        return $this;
    }

    /**
     * Set a SQLite-specific option
     * @param string $key
     * @param mixed $value
     * @return static
     */
    public function setOption(string $key, mixed $value): static
    {
        // Store non-path options separately
        if ($key !== 'path') {
            $this->parameters[$key] = (string)$value;
        }
        return $this;
    }

    protected function buildParameterString(): string
    {
        if (!isset($this->parameters['path'])) {
            throw new \RuntimeException('SQLite path not set.');
        }

        $path = $this->parameters['path'];

        // Ensure $path is a string for type safety
        if (!is_string($path)) {
            throw new \RuntimeException('SQLite path must be a string.');
        }

        // For in-memory DB, PDO expects 'sqlite::memory:'
        if ($path === ':memory:') {
            return ':memory:';
        }

        // For file-based DB, we only return the path as SQLite doesn't support
        // additional parameters in the DSN like other databases
        return $path;
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

    /**
     * Create a DSN for a temporary SQLite database file.
     * The file will be created in the system's temporary directory.
     * @return static
     * @throws \RuntimeException if the temporary file cannot be created
     */
    public static function temporary(): static
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'sqlite_');
        if ($tempFile === false) {
            throw new \RuntimeException('Failed to create a temporary SQLite file.');
        }
        return static::create()->file($tempFile);
    }

    /**
     * Create a DSN builder from configuration array
     * @param array<string, mixed> $config
     * @return static
     */
    public static function fromConfigArray(array $config): static
    {
        return static::create()->fromConfig($config);
    }
}
