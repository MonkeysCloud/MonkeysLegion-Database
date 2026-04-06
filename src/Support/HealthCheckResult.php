<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Support;

/**
 * MonkeysLegion Framework — Database Package
 *
 * Immutable result of a health check.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final readonly class HealthCheckResult
{
    public function __construct(
        public bool $healthy,
        public float $latencyMs = 0.0,
        public ?string $reason = null,
    ) {}
}
