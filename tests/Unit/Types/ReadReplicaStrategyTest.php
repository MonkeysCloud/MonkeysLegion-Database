<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Tests\Unit\Types;

use MonkeysLegion\Database\Types\ReadReplicaStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReadReplicaStrategy::class)]
final class ReadReplicaStrategyTest extends TestCase
{
    #[Test]
    public function allCasesExist(): void
    {
        $cases = ReadReplicaStrategy::cases();
        $this->assertCount(3, $cases);
    }

    #[Test]
    public function valuesAreSnakeCase(): void
    {
        $this->assertSame('round_robin', ReadReplicaStrategy::RoundRobin->value);
        $this->assertSame('random', ReadReplicaStrategy::Random->value);
        $this->assertSame('least_connections', ReadReplicaStrategy::LeastConnections->value);
    }

    #[Test]
    public function labelReturnsHumanReadable(): void
    {
        $this->assertSame('Round Robin', ReadReplicaStrategy::RoundRobin->label());
        $this->assertSame('Random', ReadReplicaStrategy::Random->label());
        $this->assertSame('Least Connections', ReadReplicaStrategy::LeastConnections->label());
    }
}
