<?php

declare(strict_types=1);

namespace MonkeysLegion\Database\Cache;

use MonkeysLegion\Cache\CacheManager as LegionCacheManager;
use MonkeysLegion\Database\Cache\Contracts\CacheInterface;
use MonkeysLegion\Database\Exceptions\CacheException;

/**
 * Bridge adapter to integrate MonkeysLegion-Cache package into Database package
 * 
 * This class wraps the Cache package's CacheManager to provide backward compatibility
 * with the Database package's cache interface while leveraging all the advanced features
 * of the Cache package (tagging, atomic operations, multiple drivers, etc.)
 */
class CacheManagerBridge implements CacheInterface
{
    private LegionCacheManager $cacheManager;
    private string $prefix;

    /**
     * @param LegionCacheManager $cacheManager The Legion cache manager instance
     * @param string $prefix Optional prefix for all cache keys
     */
    public function __construct(LegionCacheManager $cacheManager, string $prefix = '')
    {
        $this->cacheManager = $cacheManager;
        $this->prefix = $prefix;
    }

    /**
     * Get cache manager instance for advanced operations
     */
    public function getCacheManager(): LegionCacheManager
    {
        return $this->cacheManager;
    }

    /**
     * Get the prefix used for cache keys
     */
    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * Add prefix to cache key if configured
     */
    private function prefixKey(string $key): string
    {
        return $this->prefix ? $this->prefix . $key : $key;
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $key, mixed $default = null): mixed
    {
        try {
            /** @phpstan-ignore-next-line - Method exists via __call magic method */
            return $this->cacheManager->get($this->prefixKey($key), $default);
        } catch (\Throwable $e) {
            throw new CacheException("Failed to get cache key '{$key}': " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        try {
            /** @phpstan-ignore-next-line - Method exists via __call magic method */
            return $this->cacheManager->set($this->prefixKey($key), $value, $ttl);
        } catch (\Throwable $e) {
            throw new CacheException("Failed to set cache key '{$key}': " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $key): bool
    {
        try {
            /** @phpstan-ignore-next-line - Method exists via __call magic method */
            return $this->cacheManager->delete($this->prefixKey($key));
        } catch (\Throwable $e) {
            throw new CacheException("Failed to delete cache key '{$key}': " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function clear(): bool
    {
        try {
            /** @phpstan-ignore-next-line - Method exists via __call magic method */
            return $this->cacheManager->clear();
        } catch (\Throwable $e) {
            throw new CacheException("Failed to clear cache: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * {@inheritdoc}
     * @param iterable<string> $keys
     * @return iterable<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        try {
            $prefixedKeys = [];
            foreach ($keys as $key) {
                $prefixedKeys[] = $this->prefixKey($key);
            }
            /** @phpstan-ignore-next-line - Method exists via __call magic method */
            return $this->cacheManager->getMultiple($prefixedKeys, $default);
        } catch (\Throwable $e) {
            throw new CacheException("Failed to get multiple cache keys: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * {@inheritdoc}
     * @param iterable<string, mixed> $values
     */
    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        try {
            $prefixedValues = [];
            foreach ($values as $key => $value) {
                $prefixedValues[$this->prefixKey($key)] = $value;
            }
            /** @phpstan-ignore-next-line - Method exists via __call magic method */
            return $this->cacheManager->setMultiple($prefixedValues, $ttl);
        } catch (\Throwable $e) {
            throw new CacheException("Failed to set multiple cache keys: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * {@inheritdoc}
     * @param iterable<string> $keys
     */
    public function deleteMultiple(iterable $keys): bool
    {
        try {
            $prefixedKeys = [];
            foreach ($keys as $key) {
                $prefixedKeys[] = $this->prefixKey($key);
            }
            /** @phpstan-ignore-next-line - Method exists via __call magic method */
            return $this->cacheManager->deleteMultiple($prefixedKeys);
        } catch (\Throwable $e) {
            throw new CacheException("Failed to delete multiple cache keys: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function has(string $key): bool
    {
        try {
            /** @phpstan-ignore-next-line - Method exists via __call magic method */
            return $this->cacheManager->has($this->prefixKey($key));
        } catch (\Throwable $e) {
            throw new CacheException("Failed to check cache key '{$key}': " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Remember pattern: Get value from cache or execute callback and store result
     * 
     * @param string $key Cache key
     * @param int|\DateInterval|null $ttl Time to live
     * @param callable $callback Callback to execute if cache miss
     * @return mixed Cached or computed value
     */
    public function remember(string $key, int|\DateInterval|null $ttl, callable $callback): mixed
    {
        try {
            /** @phpstan-ignore-next-line - Method exists via __call magic method */
            return $this->cacheManager->remember($this->prefixKey($key), $ttl, $callback);
        } catch (\Throwable $e) {
            throw new CacheException("Failed to remember cache key '{$key}': " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Store value forever (no expiration)
     * 
     * @param string $key Cache key
     * @param mixed $value Value to store
     * @return bool True on success
     */
    public function forever(string $key, mixed $value): bool
    {
        try {
            /** @phpstan-ignore-next-line - Method exists via __call magic method */
            return $this->cacheManager->forever($this->prefixKey($key), $value);
        } catch (\Throwable $e) {
            throw new CacheException("Failed to store forever cache key '{$key}': " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Increment numeric cache value
     * 
     * @param string $key Cache key
     * @param int $value Amount to increment by
     * @return int|bool New value or false on failure
     */
    public function increment(string $key, int $value = 1): int|bool
    {
        try {
            /** @phpstan-ignore-next-line - Method exists via __call magic method */
            return $this->cacheManager->increment($this->prefixKey($key), $value);
        } catch (\Throwable $e) {
            throw new CacheException("Failed to increment cache key '{$key}': " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Decrement numeric cache value
     * 
     * @param string $key Cache key
     * @param int $value Amount to decrement by
     * @return int|bool New value or false on failure
     */
    public function decrement(string $key, int $value = 1): int|bool
    {
        try {
            /** @phpstan-ignore-next-line - Method exists via __call magic method */
            return $this->cacheManager->decrement($this->prefixKey($key), $value);
        } catch (\Throwable $e) {
            throw new CacheException("Failed to decrement cache key '{$key}': " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get value and delete from cache
     * 
     * @param string $key Cache key
     * @param mixed $default Default value if key doesn't exist
     * @return mixed The cached value or default
     */
    public function pull(string $key, mixed $default = null): mixed
    {
        try {
            /** @phpstan-ignore-next-line - Method exists via __call magic method */
            return $this->cacheManager->pull($this->prefixKey($key), $default);
        } catch (\Throwable $e) {
            throw new CacheException("Failed to pull cache key '{$key}': " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Add value to cache only if key doesn't exist
     * 
     * @param string $key Cache key
     * @param mixed $value Value to store
     * @param int|\DateInterval|null $ttl Time to live
     * @return bool True if added, false if key exists
     */
    public function add(string $key, mixed $value, int|\DateInterval|null $ttl = null): bool
    {
        try {
            /** @phpstan-ignore-next-line - Method exists via __call magic method */
            return $this->cacheManager->add($this->prefixKey($key), $value, $ttl);
        } catch (\Throwable $e) {
            throw new CacheException("Failed to add cache key '{$key}': " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * {@inheritdoc}
     * @param array<string>|string $tags
     * @phpstan-return \MonkeysLegion\Cache\CacheInterface
     */
    public function tags(array|string $tags): \MonkeysLegion\Cache\CacheInterface
    {
        /** @phpstan-ignore-next-line - Method exists via __call magic method */
        return $this->cacheManager->tags($tags);
    }

    /**
     * {@inheritdoc}
     * @phpstan-return \MonkeysLegion\Cache\CacheInterface
     */
    public function store(?string $name = null): \MonkeysLegion\Cache\CacheInterface
    {
        return $this->cacheManager->store($name);
    }

    /**
     * Clear cache by prefix (useful for namespaced clearing)
     * 
     * @param string $prefix Prefix to match
     * @return bool True on success
     */
    public function clearByPrefix(string $prefix): bool
    {
        try {
            // Use tagging feature if available, otherwise implement custom logic
            // For now, we'll use the underlying store's prefix clearing if available
            /** @phpstan-ignore-next-line - Method exists via __call magic method */
            $store = $this->cacheManager->getStore();

            if (method_exists($store, 'clearByPrefix')) {
                /** @phpstan-ignore-next-line - Dynamic method call on store object */
                return $store->clearByPrefix($this->prefix . $prefix);
            }

            // Fallback: use tags if the store supports them
            if (method_exists($store, 'tags')) {
                /** @phpstan-ignore-next-line - Method exists via __call magic method */
                return $this->cacheManager->tags([$this->prefix . $prefix])->clear();
            }

            return false;
        } catch (\Throwable $e) {
            throw new CacheException("Failed to clear by prefix '{$prefix}': " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Check if cache is connected and operational
     * 
     * @return bool True if cache is working
     */
    public function isConnected(): bool
    {
        try {
            // Try a simple operation to verify connectivity
            $testKey = '__connection_test__';
            /** @phpstan-ignore-next-line - Method exists via __call magic method */
            $this->cacheManager->set($testKey, true, 1);
            /** @phpstan-ignore-next-line - Method exists via __call magic method */
            $result = $this->cacheManager->has($testKey);
            /** @phpstan-ignore-next-line - Method exists via __call magic method */
            $this->cacheManager->delete($testKey);
            return $result;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Get cache statistics
     * 
     * @return array<string, mixed> Statistics array
     */
    public function getStatistics(): array
    {
        try {
            /** @phpstan-ignore-next-line - Method exists via __call magic method */
            $store = $this->cacheManager->getStore();

            // Try to get store-specific statistics
            if (method_exists($store, 'getStatistics')) {
                /** @phpstan-ignore-next-line - Dynamic method call on store object */
                return $store->getStatistics();
            }

            // Return basic info
            return [
                'driver' => get_class($store),
                'prefix' => $this->prefix,
                'connected' => $this->isConnected(),
            ];
        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage(),
                'connected' => false,
            ];
        }
    }
}
