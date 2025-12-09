<?php

declare(strict_types=1);

namespace MonkeysLegion\Database\Cache\Contracts;

use MonkeysLegion\Cache\CacheManager;

interface CacheInterface
{
    /**
     * Get cache manager instance for advanced operations
     */
    public function getCacheManager(): CacheManager;

    /**
     * Get the prefix used for cache keys
     */
    public function getPrefix(): string;

    /**
     * Fetch a value from the cache.
     *
     * @param string $key     The unique key of this item in the cache.
     * @param mixed  $default Default value to return if the key does not exist.
     *
     * @return mixed The value of the item from the cache, or $default in case of cache miss.
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Persist data in the cache, uniquely referenced by a key with an optional expiration TTL time.
     *
     * @param string                 $key   The key of the item to store.
     * @param mixed                  $value The value of the item to store.
     * @param null|int|\DateInterval $ttl   Optional. The TTL value of this item. If no value is sent and
     *                                      the driver supports TTL then the library may set a default value
     *                                      for it or let the driver take care of that.
     *
     * @return bool True on success and false on failure.
     */
    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool;

    /**
     * Delete an item from the cache by its unique key.
     *
     * @param string $key The unique cache key of the item to delete.
     *
     * @return bool True if the item was successfully removed. False if there was an error.
     */
    public function delete(string $key): bool;

    /**
     * Wipes clean the entire cache's keys.
     *
     * @return bool True on success and false on failure.
     */
    public function clear(): bool;

    /**
     * Obtain multiple cache items by their unique keys.
     *
     * @param iterable<string> $keys    A list of keys that can obtained in a single operation.
     * @param mixed    $default Default value to return for keys that do not exist.
     *
     * @return iterable<string, mixed> A list of key => value pairs. Cache keys that do not exist or are stale will have $default as value.
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable;

    /**
     * Persisting a set of key => value pairs in the cache, with an optional TTL.
     *
     * @param iterable<string, mixed> $values A list of key => value pairs for a multiple-set operation.
     * @param null|int|\DateInterval $ttl    Optional. The TTL value of this item. If no value is sent and
     *                                       the driver supports TTL then the library may set a default value
     *                                       for it or let the driver take care of that.
     *
     * @return bool True on success and false on failure.
     */
    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool;

    /**
     * Deletes multiple cache items in a single operation.
     *
     * @param iterable<string> $keys A list of string-based keys to be deleted.
     *
     * @return bool True on success and false on failure.
     */
    public function deleteMultiple(iterable $keys): bool;

    /**
     * Determines whether an item is present in the cache.
     *
     * @param string $key The cache item key.
     *
     * @return bool
     */
    public function has(string $key): bool;

    /**
     * Remember pattern: Get value from cache or execute callback and store result
     * 
     * @param string $key Cache key
     * @param int|\DateInterval|null $ttl Time to live
     * @param callable $callback Callback to execute if cache miss
     * @return mixed Cached or computed value
     */
    public function remember(string $key, int|\DateInterval|null $ttl, callable $callback): mixed;

    /**
     * Store value forever (no expiration)
     * 
     * @param string $key Cache key
     * @param mixed $value Value to store
     * @return bool True on success
     */
    public function forever(string $key, mixed $value): bool;

    /**
     * Increment numeric cache value
     * 
     * @param string $key Cache key
     * @param int $value Amount to increment by
     * @return int|bool New value or false on failure
     */
    public function increment(string $key, int $value = 1): int|bool;

    /**
     * Decrement numeric cache value
     * 
     * @param string $key Cache key
     * @param int $value Amount to decrement by
     * @return int|bool New value or false on failure
     */
    public function decrement(string $key, int $value = 1): int|bool;

    /**
     * Get value and delete from cache
     * 
     * @param string $key Cache key
     * @param mixed $default Default value if key doesn't exist
     * @return mixed The cached value or default
     */
    public function pull(string $key, mixed $default = null): mixed;

    /**
     * Add value to cache only if key doesn't exist
     * 
     * @param string $key Cache key
     * @param mixed $value Value to store
     * @param int|\DateInterval|null $ttl Time to live
     * @return bool True if added, false if key exists
     */
    public function add(string $key, mixed $value, int|\DateInterval|null $ttl = null): bool;

    /**
     * Use cache tags for grouped operations
     * 
     * @param array<string>|string $tags Tag(s) to group cache entries
     * @return \MonkeysLegion\Cache\CacheInterface Tagged cache manager instance
     */
    public function tags(array|string $tags): \MonkeysLegion\Cache\CacheInterface;

    /**
     * Switch to a different cache store
     * 
     * @param string|null $name Store name
     * @return \MonkeysLegion\Cache\CacheInterface Cache manager for the specified store
     */
    public function store(?string $name = null): \MonkeysLegion\Cache\CacheInterface;

    /**
     * Clear cache by prefix (useful for namespaced clearing)
     * 
     * @param string $prefix Prefix to match
     * @return bool True on success
     */
    public function clearByPrefix(string $prefix): bool;

    /**
     * Check if cache is connected and operational
     * 
     * @return bool True if cache is working
     */
    public function isConnected(): bool;

    /**
     * Get cache statistics
     * 
     * @return array<string, mixed> Statistics array
     */
    public function getStatistics(): array;
}
