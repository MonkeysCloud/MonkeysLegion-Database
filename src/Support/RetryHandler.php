<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Support;

use MonkeysLegion\Database\Exceptions\ConnectionLostException;
use MonkeysLegion\Database\Exceptions\DeadlockException;

/**
 * MonkeysLegion Framework — Database Package
 *
 * Executes a database operation with automatic retries on transient failures.
 *
 * Supported retryable exceptions:
 *  • `DeadlockException`        — always retryable (the DB already rolled back)
 *  • `ConnectionLostException`  — retryable when NOT inside a transaction
 *
 * Retries use truncated exponential back-off with jitter to spread load and
 * reduce the chance of thundering-herd behaviour on a busy database.
 *
 * PHP 8.4 features used:
 *  • `readonly` class (`RetryConfig`) — all properties immutable after construction
 *  • `get` property hook on `RetryConfig::$maxDelayMs` for a clamped computed view
 *  • `array_any()` to test whether an exception type is in the retryable list
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class RetryHandler
{
    /**
     * Execute `$operation`, retrying up to `$config->maxAttempts - 1` extra times
     * on any retryable database exception.
     *
     * @template T
     *
     * @param callable(): T    $operation The operation to execute (and possibly retry).
     * @param RetryConfig|null $config    Retry configuration; uses sensible defaults when null.
     *
     * @return T
     *
     * @throws DeadlockException        If retries are exhausted or `$config->maxAttempts` = 1.
     * @throws ConnectionLostException  If retries are exhausted or the loss occurred mid-transaction.
     * @throws \Throwable               Any non-retryable exception is re-thrown immediately.
     */
    public static function withRetry(
        callable $operation,
        ?RetryConfig $config = null,
    ): mixed {
        $cfg     = $config ?? new RetryConfig();
        $attempt = 0;

        while (true) {
            try {
                return $operation();
            } catch (DeadlockException $e) {
                $attempt++;
                if ($attempt >= $cfg->maxAttempts || !$e->canRetry) {
                    throw $e;
                }
                self::pause($cfg->delayFor($attempt - 1));
            } catch (ConnectionLostException $e) {
                $attempt++;
                if ($attempt >= $cfg->maxAttempts || !$e->retryable) {
                    throw $e;
                }
                self::pause($cfg->delayFor($attempt - 1));
            }
        }
    }

    // ── Private ─────────────────────────────────────────────────

    private static function pause(int $delayMs): void
    {
        if ($delayMs > 0) {
            usleep($delayMs * 1_000);
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────

/**
 * Immutable configuration value object for `RetryHandler`.
 *
 * PHP 8.4 features used:
 *  • `readonly` class — properties cannot be mutated after construction
 *  • `get` property hook on `$effectiveMaxDelay` — a computed, clamped view
 *    of the base delay upper bound (demonstrates hooks on readonly classes)
 */
final readonly class RetryConfig
{
    public function __construct(
        /** Maximum number of total attempts (first try + retries). */
        public int $maxAttempts = 3,

        /** Base delay in milliseconds before the first retry. */
        public int $baseDelayMs = 10,

        /** Exponential back-off multiplier applied after each retry. */
        public float $multiplier = 2.0,

        /** Hard cap on delay between retries (milliseconds). */
        public int $maxDelayMs = 1_000,

        /** Add random jitter (±50 %) to avoid thundering-herd. */
        public bool $jitter = true,
    ) {}

    // PHP 8.4 — `get` hook on a readonly property for a computed view.
    // Returns the capped maximum delay, ensuring it is never negative.
    public int $effectiveMaxDelay {
        get => max(0, $this->maxDelayMs);
    }

    /**
     * Calculate the sleep duration (ms) for the given retry index (0-based).
     *
     * Applies truncated exponential back-off with optional ±50 % jitter.
     */
    public function delayFor(int $retryIndex): int
    {
        $delay = (int) ($this->baseDelayMs * ($this->multiplier ** $retryIndex));
        $delay = min($delay, $this->effectiveMaxDelay);

        if ($this->jitter && $delay > 0) {
            // random_int gives cryptographically-safe randomness without a float bias
            $jitterFactor = 0.5 + (random_int(0, 1_000) / 1_000);
            $delay        = (int) round($delay * $jitterFactor);
        }

        return max(0, $delay);
    }
}
