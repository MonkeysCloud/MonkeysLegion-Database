<?php

declare(strict_types=1);

/**
 * Example: Basic Database Operations with Cache
 * 
 * This example demonstrates how to use the integrated cache system
 * with database operations for improved performance.
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

// Create database connection
$connection = ConnectionFactory::create($dbConfig);
$pdo = $connection->pdo();

// Create cache instance
// Since CacheFactory is removed, we instantiate CacheManager directly and wrap it in the bridge
// We assume CacheManager accepts the configuration array
$cacheManager = new CacheManager($cacheConfig);
$cache = new CacheManagerBridge($cacheManager, $cacheConfig['prefix'] ?? '');

// Example 1: Simple query caching
function getUserById(int $userId, PDO $pdo, $cache): ?array
{
    $cacheKey = "user:{$userId}";

    return $cache->remember($cacheKey, 3600, function () use ($userId, $pdo) {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: null;
    });
}

// Example 2: List query caching
function getActiveUsers(PDO $pdo, $cache): array
{
    return $cache->remember('users:active', 1800, function () use ($pdo) {
        $stmt = $pdo->query('SELECT * FROM users WHERE active = 1 ORDER BY name');
        return $stmt->fetchAll();
    });
}

// Example 3: Paginated results caching
function getUsersPaginated(int $page, int $perPage, PDO $pdo, $cache): array
{
    $cacheKey = "users:page:{$page}:{$perPage}";

    return $cache->remember($cacheKey, 600, function () use ($page, $perPage, $pdo) {
        $offset = ($page - 1) * $perPage;
        $stmt = $pdo->prepare('SELECT * FROM users LIMIT ? OFFSET ?');
        $stmt->execute([$perPage, $offset]);
        return $stmt->fetchAll();
    });
}

// Example 4: Complex query with joins
function getUserWithPosts(int $userId, PDO $pdo, $cache): array
{
    $cacheKey = "user:{$userId}:with_posts";

    return $cache->remember($cacheKey, 900, function () use ($userId, $pdo) {
        // Get user
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user) {
            return [];
        }

        // Get user's posts
        $stmt = $pdo->prepare('SELECT * FROM posts WHERE user_id = ? ORDER BY created_at DESC');
        $stmt->execute([$userId]);
        $user['posts'] = $stmt->fetchAll();

        return $user;
    });
}

// Example 5: Update with cache invalidation
function updateUser(int $userId, array $data, PDO $pdo, $cache): bool
{
    // Build update query
    $fields = [];
    $values = [];
    foreach ($data as $field => $value) {
        $fields[] = "{$field} = ?";
        $values[] = $value;
    }
    $values[] = $userId;

    $query = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?';
    $stmt = $pdo->prepare($query);
    $result = $stmt->execute($values);

    if ($result) {
        // Invalidate all related cache entries
        $cache->delete("user:{$userId}");
        $cache->delete("user:{$userId}:with_posts");
        $cache->delete('users:active');

        // Or use tags if available
        if (method_exists($cache, 'tags')) {
            $cache->tags(['users', "user:{$userId}"])->clear();
        }
    }

    return $result;
}

// Example 6: Count queries with cache
function getTotalUsers(PDO $pdo, $cache): int
{
    return $cache->remember('users:count', 3600, function () use ($pdo) {
        $stmt = $pdo->query('SELECT COUNT(*) FROM users');
        return (int) $stmt->fetchColumn();
    });
}

// Example 7: Search with cache
function searchUsers(string $query, PDO $pdo, $cache): array
{
    $cacheKey = 'users:search:' . md5($query);

    return $cache->remember($cacheKey, 300, function () use ($query, $pdo) {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE name LIKE ? OR email LIKE ?');
        $searchTerm = "%{$query}%";
        $stmt->execute([$searchTerm, $searchTerm]);
        return $stmt->fetchAll();
    });
}

// Example 8: Aggregate queries
function getUserStatistics(PDO $pdo, $cache): array
{
    return $cache->remember('users:statistics', 7200, function () use ($pdo) {
        return [
            'total' => $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
            'active' => $pdo->query('SELECT COUNT(*) FROM users WHERE active = 1')->fetchColumn(),
            'premium' => $pdo->query('SELECT COUNT(*) FROM users WHERE premium = 1')->fetchColumn(),
            'total_posts' => $pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn(),
        ];
    });
}

// Usage examples
try {
    // Fetch user (will be cached)
    $user = getUserById(1, $pdo, $cache);
    echo "User: " . ($user['name'] ?? 'Not found') . "\n";

    // Fetch active users (will be cached)
    $activeUsers = getActiveUsers($pdo, $cache);
    echo "Active users: " . count($activeUsers) . "\n";

    // Update user (will invalidate cache)
    updateUser(1, ['name' => 'Updated Name'], $pdo, $cache);
    echo "User updated and cache invalidated\n";

    // Fetch user again (will query database and re-cache)
    $user = getUserById(1, $pdo, $cache);
    echo "User after update: " . ($user['name'] ?? 'Not found') . "\n";

    // Get statistics
    $stats = getUserStatistics($pdo, $cache);
    echo "Statistics: " . json_encode($stats) . "\n";

    // Check cache statistics
    if (method_exists($cache, 'getStatistics')) {
        $cacheStats = $cache->getStatistics();
        echo "Cache stats: " . json_encode($cacheStats) . "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
