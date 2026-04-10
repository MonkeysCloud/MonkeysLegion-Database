<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Tests\Unit\Types;

use MonkeysLegion\Database\Types\DatabaseDriver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DatabaseDriver::class)]
final class DatabaseDriverTest extends TestCase
{
    #[Test]
    public function allCasesExist(): void
    {
        $cases = DatabaseDriver::cases();
        $this->assertCount(3, $cases);
        $this->assertSame('mysql', DatabaseDriver::MySQL->value);
        $this->assertSame('pgsql', DatabaseDriver::PostgreSQL->value);
        $this->assertSame('sqlite', DatabaseDriver::SQLite->value);
    }

    #[Test]
    public function pdoDriverReturnsBackingValue(): void
    {
        $this->assertSame('mysql', DatabaseDriver::MySQL->pdoDriver());
        $this->assertSame('pgsql', DatabaseDriver::PostgreSQL->pdoDriver());
        $this->assertSame('sqlite', DatabaseDriver::SQLite->pdoDriver());
    }

    #[Test]
    public function labelReturnsHumanReadable(): void
    {
        $this->assertSame('MySQL / MariaDB', DatabaseDriver::MySQL->label());
        $this->assertSame('PostgreSQL', DatabaseDriver::PostgreSQL->label());
        $this->assertSame('SQLite', DatabaseDriver::SQLite->label());
    }

    #[Test]
    public function aliasesReturnExpectedValues(): void
    {
        $this->assertContains('mysql', DatabaseDriver::MySQL->aliases());
        $this->assertContains('mariadb', DatabaseDriver::MySQL->aliases());
        $this->assertContains('pgsql', DatabaseDriver::PostgreSQL->aliases());
        $this->assertContains('postgres', DatabaseDriver::PostgreSQL->aliases());
        $this->assertContains('postgresql', DatabaseDriver::PostgreSQL->aliases());
        $this->assertContains('sqlite', DatabaseDriver::SQLite->aliases());
        $this->assertContains('sqlite3', DatabaseDriver::SQLite->aliases());
    }

    #[Test]
    public function requiredExtensionReturnsCorrectNames(): void
    {
        $this->assertSame('pdo_mysql', DatabaseDriver::MySQL->requiredExtension());
        $this->assertSame('pdo_pgsql', DatabaseDriver::PostgreSQL->requiredExtension());
        $this->assertSame('pdo_sqlite', DatabaseDriver::SQLite->requiredExtension());
    }

    #[Test]
    public function defaultPortReturnsExpectedValues(): void
    {
        $this->assertSame(3306, DatabaseDriver::MySQL->defaultPort());
        $this->assertSame(5432, DatabaseDriver::PostgreSQL->defaultPort());
        $this->assertNull(DatabaseDriver::SQLite->defaultPort());
    }

    #[Test]
    public function healthCheckSqlReturnsSelect1(): void
    {
        foreach (DatabaseDriver::cases() as $driver) {
            $this->assertSame('SELECT 1', $driver->healthCheckSql());
        }
    }

    #[Test]
    #[DataProvider('validAliasProvider')]
    public function fromStringResolvesValidAliases(string $input, DatabaseDriver $expected): void
    {
        $this->assertSame($expected, DatabaseDriver::fromString($input));
    }

    public static function validAliasProvider(): iterable
    {
        yield 'mysql' => ['mysql', DatabaseDriver::MySQL];
        yield 'MYSQL uppercase' => ['MYSQL', DatabaseDriver::MySQL];
        yield 'mariadb' => ['mariadb', DatabaseDriver::MySQL];
        yield 'MariaDB mixed' => ['MariaDB', DatabaseDriver::MySQL];
        yield 'pgsql' => ['pgsql', DatabaseDriver::PostgreSQL];
        yield 'postgres' => ['postgres', DatabaseDriver::PostgreSQL];
        yield 'postgresql' => ['postgresql', DatabaseDriver::PostgreSQL];
        yield 'PGSQL uppercase' => ['PGSQL', DatabaseDriver::PostgreSQL];
        yield 'sqlite' => ['sqlite', DatabaseDriver::SQLite];
        yield 'sqlite3' => ['sqlite3', DatabaseDriver::SQLite];
        yield 'with whitespace' => ['  mysql  ', DatabaseDriver::MySQL];
    }

    #[Test]
    public function fromStringThrowsOnInvalidDriver(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported database driver: oracle');
        DatabaseDriver::fromString('oracle');
    }

    #[Test]
    public function isExtensionLoadedReturnsBoolForSqlite(): void
    {
        // SQLite extension is almost always available in test environments
        $result = DatabaseDriver::SQLite->isExtensionLoaded();
        $this->assertIsBool($result);
    }

    #[Test]
    public function isMariaDbReturnsFalseForNonMysql(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $this->assertFalse(DatabaseDriver::SQLite->isMariaDb($pdo));
        $this->assertFalse(DatabaseDriver::PostgreSQL->isMariaDb($pdo));
    }
}
