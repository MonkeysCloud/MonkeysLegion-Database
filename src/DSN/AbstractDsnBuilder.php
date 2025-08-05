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

    public function reset(): static
    {
        $this->parameters = [];
        return $this;
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
}
