<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Tests\Unit\Support;

use MonkeysLegion\Database\Support\ConnectionPoolStats;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConnectionPoolStats::class)]
final class ConnectionPoolStatsTest extends TestCase
{
    #[Test]
    public function constructionAndProperties(): void
    {
        $stats = new ConnectionPoolStats(idle: 3, active: 5, total: 10, maxSize: 20);

        $this->assertSame(3, $stats->idle);
        $this->assertSame(5, $stats->active);
        $this->assertSame(10, $stats->total);
        $this->assertSame(20, $stats->maxSize);
    }

    #[Test]
    public function utilizationRatio(): void
    {
        $stats = new ConnectionPoolStats(idle: 0, active: 5, total: 5, maxSize: 10);
        $this->assertSame(0.5, $stats->utilization());
    }

    #[Test]
    public function utilizationZeroWhenMaxSizeZero(): void
    {
        $stats = new ConnectionPoolStats(idle: 0, active: 0, total: 0, maxSize: 0);
        $this->assertSame(0.0, $stats->utilization());
    }

    #[Test]
    public function utilizationFullyUtilized(): void
    {
        $stats = new ConnectionPoolStats(idle: 0, active: 10, total: 10, maxSize: 10);
        $this->assertSame(1.0, $stats->utilization());
    }

    #[Test]
    public function isExhaustedWhenFull(): void
    {
        $stats = new ConnectionPoolStats(idle: 0, active: 10, total: 10, maxSize: 10);
        $this->assertTrue($stats->isExhausted());
    }

    #[Test]
    public function isNotExhaustedWhenRoomAvailable(): void
    {
        $stats = new ConnectionPoolStats(idle: 2, active: 5, total: 7, maxSize: 10);
        $this->assertFalse($stats->isExhausted());
    }

    #[Test]
    public function isAllIdleWhenNoActive(): void
    {
        $stats = new ConnectionPoolStats(idle: 5, active: 0, total: 5, maxSize: 10);
        $this->assertTrue($stats->isAllIdle());
    }

    #[Test]
    public function isNotAllIdleWhenSomeActive(): void
    {
        $stats = new ConnectionPoolStats(idle: 3, active: 2, total: 5, maxSize: 10);
        $this->assertFalse($stats->isAllIdle());
    }

    #[Test]
    public function isNotAllIdleWhenEmpty(): void
    {
        $stats = new ConnectionPoolStats(idle: 0, active: 0, total: 0, maxSize: 10);
        $this->assertFalse($stats->isAllIdle());
    }

    #[Test]
    public function toArrayContainsAllFields(): void
    {
        $stats = new ConnectionPoolStats(idle: 2, active: 3, total: 5, maxSize: 10);
        $arr = $stats->toArray();

        $this->assertSame(2, $arr['idle']);
        $this->assertSame(3, $arr['active']);
        $this->assertSame(5, $arr['total']);
        $this->assertSame(10, $arr['max_size']);
        $this->assertSame(0.3, $arr['utilization']);
        $this->assertFalse($arr['exhausted']);
    }
}
