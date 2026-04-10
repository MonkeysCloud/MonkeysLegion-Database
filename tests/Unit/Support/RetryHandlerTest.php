<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Tests\Unit\Support;

use MonkeysLegion\Database\Exceptions\ConnectionLostException;
use MonkeysLegion\Database\Exceptions\DeadlockException;
use MonkeysLegion\Database\Support\RetryConfig;
use MonkeysLegion\Database\Support\RetryHandler;
use MonkeysLegion\Database\Types\DatabaseDriver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RetryHandler::class)]
#[CoversClass(RetryConfig::class)]
final class RetryHandlerTest extends TestCase
{
    // ── RetryConfig ─────────────────────────────────────────────

    #[Test]
    public function retryConfigDefaults(): void
    {
        $cfg = new RetryConfig();

        $this->assertSame(3, $cfg->maxAttempts);
        $this->assertSame(10, $cfg->baseDelayMs);
        $this->assertSame(2.0, $cfg->multiplier);
        $this->assertSame(1_000, $cfg->maxDelayMs);
        $this->assertTrue($cfg->jitter);
    }

    #[Test]
    public function effectiveMaxDelayHookReturnsClamped(): void
    {
        $cfg = new RetryConfig(maxDelayMs: 500);
        $this->assertSame(500, $cfg->effectiveMaxDelay());
    }

    #[Test]
    public function effectiveMaxDelayIsNeverNegative(): void
    {
        $cfg = new RetryConfig(maxDelayMs: -100);
        $this->assertSame(0, $cfg->effectiveMaxDelay());
    }

    #[Test]
    public function delayForIsZeroWithZeroBase(): void
    {
        $cfg = new RetryConfig(baseDelayMs: 0, jitter: false);
        $this->assertSame(0, $cfg->delayFor(0));
        $this->assertSame(0, $cfg->delayFor(2));
    }

    #[Test]
    public function delayForExponentialGrowthWithoutJitter(): void
    {
        $cfg = new RetryConfig(baseDelayMs: 10, multiplier: 2.0, maxDelayMs: 10_000, jitter: false);

        $this->assertSame(10, $cfg->delayFor(0));   // 10 * 2^0 = 10
        $this->assertSame(20, $cfg->delayFor(1));   // 10 * 2^1 = 20
        $this->assertSame(40, $cfg->delayFor(2));   // 10 * 2^2 = 40
    }

    #[Test]
    public function delayForIsCappedAtMaxDelay(): void
    {
        $cfg = new RetryConfig(baseDelayMs: 100, multiplier: 10.0, maxDelayMs: 200, jitter: false);

        $this->assertSame(100, $cfg->delayFor(0));
        $this->assertSame(200, $cfg->delayFor(1)); // 1000 capped at 200
        $this->assertSame(200, $cfg->delayFor(2)); // 10000 capped at 200
    }

    // ── RetryHandler::withRetry ──────────────────────────────────

    #[Test]
    public function successOnFirstAttemptReturnsResult(): void
    {
        $result = RetryHandler::withRetry(
            static fn() => 'ok',
            new RetryConfig(maxAttempts: 3, baseDelayMs: 0),
        );

        $this->assertSame('ok', $result);
    }

    #[Test]
    public function retriesOnDeadlockAndEventuallySucceeds(): void
    {
        $calls = 0;

        $result = RetryHandler::withRetry(
            function () use (&$calls): string {
                $calls++;
                if ($calls < 3) {
                    throw $this->makeDeadlock();
                }
                return 'done';
            },
            new RetryConfig(maxAttempts: 5, baseDelayMs: 0, jitter: false),
        );

        $this->assertSame('done', $result);
        $this->assertSame(3, $calls);
    }

    #[Test]
    public function throwsDeadlockWhenMaxAttemptsExhausted(): void
    {
        $this->expectException(DeadlockException::class);

        RetryHandler::withRetry(
            fn() => throw $this->makeDeadlock(),
            new RetryConfig(maxAttempts: 2, baseDelayMs: 0, jitter: false),
        );
    }

    #[Test]
    public function doesNotRetryNonRetryableExceptions(): void
    {
        $calls = 0;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        RetryHandler::withRetry(
            function () use (&$calls): never {
                $calls++;
                throw new \RuntimeException('boom');
            },
            new RetryConfig(maxAttempts: 3, baseDelayMs: 0),
        );

        // Should only have been called once
        $this->assertSame(1, $calls);
    }

    #[Test]
    public function retriesRetryableConnectionLost(): void
    {
        $calls = 0;

        $result = RetryHandler::withRetry(
            function () use (&$calls): string {
                $calls++;
                if ($calls === 1) {
                    throw new ConnectionLostException(
                        message: 'gone',
                        driver: DatabaseDriver::MySQL,
                        wasInTransaction: false,
                    );
                }
                return 'reconnected';
            },
            new RetryConfig(maxAttempts: 3, baseDelayMs: 0, jitter: false),
        );

        $this->assertSame('reconnected', $result);
        $this->assertSame(2, $calls);
    }

    #[Test]
    public function doesNotRetryConnectionLostMidTransaction(): void
    {
        $this->expectException(ConnectionLostException::class);

        RetryHandler::withRetry(
            fn() => throw new ConnectionLostException(
                message: 'gone mid-tx',
                driver: DatabaseDriver::MySQL,
                wasInTransaction: true,
            ),
            new RetryConfig(maxAttempts: 3, baseDelayMs: 0, jitter: false),
        );
    }

    #[Test]
    public function singleAttemptNeverRetries(): void
    {
        $calls = 0;

        $this->expectException(DeadlockException::class);

        RetryHandler::withRetry(
            function () use (&$calls): never {
                $calls++;
                throw $this->makeDeadlock();
            },
            new RetryConfig(maxAttempts: 1, baseDelayMs: 0),
        );
    }

    // ── Helpers ─────────────────────────────────────────────────

    private function makeDeadlock(): DeadlockException
    {
        return new DeadlockException(
            message: 'Deadlock found',
            driver: DatabaseDriver::MySQL,
            sql: 'UPDATE t SET x=1',
            maxRetries: 5,
        );
    }
}
