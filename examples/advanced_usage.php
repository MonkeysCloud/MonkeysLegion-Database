<?php

declare(strict_types=1);

/**
 * Example: Advanced Cache Features
 * 
 * This example demonstrates advanced cache features including:
 * - Cache tagging
 * - Atomic operations
 * - Rate limiting
 * - Multiple cache stores
 * - Cache warming
 */

require_once __DIR__ . '/../vendor/autoload.php';

if (!function_exists('env')) {
    function env($key, $default = null)
    {
        $value = getenv($key);
        return $value !== false ? $value : $default;
    }
}

if (!function_exists('storage_path')) {
    function storage_path($path = '')
    {
        return __DIR__ . '/../var/' . $path;
    }
}


use MonkeysLegion\Database\Factory\ConnectionFactory;
use MonkeysLegion\Cache\CacheManager;
use MonkeysLegion\Database\Cache\CacheManagerBridge;

// Load configurations
$dbConfig = require __DIR__ . '/../config/database.php';
$cacheConfig = require __DIR__ . '/../config/database_cache.php';

$connection = ConnectionFactory::create($dbConfig);
$pdo = $connection->pdo();

// Create cache instance
$cacheManager = new CacheManager($cacheConfig);
$cache = new CacheManagerBridge($cacheManager, $cacheConfig['prefix'] ?? '');

/**
 * Example 1: Cache Tagging for Related Data
 */
function getUserWithTagging(int $userId, PDO $pdo, $cache): array
{
    $bridge = $cache; // CacheManagerBridge instance

    // Use tags to group related cache entries
    return $bridge->tags(['users', "user:{$userId}"])->remember(
        "user:{$userId}:full",
        3600,
        function () use ($userId, $pdo) {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $user = $stmt->fetch();

            if (!$user) {
                return [];
            }

            // Get related data
            $stmt = $pdo->prepare('SELECT * FROM posts WHERE user_id = ?');
            $stmt->execute([$userId]);
            $user['posts'] = $stmt->fetchAll();

            $stmt = $pdo->prepare('SELECT * FROM comments WHERE user_id = ?');
            $stmt->execute([$userId]);
            $user['comments'] = $stmt->fetchAll();

            return $user;
        }
    );
}

/**
 * Example 2: Invalidate All User-Related Cache
 */
function invalidateUserCache(int $userId, $cache): void
{
    // Clear all cache entries tagged with this user
    if (method_exists($cache, 'tags')) {
        $cache->tags(["user:{$userId}"])->clear();
    }

    // Also clear general user lists
    $cache->tags(['users'])->clear();
}

/**
 * Example 3: Rate Limiting with Atomic Operations
 */
function checkRateLimit(string $userId, int $maxRequests, int $windowSeconds, $cache): bool
{
    $key = "rate_limit:{$userId}";

    // Increment request count
    $requests = $cache->increment($key);

    // If this is the first request, set expiration
    if ($requests === 1 || $requests === false) {
        $cache->set($key, 1, $windowSeconds);
        return true;
    }

    // Check if limit exceeded
    return $requests <= $maxRequests;
}

/**
 * Example 4: View Counter with Atomic Increment
 */
function incrementPostViews(int $postId, $cache): int
{
    $key = "post:{$postId}:views";
    return $cache->increment($key) ?: 1;
}

function getPostViews(int $postId, $cache): int
{
    $key = "post:{$postId}:views";
    return (int) $cache->get($key, 0);
}

/**
 * Example 5: Cache Lock Pattern for Expensive Operations
 */
function performExpensiveOperation(string $operationId, $cache, callable $operation): mixed
{
    $lockKey = "lock:operation:{$operationId}";
    $resultKey = "result:operation:{$operationId}";

    // Try to acquire lock
    if (!$cache->add($lockKey, true, 300)) {
        // Lock exists, check if result is available
        $retries = 0;
        while ($retries < 30) {
            if ($cache->has($resultKey)) {
                return $cache->get($resultKey);
            }
            sleep(1);
            $retries++;
        }
        throw new Exception('Operation timeout');
    }

    try {
        // Perform operation
        $result = $operation();

        // Store result
        $cache->set($resultKey, $result, 3600);

        return $result;
    } finally {
        // Release lock
        $cache->delete($lockKey);
    }
}

/**
 * Example 6: Multi-Store Cache Strategy
 */
class MultiStoreCacheStrategy
{
    private $hotCache;  // Fast cache (Redis/Memcached)
    private $coldCache; // Persistent cache (File)

    public function __construct($config)
    {
        // Hot cache for frequently accessed data
        $redisConfig = [
            'default' => 'redis',
            'stores' => [
                'redis' => [
                    'driver' => 'redis',
                    'host' => $config['redis']['host'],
                    'port' => $config['redis']['port'],
                    'prefix' => 'hot:',
                ]
            ]
        ];
        $this->hotCache = new CacheManagerBridge(new CacheManager($redisConfig), 'hot:');

        // Cold cache for less frequently accessed data
        $fileConfig = [
            'default' => 'file',
            'stores' => [
                'file' => [
                    'driver' => 'file',
                    'path' => $config['file']['path'],
                    'prefix' => 'cold:',
                ]
            ]
        ];
        $this->coldCache = new CacheManagerBridge(new CacheManager($fileConfig), 'cold:');
    }

    public function get(string $key, callable $callback, bool $isHot = false): mixed
    {
        $cache = $isHot ? $this->hotCache : $this->coldCache;
        $ttl = $isHot ? 300 : 3600; // Hot data expires faster

        return $cache->remember($key, $ttl, $callback);
    }
}

/**
 * Example 7: Cache Warming on Application Start
 */
function warmCache(PDO $pdo, $cache): void
{
    echo "Warming cache...\n";

    // Warm frequently accessed data
    $cache->remember('users:active', 3600, function () use ($pdo) {
        $stmt = $pdo->query('SELECT * FROM users WHERE active = 1');
        return $stmt->fetchAll();
    });

    $cache->remember('categories:all', 7200, function () use ($pdo) {
        $stmt = $pdo->query('SELECT * FROM categories ORDER BY name');
        return $stmt->fetchAll();
    });

    $cache->remember('settings:global', 86400, function () use ($pdo) {
        $stmt = $pdo->query('SELECT * FROM settings');
        $settings = [];
        foreach ($stmt->fetchAll() as $row) {
            $settings[$row['key']] = $row['value'];
        }
        return $settings;
    });

    echo "Cache warmed successfully\n";
}

/**
 * Example 8: Cascade Cache Invalidation
 */
function cascadeInvalidateCache(string $entity, int $entityId, $cache): void
{
    $invalidationMap = [
        'user' => [
            "user:{$entityId}",
            "user:{$entityId}:full",
            "user:{$entityId}:posts",
            'users:active',
            'users:list',
        ],
        'post' => [
            "post:{$entityId}",
            "post:{$entityId}:full",
            "post:{$entityId}:comments",
            'posts:recent',
            'posts:popular',
        ],
        'comment' => [
            "comment:{$entityId}",
            'comments:recent',
        ],
    ];

    if (isset($invalidationMap[$entity])) {
        foreach ($invalidationMap[$entity] as $key) {
            $cache->delete($key);
        }
    }

    // Also clear tags
    if (method_exists($cache, 'tags')) {
        $cache->tags([$entity, "{$entity}:{$entityId}"])->clear();
    }
}

/**
 * Example 9: Cache Statistics Monitoring
 */
function monitorCacheHealth($cache): array
{
    $stats = $cache->getStatistics();

    $health = [
        'status' => 'healthy',
        'issues' => [],
    ];

    // Check hit ratio
    if (isset($stats['hit_ratio']) && $stats['hit_ratio'] < 70) {
        $health['status'] = 'warning';
        $health['issues'][] = "Low cache hit ratio: {$stats['hit_ratio']}%";
    }

    // Check connectivity
    if (!$cache->isConnected()) {
        $health['status'] = 'critical';
        $health['issues'][] = 'Cache connection lost';
    }

    // Check memory usage (if available)
    if (isset($stats['memory_usage_percent']) && $stats['memory_usage_percent'] > 90) {
        $health['status'] = 'warning';
        $health['issues'][] = "High memory usage: {$stats['memory_usage_percent']}%";
    }

    return $health;
}

/**
 * Example 10: Stale-While-Revalidate Pattern
 */
function getWithStaleWhileRevalidate(
    string $key,
    int $freshTtl,
    int $staleTtl,
    callable $callback,
    $cache
): mixed {
    $freshKey = "{$key}:fresh";
    $staleKey = "{$key}:stale";

    // Try to get fresh data
    $fresh = $cache->get($freshKey);
    if ($fresh !== null) {
        return $fresh;
    }

    // Get stale data if available
    $stale = $cache->get($staleKey);

    // Try to acquire lock for regeneration
    $lockKey = "{$key}:lock";
    if ($cache->add($lockKey, true, 30)) {
        try {
            // Regenerate data
            $data = $callback();

            // Store fresh and stale copies
            $cache->set($freshKey, $data, $freshTtl);
            $cache->set($staleKey, $data, $staleTtl);

            return $data;
        } finally {
            $cache->delete($lockKey);
        }
    }

    // Another process is regenerating, return stale data if available
    return $stale ?? $callback();
}

// Usage Examples
try {
    echo "=== Advanced Cache Examples ===\n\n";

    // Example 1: Cache with tagging
    echo "1. Fetching user with tagging...\n";
    $user = getUserWithTagging(1, $pdo, $cache);
    echo "User fetched: " . ($user['name'] ?? 'Not found') . "\n\n";

    // Example 2: Rate limiting
    echo "2. Testing rate limiting...\n";
    for ($i = 1; $i <= 5; $i++) {
        $allowed = checkRateLimit('user_123', 3, 60, $cache);
        echo "Request {$i}: " . ($allowed ? 'Allowed' : 'Rate limited') . "\n";
    }
    echo "\n";

    // Example 3: View counter
    echo "3. Incrementing post views...\n";
    for ($i = 1; $i <= 3; $i++) {
        $views = incrementPostViews(42, $cache);
        echo "Post views: {$views}\n";
    }
    echo "\n";

    // Example 4: Cache warming
    echo "4. Warming cache...\n";
    warmCache($pdo, $cache);
    echo "\n";

    // Example 5: Monitor cache health
    echo "5. Checking cache health...\n";
    $health = monitorCacheHealth($cache);
    echo "Status: {$health['status']}\n";
    if (!empty($health['issues'])) {
        echo "Issues: " . implode(', ', $health['issues']) . "\n";
    }
    echo "\n";

    // Example 6: Stale-while-revalidate
    echo "6. Using stale-while-revalidate...\n";
    $data = getWithStaleWhileRevalidate(
        'expensive_operation',
        300,  // Fresh for 5 minutes
        3600, // Stale for 1 hour
        function () {
            echo "Executing expensive operation...\n";
            sleep(2); // Simulate expensive operation
            return ['result' => 'computed', 'timestamp' => time()];
        },
        $cache
    );
    echo "Data: " . json_encode($data) . "\n\n";

    // Example 7: Cache statistics
    if (method_exists($cache, 'getStatistics')) {
        echo "7. Cache statistics:\n";
        $stats = $cache->getStatistics();
        echo json_encode($stats, JSON_PRETTY_PRINT) . "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
