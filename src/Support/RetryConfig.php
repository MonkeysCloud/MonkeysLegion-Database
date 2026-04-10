<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Support;

/**
 * MonkeysLegion Framework — Database Package
 *
 * Immutable configuration value object for `RetryHandler`.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class RetryConfig
{
    public function __construct(
        /** Maximum number of total attempts (first try + retries). */
        public readonly int $maxAttempts = 3,

        /** Base delay in milliseconds before the first retry. */
        public readonly int $baseDelayMs = 10,

        /** Exponential back-off multiplier applied after each retry. */
        public readonly float $multiplier = 2.0,

        /** Hard cap on delay between retries (milliseconds). */
        public readonly int $maxDelayMs = 1_000,

        /** Add random jitter (±50 %) to avoid thundering-herd. */
        public readonly bool $jitter = true,
    ) {}

    /**
     * Capped maximum delay, ensuring it is never negative.
     */
    public function effectiveMaxDelay(): int
    {
        return max(0, $this->maxDelayMs);
    }

    /**
     * Calculate the sleep duration (ms) for the given retry index (0-based).
     *
     * Applies truncated exponential back-off with optional ±50 % jitter.
     */
    public function delayFor(int $retryIndex): int
    {
        $delay = (int) ($this->baseDelayMs * ($this->multiplier ** $retryIndex));
        $delay = min($delay, $this->effectiveMaxDelay());

        if ($this->jitter && $delay > 0) {
            $jitterFactor = 0.5 + (random_int(0, 1_000) / 1_000);
            $delay        = (int) round($delay * $jitterFactor);
        }

        return max(0, $delay);
    }
}
