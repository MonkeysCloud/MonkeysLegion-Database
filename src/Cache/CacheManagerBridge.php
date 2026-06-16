<?php

declare(strict_types=1);

namespace MonkeysLegion\Database\Cache;

use MonkeysLegion\Cache\CacheManager;
use MonkeysLegion\Cache\CacheStore;
use MonkeysLegion\Cache\CacheStoreInterface;
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
    private CacheManager $cacheManager;
    private string $prefix;

    /**
     * @param CacheManager $cacheManager The Legion cache manager instance
     * @param string $prefix Optional prefix for all cache keys
     */
    public function __construct(CacheManager $cacheManager, string $prefix = '')
    {
        $this->cacheManager = $cacheManager;
        $this->prefix = $prefix;
    }

    /**
     * Get cache manager instance for advanced operations
     */
    public function getCacheManager(): CacheManager
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
            return $this->cacheManager->store()->get($this->prefixKey($key), $default);
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
            return $this->cacheManager->store()->set($this->prefixKey($key), $value, $ttl);
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
            return $this->cacheManager->store()->delete($this->prefixKey($key));
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
            return $this->cacheManager->store()->clear();
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
            return $this->cacheManager->store()->getMultiple($prefixedKeys, $default);
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
            return $this->cacheManager->store()->setMultiple($prefixedValues, $ttl);
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
            return $this->cacheManager->store()->deleteMultiple($prefixedKeys);
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
            return $this->cacheManager->store()->has($this->prefixKey($key));
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
            $closure = \Closure::fromCallable($callback);
            return $this->cacheManager->store()->remember($this->prefixKey($key), $ttl, $closure);
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
            return $this->cacheManager->store()->forever($this->prefixKey($key), $value);
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
            return $this->cacheManager->store()->increment($this->prefixKey($key), $value);
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
            return $this->cacheManager->store()->decrement($this->prefixKey($key), $value);
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
            return $this->cacheManager->store()->pull($this->prefixKey($key), $default);
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
            return $this->cacheManager->store()->add($this->prefixKey($key), $value, $ttl);
        } catch (\Throwable $e) {
            throw new CacheException("Failed to add cache key '{$key}': " . $e->getMessage(), 0, $e);
        }
    }

    public function tags(array|string $tags): CacheStoreInterface
    {
        $store = $this->cacheManager->store();
        $store->tags($tags);
        return $store;
    }

    /**
     * {@inheritdoc}
     * @phpstan-return CacheStore
     */
    public function store(?string $name = null): CacheStore
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
            $store = $this->cacheManager->store();

            if (method_exists($store, 'clearByPrefix')) {
                return $store->clearByPrefix("{$this->prefix}{$prefix}");
            }

            // Fallback: use tags if the store supports them
            if (method_exists($store, 'tags')) {
                return $this->cacheManager->store()->tags(["{$this->prefix}{$prefix}"])->clear();
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
            $this->cacheManager->store()->set($testKey, true, 1);
            $result = $this->cacheManager->store()->has($testKey);
            $this->cacheManager->store()->delete($testKey);
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
            $store = $this->cacheManager->store();

            // Try to get store-specific statistics
            if (method_exists($store, 'getStatistics')) {
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
