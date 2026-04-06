<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Tests\Unit\Types;

use MonkeysLegion\Database\Types\IsolationLevel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IsolationLevel::class)]
final class IsolationLevelTest extends TestCase
{
    #[Test]
    public function allCasesExist(): void
    {
        $cases = IsolationLevel::cases();
        $this->assertCount(4, $cases);
    }

    #[Test]
    public function valuesMatchSqlSyntax(): void
    {
        $this->assertSame('READ UNCOMMITTED', IsolationLevel::ReadUncommitted->value);
        $this->assertSame('READ COMMITTED', IsolationLevel::ReadCommitted->value);
        $this->assertSame('REPEATABLE READ', IsolationLevel::RepeatableRead->value);
        $this->assertSame('SERIALIZABLE', IsolationLevel::Serializable->value);
    }

    #[Test]
    public function labelReturnsHumanReadable(): void
    {
        $this->assertSame('Read Uncommitted', IsolationLevel::ReadUncommitted->label());
        $this->assertSame('Read Committed', IsolationLevel::ReadCommitted->label());
        $this->assertSame('Repeatable Read', IsolationLevel::RepeatableRead->label());
        $this->assertSame('Serializable', IsolationLevel::Serializable->label());
    }

    #[Test]
    public function canBeConstructedFromValue(): void
    {
        $this->assertSame(IsolationLevel::ReadCommitted, IsolationLevel::from('READ COMMITTED'));
    }

    #[Test]
    public function tryFromReturnsNullForInvalid(): void
    {
        $this->assertNull(IsolationLevel::tryFrom('INVALID'));
    }
}
