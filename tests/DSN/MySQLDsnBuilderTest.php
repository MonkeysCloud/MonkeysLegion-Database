<?php

declare(strict_types=1);

namespace MonkeysLegion\Database\Tests\DSN;

use MonkeysLegion\Database\DSN\MySQLDsnBuilder;
use PHPUnit\Framework\TestCase;

class MySQLDsnBuilderTest extends TestCase
{
    public function testBasicDsnBuilding(): void
    {
        $dsn = MySQLDsnBuilder::create()
            ->host('localhost')
            ->port(3306)
            ->database('test')
            ->charset('utf8mb4')
            ->build();

        $this->assertEquals('mysql:host=localhost;port=3306;dbname=test;charset=utf8mb4', $dsn);
    }

    public function testLocalhostShortcut(): void
    {
        $dsn = MySQLDsnBuilder::localhost('test_db')->build();

        $this->assertEquals('mysql:host=localhost;port=3306;dbname=test_db;charset=utf8mb4', $dsn);
    }

    public function testDockerShortcut(): void
    {
        $dsn = MySQLDsnBuilder::docker('test_db', 'mysql_container')->build();

        $this->assertEquals('mysql:host=mysql_container;port=3306;dbname=test_db;charset=utf8mb4', $dsn);
    }

    public function testUnixSocket(): void
    {
        $dsn = MySQLDsnBuilder::create()
            ->database('test')
            ->unixSocket('/var/run/mysqld/mysqld.sock')
            ->build();

        $this->assertEquals('mysql:dbname=test;unix_socket=/var/run/mysqld/mysqld.sock', $dsn);
    }

    public function testReset(): void
    {
        $builder = MySQLDsnBuilder::create()
            ->host('localhost')
            ->database('test');

        $this->assertNotEmpty($builder->getParameters());

        $builder->reset();
        $this->assertEmpty($builder->getParameters());
    }
}
