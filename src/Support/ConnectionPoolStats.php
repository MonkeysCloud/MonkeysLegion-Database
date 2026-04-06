<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Support;

/**
 * MonkeysLegion Framework — Database Package
 *
 * Immutable snapshot of connection pool statistics.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final readonly class ConnectionPoolStats
{
    public function __construct(
        public int $idle,
        public int $active,
        public int $total,
        public int $maxSize,
    ) {}

    /**
     * Pool utilization as a ratio (0.0 = empty, 1.0 = full).
     */
    public function utilization(): float
    {
        return $this->maxSize > 0
            ? round($this->active / $this->maxSize, 4)
            : 0.0;
    }

    /**
     * Whether the pool has no more room for new connections.
     */
    public function isExhausted(): bool
    {
        return $this->active >= $this->maxSize;
    }

    /**
     * Whether all connections are idle (none actively in use).
     */
    public function isAllIdle(): bool
    {
        return $this->active === 0 && $this->idle > 0;
    }

    /**
     * Convert to array for monitoring/logging.
     *
     * @return array<string, int|float|bool>
     */
    public function toArray(): array
    {
        return [
            'idle'        => $this->idle,
            'active'      => $this->active,
            'total'       => $this->total,
            'max_size'    => $this->maxSize,
            'utilization' => $this->utilization(),
            'exhausted'   => $this->isExhausted(),
        ];
    }
}
