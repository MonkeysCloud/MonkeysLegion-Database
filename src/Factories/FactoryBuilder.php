<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Factories;

/**
 * MonKeysLegion Framework — Database Package
 *
 * Fluent builder for factory sequences and states.
 *
 * Allows defining sequences (incrementing values), and
 * conditional states based on the current attributes.
 *
 * @copyright 2026 MonKeysCloud Team
 * @license   MIT
 */
final class FactoryBuilder
{
    /** @var array<string, \Closure> */
    private array $sequences = [];

    /** @var list<array{condition: callable(array): bool, state: callable(array): array}> */
    private array $conditionalStates = [];

    /**
     * Define a sequence for a field (increments each call).
     *
     * @param string $field
     * @param \Closure(int): mixed $callback
     */
    public function sequence(string $field, \Closure $callback): self
    {
        $counter = 0;
        $this->sequences[$field] = function() use ($callback, &$counter): mixed {
            return $callback($counter++);
        };
        return $this;
    }

    /**
     * Define a conditional state.
     *
     * @param callable(array): bool $condition
     * @param callable(array): array $state
     */
    public function when(callable $condition, callable $state): self
    {
        $this->conditionalStates[] = ['condition' => $condition, 'state' => $state];
        return $this;
    }

    /**
     * Apply sequences and conditional states to attributes.
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function apply(array $attributes): array
    {
        // Apply sequences
        foreach ($this->sequences as $field => $generator) {
            if (!isset($attributes[$field])) {
                $attributes[$field] = $generator();
            }
        }

        // Apply conditional states
        foreach ($this->conditionalStates as $conditional) {
            if ($conditional['condition']($attributes)) {
                $attributes = $conditional['state']($attributes);
            }
        }

        return $attributes;
    }
}
