<?php

declare(strict_types=1);

namespace MonkeysLegion\Database\DSN;

use MonkeysLegion\Database\Types\DatabaseType;

abstract class AbstractDsnBuilder
{
    /**
     * @var array<string, string|int>
     */
    protected array $parameters = [];

    /**
     * @var array<string, string|int>
     */
    protected array $options = [];

    public function build(): string
    {
        return $this->getDatabaseType()->getDriverName() . ':' . $this->buildParameterString();
    }

    abstract protected function getDatabaseType(): DatabaseType;

    protected function buildParameterString(): string
    {
        return implode(';', array_map(
            fn($key, $value) => "{$key}={$value}",
            array_keys($this->parameters),
            array_values($this->parameters)
        ));
    }

    /**
     * Get the parameters used to build the DSN.
     *
     * @return array<string, string|int>
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    /**
     * Get the options that are not part of the DSN but may be used for connection configuration
     *
     * @return array<string, string|int>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    public function reset(): static
    {
        $this->parameters = [];
        $this->options = [];
        return $this;
    }
}
