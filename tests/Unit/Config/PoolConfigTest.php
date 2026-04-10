<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Tests\Unit\Config;

use MonkeysLegion\Database\Config\PoolConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PoolConfig::class)]
final class PoolConfigTest extends TestCase
{
    #[Test]
    public function defaultValues(): void
    {
        $config = new PoolConfig();

        $this->assertSame(1, $config->minConnections);
        $this->assertSame(10, $config->maxConnections);
        $this->assertSame(300, $config->idleTimeoutSeconds);
        $this->assertSame(3600, $config->maxLifetimeSeconds);
        $this->assertSame(30, $config->healthCheckIntervalSeconds);
        $this->assertTrue($config->validateOnAcquire);
    }

    #[Test]
    public function customValues(): void
    {
        $config = new PoolConfig(
            minConnections: 5,
            maxConnections: 50,
            idleTimeoutSeconds: 600,
            maxLifetimeSeconds: 7200,
            healthCheckIntervalSeconds: 60,
            validateOnAcquire: false,
        );

        $this->assertSame(5, $config->minConnections);
        $this->assertSame(50, $config->maxConnections);
        $this->assertSame(600, $config->idleTimeoutSeconds);
        $this->assertSame(7200, $config->maxLifetimeSeconds);
        $this->assertSame(60, $config->healthCheckIntervalSeconds);
        $this->assertFalse($config->validateOnAcquire);
    }

    #[Test]
    public function fromArrayWithDefaults(): void
    {
        $config = PoolConfig::fromArray([]);

        $this->assertSame(1, $config->minConnections);
        $this->assertSame(10, $config->maxConnections);
    }

    #[Test]
    public function fromArrayWithCustomValues(): void
    {
        $config = PoolConfig::fromArray([
            'min_connections' => 3,
            'max_connections' => 25,
            'idle_timeout' => 120,
            'max_lifetime' => 1800,
            'health_check_interval' => 15,
            'validate_on_acquire' => false,
        ]);

        $this->assertSame(3, $config->minConnections);
        $this->assertSame(25, $config->maxConnections);
        $this->assertSame(120, $config->idleTimeoutSeconds);
        $this->assertSame(1800, $config->maxLifetimeSeconds);
        $this->assertSame(15, $config->healthCheckIntervalSeconds);
        $this->assertFalse($config->validateOnAcquire);
    }
}
