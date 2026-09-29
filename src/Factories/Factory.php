<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Factories;

/**
 * MonKeysLegion Framework — Database Package
 *
 * Abstract base class for model factories.
 *
 * Factories generate test data for database entities. Each entity
 * has a corresponding factory with a `definition()` method that
 * returns the default attribute set.
 *
 * Usage:
 *   class UserFactory extends Factory
 *   {
 *       protected string $entity = User::class;
 *
 *       public function definition(): array
 *       {
 *           return [
 *               'name'  => fake()->name(),
 *               'email' => fake()->email(),
 *           ];
 *       }
 *   }
 *
 *   // Create and persist:
 *   UserFactory::new()->create();
 *   UserFactory::new()->state(['name' => 'Bob'])->create();
 *
 * @template T of object
 *
 * @copyright 2026 MonKeysCloud Team
 * @license   MIT
 */
abstract class Factory
{
    /** @var list<callable(array): array> State modifications */
    private array $states = [];

    /** @var list<\Closure> Callbacks to run after creation */
    private array $afterCreating = [];

    /** @var array<string, mixed> Explicit overrides */
    private array $overrides = [];

    /**
     * Create a new factory instance.
     */
    public static function new(): static
    {
        return new static();
    }

    /**
     * Get the entity class name this factory builds.
     *
     * @return class-string<T>
     */
    abstract protected function entity(): string;

    /**
     * Get the default attributes for the entity.
     *
     * @return array<string, mixed>
     */
    abstract public function definition(): array;

    /**
     * Apply a state modification (merges into attributes).
     *
     * @param callable(array): array|callable|array $state
     */
    public function state(callable|array $state): static
    {
        $clone = clone $this;
        if (is_callable($state)) {
            $clone->states[] = $state;
        } else {
            $clone->states[] = fn(array $attrs): array => array_merge($attrs, $state);
        }
        return $clone;
    }

    /**
     * Set explicit attribute overrides.
     *
     * @param array<string, mixed> $overrides
     */
    public function override(array $overrides): static
    {
        $clone = clone $this;
        $clone->overrides = array_merge($clone->overrides, $overrides);
        return $clone;
    }

    /**
     * Register a callback to run after creating an entity.
     */
    public function afterCreating(\Closure $callback): static
    {
        $clone = clone $this;
        $clone->afterCreating[] = $callback;
        return $clone;
    }

    /**
     * Build attribute array (without persisting).
     *
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        $attributes = $this->definition();

        // Apply states
        foreach ($this->states as $state) {
            $attributes = $state($attributes);
        }

        // Apply overrides (highest priority)
        $attributes = array_merge($attributes, $this->overrides);

        return $attributes;
    }

    /**
     * Create an entity instance (without persisting).
     *
     * @return T
     */
    public function make(): object
    {
        $attributes = $this->raw();
        $entity = $this->entity();

        /** @var T $instance */
        $instance = new $entity();

        foreach ($attributes as $key => $value) {
            if (property_exists($instance, $key)) {
                $instance->{$key} = $value;
            }
        }

        return $instance;
    }

    /**
     * Create and persist an entity.
     *
     * @param array<string, mixed>|null $attributes Optional overrides for this call.
     * @return T
     */
    public function create(?array $attributes = null): object
    {
        if ($attributes !== null) {
            $this->overrides = array_merge($this->overrides, $attributes);
        }

        $instance = $this->make();

        // Run after-creating callbacks
        foreach ($this->afterCreating as $callback) {
            $callback($instance);
        }

        return $instance;
    }

    /**
     * Create multiple entities.
     *
     * @param int $count Number of entities to create.
     * @param array<string, mixed>|null $attributes Optional overrides.
     * @return list<T>
     */
    public function createMany(int $count, ?array $attributes = null): array
    {
        $results = [];
        for ($i = 0; $i < $count; $i++) {
            $results[] = $this->create($attributes);
        }
        return $results;
    }

    /**
     * Create multiple entities as raw attribute arrays.
     *
     * @param int $count
     * @return list<array<string, mixed>>
     */
    public function rawMany(int $count): array
    {
        $results = [];
        for ($i = 0; $i < $count; $i++) {
            $results[] = $this->raw();
        }
        return $results;
    }
}
