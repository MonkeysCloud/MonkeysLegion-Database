<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Tests\Unit\Config;

use MonkeysLegion\Database\Config\DatabaseConfig;
use MonkeysLegion\Database\Config\DsnConfig;
use MonkeysLegion\Database\Config\PoolConfig;
use MonkeysLegion\Database\Exceptions\ConfigurationException;
use MonkeysLegion\Database\Types\DatabaseDriver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DatabaseConfig::class)]
final class DatabaseConfigTest extends TestCase
{
    #[Test]
    public function constructWithExplicitValues(): void
    {
        $dsn = new DsnConfig(
            driver: DatabaseDriver::SQLite,
            memory: true,
        );

        $config = new DatabaseConfig(
            name: 'test',
            driver: DatabaseDriver::SQLite,
            dsn: $dsn,
            timezone: 'America/New_York',
        );

        $this->assertSame('test', $config->name);
        $this->assertSame(DatabaseDriver::SQLite, $config->driver);
        $this->assertSame('America/New_York', $config->timezone);
        $this->assertNull($config->username);
        $this->assertNull($config->password);
        $this->assertNull($config->readReplica);
    }

    #[Test]
    public function effectivePdoOptionsIncludesDefaults(): void
    {
        $dsn = new DsnConfig(driver: DatabaseDriver::SQLite, memory: true);
        $config = new DatabaseConfig(
            name: 'test',
            driver: DatabaseDriver::SQLite,
            dsn: $dsn,
        );

        $options = $config->effectivePdoOptions();

        $this->assertSame(\PDO::ERRMODE_EXCEPTION, $options[\PDO::ATTR_ERRMODE]);
        $this->assertSame(\PDO::FETCH_ASSOC, $options[\PDO::ATTR_DEFAULT_FETCH_MODE]);
        $this->assertFalse($options[\PDO::ATTR_EMULATE_PREPARES]);
    }

    #[Test]
    public function effectivePdoOptionsUserOverridesDefaults(): void
    {
        $dsn = new DsnConfig(driver: DatabaseDriver::SQLite, memory: true);
        $config = new DatabaseConfig(
            name: 'test',
            driver: DatabaseDriver::SQLite,
            dsn: $dsn,
            pdoOptions: [
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_OBJ,
            ],
        );

        $options = $config->effectivePdoOptions();
        $this->assertSame(\PDO::FETCH_OBJ, $options[\PDO::ATTR_DEFAULT_FETCH_MODE]);
        // Defaults still present for non-overridden keys
        $this->assertSame(\PDO::ERRMODE_EXCEPTION, $options[\PDO::ATTR_ERRMODE]);
    }

    #[Test]
    public function fromArrayBuildsSqliteConfig(): void
    {
        $config = DatabaseConfig::fromArray('default', [
            'driver' => 'sqlite',
            'memory' => true,
        ]);

        $this->assertSame('default', $config->name);
        $this->assertSame(DatabaseDriver::SQLite, $config->driver);
        $this->assertSame('sqlite::memory:', $config->dsn->dsn());
    }

    #[Test]
    public function fromArrayThrowsWithoutDriver(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("missing the required 'driver'");

        DatabaseConfig::fromArray('broken', [
            'host' => 'localhost',
        ]);
    }

    #[Test]
    public function fromArrayParsesPoolConfig(): void
    {
        $config = DatabaseConfig::fromArray('pooled', [
            'driver' => 'sqlite',
            'memory' => true,
            'pool' => [
                'min_connections' => 2,
                'max_connections' => 20,
            ],
        ]);

        $this->assertSame(2, $config->pool->minConnections);
        $this->assertSame(20, $config->pool->maxConnections);
    }

    #[Test]
    public function fromArrayParsesPdoOptions(): void
    {
        $config = DatabaseConfig::fromArray('opts', [
            'driver' => 'sqlite',
            'memory' => true,
            'options' => [
                \PDO::ATTR_TIMEOUT => 30,
            ],
        ]);

        $this->assertArrayHasKey(\PDO::ATTR_TIMEOUT, $config->pdoOptions);
        $this->assertSame(30, $config->pdoOptions[\PDO::ATTR_TIMEOUT]);
    }

    #[Test]
    public function fromArrayDefaultTimezone(): void
    {
        $config = DatabaseConfig::fromArray('tz', [
            'driver' => 'sqlite',
            'memory' => true,
        ]);

        $this->assertSame('UTC', $config->timezone);
    }

    #[Test]
    public function fromArrayCustomTimezone(): void
    {
        $config = DatabaseConfig::fromArray('tz', [
            'driver' => 'sqlite',
            'memory' => true,
            'timezone' => 'America/Chicago',
        ]);

        $this->assertSame('America/Chicago', $config->timezone);
    }
}
