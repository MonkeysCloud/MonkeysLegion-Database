<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Tests\Unit\Factories;

use MonkeysLegion\Database\Factories\Factory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Factory base class.
 */
final class FactoryTest extends TestCase
{
    #[Test]
    public function raw_returns_definition_attributes(): void
    {
        $factory = UserFactory::new();
        $attrs = $factory->raw();

        self::assertArrayHasKey('name', $attrs);
        self::assertArrayHasKey('email', $attrs);
    }

    #[Test]
    public function make_creates_entity_instance(): void
    {
        $user = UserFactory::new()->make();

        self::assertInstanceOf(UserStub::class, $user);
        self::assertNotEmpty($user->name);
        self::assertNotEmpty($user->email);
    }

    #[Test]
    public function state_merges_attributes(): void
    {
        $user = UserFactory::new()
            ->state(['name' => 'Custom Name'])
            ->make();

        self::assertSame('Custom Name', $user->name);
    }

    #[Test]
    public function state_with_closure(): void
    {
        $user = UserFactory::new()
            ->state(fn(array $attrs): array => array_merge($attrs, ['name' => 'Closure Name']))
            ->make();

        self::assertSame('Closure Name', $user->name);
    }

    #[Test]
    public function override_takes_priority_over_state(): void
    {
        $user = UserFactory::new()
            ->state(['name' => 'State Name'])
            ->override(['name' => 'Override Name'])
            ->make();

        self::assertSame('Override Name', $user->name);
    }

    #[Test]
    public function create_returns_entity(): void
    {
        $user = UserFactory::new()->create();

        self::assertInstanceOf(UserStub::class, $user);
    }

    #[Test]
    public function create_with_inline_attributes(): void
    {
        $user = UserFactory::new()->create(['name' => 'Inline Name']);

        self::assertSame('Inline Name', $user->name);
    }

    #[Test]
    public function create_many_creates_count_entities(): void
    {
        $users = UserFactory::new()->createMany(5);

        self::assertCount(5, $users);
        foreach ($users as $user) {
            self::assertInstanceOf(UserStub::class, $user);
        }
    }

    #[Test]
    public function raw_many_returns_count_arrays(): void
    {
        $arrays = UserFactory::new()->rawMany(3);

        self::assertCount(3, $arrays);
        foreach ($arrays as $arr) {
            self::assertArrayHasKey('name', $arr);
        }
    }

    #[Test]
    public function after_creating_callback_runs(): void
    {
        $called = false;
        UserFactory::new()
            ->afterCreating(function() use (&$called): void { $called = true; })
            ->create();

        self::assertTrue($called);
    }

    #[Test]
    public function state_does_not_mutate_original_factory(): void
    {
        $original = UserFactory::new();
        $modified = $original->state(['name' => 'Modified']);

        $originalUser = $original->make();
        $modifiedUser = $modified->make();

        self::assertNotSame('Modified', $originalUser->name);
        self::assertSame('Modified', $modifiedUser->name);
    }
}

// ── Test Fixtures ────────────────────────────────────────────

final class UserStub
{
    public string $name = '';
    public string $email = '';
    public bool $active = true;
}

final class UserFactory extends Factory
{
    private int $counter = 0;

    protected function entity(): string
    {
        return UserStub::class;
    }

    public function definition(): array
    {
        $this->counter++;
        return [
            'name'   => "User {$this->counter}",
            'email'  => "user{$this->counter}@example.com",
            'active' => true,
        ];
    }
}
