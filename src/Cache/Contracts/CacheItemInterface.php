<?php

declare(strict_types=1);

namespace MonkeysLegion\Database\Cache\Contracts;

use Psr\Cache\CacheItemInterface as PsrCacheItemInterface;

interface CacheItemInterface extends PsrCacheItemInterface
{
    /**
     * Determine if the cache item is expired.
     *
     * @return bool True if expired, false otherwise.
     */
    public function isExpired(): bool;
}
