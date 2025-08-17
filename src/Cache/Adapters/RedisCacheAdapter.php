<?php

declare(strict_types=1);

namespace MonkeysLegion\Database\Cache\Adapters;

use MonkeysLegion\Database\Cache\Contracts\CacheItemPoolInterface;
use MonkeysLegion\Database\Cache\Enum\Constants;
use MonkeysLegion\Database\Cache\Items\CacheItem;
use MonkeysLegion\Database\Cache\Exceptions\CacheException;
use MonkeysLegion\Database\Cache\Utils\CacheKeyValidator;

class RedisCacheAdapter implements CacheItemPoolInterface
{
    private \Redis $redis;
    private string $prefix;
    /** @var array<string, \Psr\Cache\CacheItemInterface> */
    private array $deferred = [];

    // Cache statistics
    /** @var array<string, int> */
    private array $statistics = [
        'hits' => 0,
        'misses' => 0,
        'writes' => 0,
        'deletes' => 0,
        'errors' => 0
    ];

    public function __construct(\Redis $redis, string $prefix = 'cache:')
    {
        $this->redis = $redis;
        $this->prefix = $prefix;

        try {
            $ping = $this->redis->ping();
            // Handle different Redis ping responses: '+PONG', 'PONG', 1, true
            $validPingResponses = ['+PONG', 'PONG', 1, true, '1'];
            if (!in_array($ping, $validPingResponses, true)) {
                throw new CacheException("Redis connection failed - ping returned: " . var_export($ping, true));
            }
        } catch (\RedisException $e) {
            throw new CacheException('Redis connection failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public static function createConnection(
        string $host = '127.0.0.1',
        int $port = 6379,
        float $timeout = 0.0,
        ?string $auth = null,
        ?int $database = null
    ): \Redis {
        $redis = new \Redis();

        try {
            $connected = @$redis->connect($host, $port, $timeout);
            if (!$connected) {
                throw new CacheException("Failed to connect to Redis at {$host}:{$port}");
            }

            if ($auth !== null) {
                $authResult = $redis->auth($auth);
                if (!$authResult) {
                    throw new CacheException('Redis authentication failed');
                }
            }

            if ($database !== null) {
                $selectResult = $redis->select($database);
                if (!$selectResult) {
                    throw new CacheException("Failed to select Redis database {$database}");
                }
            }
        } catch (\RedisException $e) {
            throw new CacheException('Redis connection error: ' . $e->getMessage(), 0, $e);
        }

        return $redis;
    }

    public function getItem(string $key): CacheItem
    {
        CacheKeyValidator::validateKey($key);

        $item = new CacheItem($key);
        $redisKey = $this->prefix . $key;

        try {
            $data = $this->redis->get($redisKey);

            if ($data !== false) {
                $value = @unserialize($data);
                if ($value !== false || $data === serialize(false)) {
                    $item->set($value);
                    $item->setHit(true);

                    // Get TTL and set expiration if available
                    $ttl = $this->redis->ttl($redisKey);
                    if (is_int($ttl) && $ttl > 0) {
                        $item->expiresAt((new \DateTimeImmutable())->modify("+{$ttl} seconds"));
                    }
                    $this->statistics['hits']++;
                } else {
                    // Serialization failed, data is corrupted
                    $this->redis->del($redisKey); // Clean up corrupted data
                    $this->statistics['errors']++;
                    $this->statistics['misses']++;
                }
            } else {
                $this->statistics['misses']++;
            }
        } catch (\RedisException $e) {
            $this->statistics['errors']++;
            $this->statistics['misses']++;
        }

        return $item;
    }

    /**
     * Get multiple cache items.
     *
     * @param array<string> $keys
     * @return iterable<string, \Psr\Cache\CacheItemInterface>
     */
    public function getItems(array $keys = []): iterable
    {
        foreach ($keys as $key) {
            CacheKeyValidator::validateKey($key);
        }

        $items = [];

        if (empty($keys)) {
            return $items;
        }

        try {
            // Use MGET for better performance with multiple keys
            $redisKeys = array_map(fn($key) => $this->prefix . $key, $keys);
            $values = $this->redis->mget($redisKeys);

            if (is_array($values)) {
                foreach ($keys as $index => $key) {
                    $item = new CacheItem($key);
                    $data = $values[$index] ?? false;

                    if ($data !== false) {
                        $value = @unserialize($data);
                        if ($value !== false || $data === serialize(false)) {
                            $item->set($value);
                            $item->setHit(true);
                            $this->statistics['hits']++;
                        } else {
                            $this->statistics['errors']++;
                            $this->statistics['misses']++;
                        }
                    } else {
                        $this->statistics['misses']++;
                    }

                    $items[$key] = $item;
                }
            } else {
                // Fallback to individual gets
                foreach ($keys as $key) {
                    $items[$key] = $this->getItem($key);
                }
            }
        } catch (\RedisException $e) {
            // Fallback to individual gets on Redis error
            foreach ($keys as $key) {
                $items[$key] = $this->getItem($key);
            }
        }

        return $items;
    }

    public function hasItem(string $key): bool
    {
        CacheKeyValidator::validateKey($key);

        try {
            $exists = $this->redis->exists($this->prefix . $key);
            return is_int($exists) && $exists > 0;
        } catch (\RedisException $e) {
            $this->statistics['errors']++;
            return false;
        }
    }

    public function clear(): bool
    {
        try {
            $iterator = null;
            $keys = [];
            do {
                $result = $this->redis->scan($iterator, $this->prefix . '*');
                if ($result !== false) {
                    $keys = array_merge($keys, $result);
                }
            } while ($iterator > 0);
            if (!empty($keys)) {
                $deleted = $this->redis->del($keys);
                return is_int($deleted) && $deleted > 0;
            }
            return true;
        } catch (\RedisException $e) {
            $this->statistics['errors']++;
            return false;
        }
    }

    public function deleteItem(string $key): bool
    {
        try {
            CacheKeyValidator::validateKey($key);
            $deleted = $this->redis->del($this->prefix . $key);
            if (is_int($deleted) && $deleted > 0) {
                $this->statistics['deletes']++;
            }

            return is_int($deleted) && $deleted >= 0;
        } catch (\RedisException $e) {
            $this->statistics['errors']++;
            return false;
        }
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

        if (empty($keys)) {
            return true;
        }

        try {
            $redisKeys = array_map(fn($key) => $this->prefix . $key, $keys);
            $deleted = $this->redis->del($redisKeys);
            if (is_int($deleted) && $deleted > 0) {
                $this->statistics['deletes'] += $deleted;
            }
            return is_int($deleted) && $deleted >= 0;
        } catch (\RedisException $e) {
            $this->statistics['errors']++;
            return false;
        }
    }

    public function save(\Psr\Cache\CacheItemInterface $item): bool
    {
        try {
            $redisKey = $this->prefix . $item->getKey();
            $value = serialize($item->get());

            // Check for expiration
            if ($item instanceof CacheItem && $item->getExpiration()) {
                $ttl = $item->getExpiration()->getTimestamp() - time();
                if ($ttl > 0) {
                    $result = $this->redis->setex($redisKey, $ttl, $value);
                    $success = $result === true;
                } else {
                    $success = false; // Expired before saving
                }
            } else {
                // Use default TTL if no expiration set and value is not null
                if ($item->get() !== null) {
                    $result = $this->redis->setex($redisKey, Constants::CACHE_DEFAULT_TTL, $value);
                    $success = $result === true;
                } else {
                    $result = $this->redis->set($redisKey, $value);
                    $success = $result === true || $result === 'OK';
                }
            }

            if ($success) {
                $this->statistics['writes']++;
            } else {
                $this->statistics['errors']++;
            }

            return $success;
        } catch (\RedisException $e) {
            $this->statistics['errors']++;
            return false;
        }
    }

    public function saveDeferred(\Psr\Cache\CacheItemInterface $item): bool
    {
        $this->deferred[$item->getKey()] = $item;
        return true;
    }

    public function commit(): bool
    {
        $allSuccessful = true;

        if (empty($this->deferred)) {
            return true;
        }

        try {
            // Use pipeline for better performance
            $pipe = $this->redis->multi(\Redis::PIPELINE);

            foreach ($this->deferred as $item) {
                $redisKey = $this->prefix . $item->getKey();
                $value = serialize($item->get());

                if ($item instanceof CacheItem && $item->getExpiration()) {
                    $ttl = $item->getExpiration()->getTimestamp() - time();
                    if ($ttl > 0) {
                        $pipe->setex($redisKey, $ttl, $value);
                    }
                } else {
                    if ($item->get() !== null) {
                        $pipe->setex($redisKey, Constants::CACHE_DEFAULT_TTL, $value);
                    } else {
                        $pipe->set($redisKey, $value);
                    }
                }
            }

            $results = $pipe->exec();

            if (is_array($results)) {
                foreach ($results as $result) {
                    if ($result === true || $result === 'OK') {
                        $this->statistics['writes']++;
                    } else {
                        $this->statistics['errors']++;
                        $allSuccessful = false;
                    }
                }
            } else {
                $allSuccessful = false;
                $this->statistics['errors'] += count($this->deferred);
            }
        } catch (\RedisException $e) {
            // Fallback to individual saves
            foreach ($this->deferred as $item) {
                $redisKey = $this->prefix . $item->getKey();
                $value = serialize($item->get());

                $success = false;
                if ($item instanceof CacheItem && $item->getExpiration()) {
                    $ttl = $item->getExpiration()->getTimestamp() - time();
                    if ($ttl > 0) {
                        $success = $this->redis->setex($redisKey, $ttl, $value);
                    }
                } else {
                    if ($item->get() !== null) {
                        $success = $this->redis->setex($redisKey, Constants::CACHE_DEFAULT_TTL, $value);
                    } else {
                        $success = $this->redis->set($redisKey, $value);
                    }
                }

                if ($success) {
                    $this->statistics['writes']++;
                } else {
                    $this->statistics['errors']++;
                    $allSuccessful = false;
                }
            }
        }

        $this->deferred = [];
        return $allSuccessful;
    }

    public function clearByPrefix(string $prefix): bool
    {
        try {
            $pattern = $this->prefix . $prefix . '*';
            $iterator = null;
            $keys = [];
            do {
                $batch = $this->redis->scan($iterator, $pattern);
                if ($batch !== false) {
                    $keys = array_merge($keys, $batch);
                }
            } while ($iterator > 0);
            if (!empty($keys)) {
                $deleted = $this->redis->del($keys);
                return is_int($deleted) && $deleted > 0;
            }
            return true;
        } catch (\RedisException $e) {
            $this->statistics['errors']++;
            return false;
        }
    }

    /**
     * Get cache statistics.
     *
     * @return array<string, mixed>
     */
    public function getStatistics(): array
    {
        try {
            $info = $this->redis->info('stats');
            $redisStats = is_array($info) ? $info : [];
        } catch (\RedisException $e) {
            $redisStats = ['error' => $e->getMessage()];
        }

        return $this->statistics + [
            'hit_ratio' => $this->calculateHitRatio(),
            'total_operations' => $this->statistics['hits'] + $this->statistics['misses'],
            'redis_stats' => $redisStats,
            'connection_status' => $this->isConnected(),
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

    /**
     * Check if Redis connection is still alive.
     *
     * @return bool
     */
    public function isConnected(): bool
    {
        try {
            $ping = $this->redis->ping();
            // Handle different Redis ping responses
            $validPingResponses = ['+PONG', 'PONG', 1, true, '1'];
            return in_array($ping, $validPingResponses, true);
        } catch (\RedisException $e) {
            return false;
        }
    }

    /**
     * Get Redis connection info.
     *
     * @return array<string, mixed>
     */
    public function getConnectionInfo(): array
    {
        try {
            $info = $this->redis->info('server');
            return is_array($info) ? $info : [];
        } catch (\RedisException $e) {
            return ['error' => $e->getMessage()];
        }
    }
}
