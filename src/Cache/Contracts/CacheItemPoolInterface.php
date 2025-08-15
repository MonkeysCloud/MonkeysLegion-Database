<?php

declare(strict_types=1);

namespace MonkeysLegion\Database\Cache\Contracts;

use Psr\Cache\CacheItemPoolInterface as PsrCacheItemPoolInterface;
use Psr\Cache\CacheItemInterface;

interface CacheItemPoolInterface extends PsrCacheItemPoolInterface
{
    /**
     * Clear cache items by prefix.
     *
     * @param string $prefix
     * @return bool
     */
    public function clearByPrefix(string $prefix): bool;

    /**
     * Get cache statistics.
     *
     * @return array<string, mixed>
     */
    public function getStatistics(): array;

    /**
     * Reset cache statistics.
     */
    public function resetStatistics(): void;
}
