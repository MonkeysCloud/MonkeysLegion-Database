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

