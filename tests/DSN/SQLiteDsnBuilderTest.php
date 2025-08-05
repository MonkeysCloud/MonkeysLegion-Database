<?php

declare(strict_types=1);

namespace MonkeysLegion\Database\Tests\DSN;

use MonkeysLegion\Database\DSN\SQLiteDsnBuilder;
use PHPUnit\Framework\TestCase;

class SQLiteDsnBuilderTest extends TestCase
{
    public function testInMemoryDsn(): void
    {
        $dsn = SQLiteDsnBuilder::inMemory()->build();

        $this->assertEquals('sqlite::memory:', $dsn);
    }

    public function testFileDsn(): void
    {
        $dsn = SQLiteDsnBuilder::fromFile('/path/to/database.db')->build();

        $this->assertEquals('sqlite:/path/to/database.db', $dsn);
    }

    public function testTemporaryDsn(): void
    {
        $dsn = SQLiteDsnBuilder::temporary()->build();

        $this->assertStringStartsWith('sqlite:', $dsn);
        // Check that it contains a temporary file path (works on both Windows and Unix)
        $this->assertMatchesRegularExpression('/sqlite:.*[\/\\\\].*\.tmp/', $dsn);
    }

    public function testFluentInterface(): void
    {
        $builder = SQLiteDsnBuilder::create();
        $result = $builder->file('/test.db');

        $this->assertSame($builder, $result);
        $this->assertEquals('sqlite:/test.db', $builder->build());
    }
}
