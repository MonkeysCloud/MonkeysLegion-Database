<?php

declare(strict_types=1);

namespace MonkeysLegion\Database\Cache\Adapters;

use MonkeysLegion\Database\Cache\Contracts\CacheItemPoolInterface;
use MonkeysLegion\Database\Cache\Items\CacheItem;
use MonkeysLegion\Database\Cache\Utils\CacheKeyValidator;

class ArrayCacheAdapter implements CacheItemPoolInterface
{
    /** @var array<string, array<string, CacheItem>> */
    private array $cache = [];
    /** @var array<string, CacheItem> */
    private array $deferred = [];

    // Cache statistics
    /** @var array<string, int> */
    private array $statistics = [
        'hits' => 0,
        'misses' => 0,
        'writes' => 0,
        'deletes' => 0
    ];

    public function getItem(string $key): CacheItem
    {
        CacheKeyValidator::validateKey($key);

        $item = new CacheItem($key);

        if (isset($this->cache[$key])) {
            $cached = $this->cache[$key];
            if (!$cached['item']->isExpired()) {
                $item->set($cached['item']->get());
                $item->setHit(true);
                $item->expiresAt($cached['item']->getExpiration());
                $this->statistics['hits']++;
            } else {
                unset($this->cache[$key]);
                $this->statistics['misses']++;
            }
        } else {
            $this->statistics['misses']++;
        }

        return $item;
    }

    /**
     * Get multiple cache items.
     *
     * @param array<string> $keys
     * @return iterable<string, CacheItem>
     */
    public function getItems(array $keys = []): iterable
    {
        foreach ($keys as $key) {
            CacheKeyValidator::validateKey($key);
        }

        $items = [];
        foreach ($keys as $key) {
            $items[$key] = $this->getItem($key);
        }
        return $items;
    }

    public function hasItem(string $key): bool
    {
        CacheKeyValidator::validateKey($key);

        return isset($this->cache[$key]) && !$this->cache[$key]['item']->isExpired();
    }

    public function clear(): bool
    {
        $this->cache = [];
        return true;
    }

    public function deleteItem(string $key): bool
    {
        CacheKeyValidator::validateKey($key);

        if (isset($this->cache[$key])) {
            unset($this->cache[$key]);
            $this->statistics['deletes']++;
        }
        return true;
    }

    /**
     * Delete multiple cache items.
     *
     * @param array<string> $keys
     * @return bool
     */
    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $key) {
            CacheKeyValidator::validateKey($key);
        }

        foreach ($keys as $key) {
            $this->deleteItem($key);
        }
        return true;
    }

    public function save(\Psr\Cache\CacheItemInterface $item): bool
    {
        // Convert to our CacheItem if needed
        if (!$item instanceof CacheItem) {
            $cacheItem = new CacheItem($item->getKey());
            $cacheItem->set($item->get());
            // PSR interface doesn't have getExpiration, so we can't set expiration
            $item = $cacheItem;
        }

        $this->cache[$item->getKey()] = ['item' => $item];
        $this->statistics['writes']++;
        return true;
    }

    public function saveDeferred(\Psr\Cache\CacheItemInterface $item): bool
    {
        // Convert to our CacheItem if needed
        if (!$item instanceof CacheItem) {
            $cacheItem = new CacheItem($item->getKey());
            $cacheItem->set($item->get());
            // PSR interface doesn't have getExpiration, so we can't set expiration
            $item = $cacheItem;
        }

        $this->deferred[$item->getKey()] = $item;
        return true;
    }

    public function commit(): bool
    {
        foreach ($this->deferred as $item) {
            $this->save($item);
        }
        $this->deferred = [];
        return true;
    }

    public function clearByPrefix(string $prefix): bool
    {
        $keysToDelete = array_filter(array_keys($this->cache), fn($key) => str_starts_with($key, $prefix));
        foreach ($keysToDelete as $key) {
            unset($this->cache[$key]);
        }
        return !empty($keysToDelete);
    }

    /**
     * Get cache statistics.
     *
     * @return array<string, mixed>
     */
    public function getStatistics(): array
    {
        return $this->statistics + [
            'hit_ratio' => $this->calculateHitRatio(),
            'total_operations' => $this->statistics['hits'] + $this->statistics['misses'],
            'cache_items' => count($this->cache),
        ];
    }

    /**
     * Reset cache statistics.
     */
    public function resetStatistics(): void
    {
        $this->statistics = array_fill_keys(array_keys($this->statistics), 0);
    }

    /**
     * Calculate hit ratio as percentage.
     *
     * @return float
     */
    private function calculateHitRatio(): float
    {
        $total = $this->statistics['hits'] + $this->statistics['misses'];
        return $total > 0 ? ($this->statistics['hits'] / $total) * 100 : 0.0;
    }
}
