<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Tests\Unit\Config;

use MonkeysLegion\Database\Config\DsnConfig;
use MonkeysLegion\Database\Exceptions\ConfigurationException;
use MonkeysLegion\Database\Types\DatabaseDriver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DsnConfig::class)]
final class DsnConfigTest extends TestCase
{
    // ── MySQL DSN ───────────────────────────────────────────────

    #[Test]
    public function mysqlDsnWithHostAndPort(): void
    {
        $config = new DsnConfig(
            driver: DatabaseDriver::MySQL,
            host: 'localhost',
            port: 3306,
            database: 'myapp',
        );

        $dsn = $config->dsn();
        $this->assertStringContainsString('mysql:', $dsn);
        $this->assertStringContainsString('host=localhost', $dsn);
        $this->assertStringContainsString('port=3306', $dsn);
        $this->assertStringContainsString('dbname=myapp', $dsn);
        $this->assertStringContainsString('charset=utf8mb4', $dsn);
    }

    #[Test]
    public function mysqlDsnWithSocket(): void
    {
        $config = new DsnConfig(
            driver: DatabaseDriver::MySQL,
            socket: '/var/run/mysqld/mysqld.sock',
            database: 'myapp',
        );

        $dsn = $config->dsn();
        $this->assertStringContainsString('unix_socket=/var/run/mysqld/mysqld.sock', $dsn);
        $this->assertStringNotContainsString('host=', $dsn);
    }

    #[Test]
    public function mysqlDsnWithCustomCharset(): void
    {
        $config = new DsnConfig(
            driver: DatabaseDriver::MySQL,
            host: 'localhost',
            database: 'myapp',
            charset: 'latin1',
        );

        $this->assertStringContainsString('charset=latin1', $config->dsn());
    }

    #[Test]
    public function mysqlDsnWithExtraParams(): void
    {
        $config = new DsnConfig(
            driver: DatabaseDriver::MySQL,
            host: 'localhost',
            database: 'myapp',
            extra: ['timeout' => '5'],
        );

        $this->assertStringContainsString('timeout=5', $config->dsn());
    }

    // ── PostgreSQL DSN ──────────────────────────────────────────

    #[Test]
    public function pgsqlDsnWithHostAndPort(): void
    {
        $config = new DsnConfig(
            driver: DatabaseDriver::PostgreSQL,
            host: 'pghost',
            port: 5432,
            database: 'pgdb',
        );

        $dsn = $config->dsn();
        $this->assertStringStartsWith('pgsql:', $dsn);
        $this->assertStringContainsString('host=pghost', $dsn);
        $this->assertStringContainsString('port=5432', $dsn);
        $this->assertStringContainsString('dbname=pgdb', $dsn);
    }

    #[Test]
    public function pgsqlDsnWithSslMode(): void
    {
        $config = new DsnConfig(
            driver: DatabaseDriver::PostgreSQL,
            host: 'pghost',
            database: 'pgdb',
            sslMode: 'require',
        );

        $this->assertStringContainsString('sslmode=require', $config->dsn());
    }

    // ── SQLite DSN ──────────────────────────────────────────────

    #[Test]
    public function sqliteDsnMemory(): void
    {
        $config = new DsnConfig(
            driver: DatabaseDriver::SQLite,
            memory: true,
        );

        $this->assertSame('sqlite::memory:', $config->dsn());
    }

    #[Test]
    public function sqliteDsnFile(): void
    {
        $config = new DsnConfig(
            driver: DatabaseDriver::SQLite,
            file: '/tmp/test.db',
        );

        $this->assertSame('sqlite:/tmp/test.db', $config->dsn());
    }

    #[Test]
    public function sqliteDsnThrowsWithoutFileOrMemory(): void
    {
        $config = new DsnConfig(driver: DatabaseDriver::SQLite);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('SQLite DSN requires either');
        $config->dsn();
    }

    // ── fromArray ───────────────────────────────────────────────

    #[Test]
    public function fromArrayBuildsMysqlConfig(): void
    {
        $config = DsnConfig::fromArray(DatabaseDriver::MySQL, [
            'host' => '127.0.0.1',
            'port' => 3307,
            'database' => 'testdb',
            'charset' => 'utf8',
        ]);

        $this->assertSame(DatabaseDriver::MySQL, $config->driver);
        $this->assertSame('127.0.0.1', $config->host);
        $this->assertSame(3307, $config->port);
        $this->assertSame('testdb', $config->database);
        $this->assertSame('utf8', $config->charset);
    }

    #[Test]
    public function fromArrayHandlesSqliteMemory(): void
    {
        $config = DsnConfig::fromArray(DatabaseDriver::SQLite, [
            'memory' => true,
        ]);

        $this->assertTrue($config->memory);
        $this->assertSame('sqlite::memory:', $config->dsn());
    }

    #[Test]
    public function fromArrayHandlesMinimalConfig(): void
    {
        $config = DsnConfig::fromArray(DatabaseDriver::MySQL, []);

        $this->assertSame(DatabaseDriver::MySQL, $config->driver);
        $this->assertNull($config->host);
        $this->assertNull($config->port);
    }
}
