# MonkeysLegion Database

A flexible PDO-based database abstraction layer supporting MySQL, PostgreSQL, and SQLite with elegant DSN builders and connection management.


## Features

- ✅ **Multi-Database Support**: MySQL, PostgreSQL, SQLite
- ✅ **DSN Builders**: Fluent API for building connection strings
- ✅ **Host Fallback**: Automatic localhost fallback for unreachable hosts
- ✅ **Connection Health Checks**: `isConnected()` and `isAlive()` methods
- ✅ **Type Safety**: Full enum support and strict typing
- ✅ **Factory Pattern**: Flexible connection creation
- ✅ **Docker Ready**: Built-in support for containerized environments
- ✅ **Zero Dependencies**: Pure PDO implementation
- ✅ **High-Performance Caching**: Array, FileSystem, and Redis cache adapters
- ✅ **Concurrency Protection**: Lock-based stale-while-revalidate pattern
- ✅ **Automatic Cleanup**: Intelligent cache maintenance and monitoring
- ✅ **PSR Compatible**: Follows PSR-16 caching standards

## Installation

```bash
composer require monkeyscloud/monkeyslegion-database
```

## Basic Usage

### 1. Direct Connection Creation

```php
use MonkeysLegion\Database\MySQL\Connection as MySQLConnection;
use MonkeysLegion\Database\PostgreSQL\Connection as PostgreSQLConnection;
use MonkeysLegion\Database\SQLite\Connection as SQLiteConnection;

// MySQL
$config = [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'dsn' => 'mysql:host=localhost;dbname=myapp',
            'username' => 'root',
            'password' => 'secret',
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        ],
        ...
    ]
];

// You must pass only the sub-config specific to the DB type, not the whole config array
$connection = new MySQLConnection($config['connections']['mysql']); // No connection established yet

// when calling pdo(), connect() method called automatically
$pdo = $connection->pdo();
```

### 2. Using ConnectionFactory (Recommended)

```php
use MonkeysLegion\Database\Factory\ConnectionFactory;
use MonkeysLegion\Database\Types\DatabaseType;

$config = require base_path('config/database.php');

// Create connection using the 'default' type from config; throws if missing
$connection = ConnectionFactory::create($config);

// Or specify connection type
$connection = ConnectionFactory::createByType('mysql', $config);

// Or specify by available connection enum
$connection = ConnectionFactory::createByEnum(
    DatabaseType::MYSQL,
    $config
);
```

### 3. Dependency Injection Container Setup

#### Old Way (Direct Connection)
```php
// Before
Connection::class => fn() => new Connection(
    require base_path('config/database.php')
),
```

#### New Way (Factory Pattern)
```php
// Register interface with factory
ConnectionInterface::class => fn() =>
    ConnectionFactory::create(require base_path('config/database.php')),

// Or for dynamic switching
ConnectionInterface::class => function() {
    $config = require base_path('config/database.php');
    $type = env('DB_CONNECTION', 'mysql');
    
    return ConnectionFactory::createByType($type, $config);
}
```

## Cache System

The package includes a high-performance PSR-16 compatible cache system with three adapters:

### Array Cache Adapter

An in-memory cache adapter ideal for development or single-request caching:

```php
use MonkeysLegion\Database\Cache\Adapters\ArrayCacheAdapter;
use MonkeysLegion\Database\Cache\Items\CacheItem;

$cache = new ArrayCacheAdapter();

// Basic operations
$item = new CacheItem('user_123');
$item->set(['name' => 'John Doe', 'email' => 'john@example.com']);
$item->expiresAfter(300); // 5 minutes

$cache->save($item);

// Retrieve item
$cachedItem = $cache->getItem('user_123');
if ($cachedItem->isHit()) {
    $userData = $cachedItem->get();
}

// Batch operations
$cache->saveDeferred($item1);
$cache->saveDeferred($item2);
$cache->commit(); // Save all deferred items

// Statistics
$stats = $cache->getStatistics();
echo "Hit ratio: " . $stats['hit_ratio'] . "%\n";
```

### FileSystem Cache Adapter

A persistent file-based cache with advanced concurrency protection and automatic cleanup:

```php
use MonkeysLegion\Database\Cache\Adapters\FileSystemAdapter;
use MonkeysLegion\Database\Cache\Items\CacheItem;

// Initialize with custom directory (optional)
$cache = new FileSystemAdapter('/path/to/cache/dir');
// Or use system temp directory
$cache = new FileSystemAdapter();

// Basic caching
$item = new CacheItem('expensive_query_result');
$item->set($queryResult);
$item->expiresAfter(3600); // 1 hour

$cache->save($item);

// Retrieve with automatic expiration handling
$cachedItem = $cache->getItem('expensive_query_result');
if ($cachedItem->isHit()) {
    return $cachedItem->get(); // Fresh data
}

// If cache miss or expired, regenerate data
$freshData = expensiveOperation();
$item->set($freshData);
$cache->save($item);
```

#### Advanced FileSystem Cache Features

**1. Concurrency Protection & Stale-While-Revalidate**

The FileSystem adapter implements a sophisticated lock-based concurrency system that prevents cache stampedes while serving stale data for optimal performance:

```php
// Process A: Cache expires, starts regenerating with lock
$item = $cache->getItem('heavy_computation');
if (!$item->isHit()) {
    // Creates lock file automatically during save
    $newData = heavyComputation(); // Takes 10 seconds
    $item->set($newData);
    $cache->save($item); // Lock is released after save
}

// Process B: During Process A's computation
$item = $cache->getItem('heavy_computation');
// Returns stale data immediately (marked as miss but has value)
// No waiting, no duplicate computation
if ($item->get() !== null) {
    return $item->get(); // Stale but valid data
}
```

**Lock Logic:**
- When cache expires, the first process creates a lock file with its process ID
- Other processes check if lock exists and belongs to different process
- If different process lock exists: return stale data immediately (no waiting)
- If same process lock exists: proceed with cache regeneration
- Locks auto-expire to prevent deadlocks

**2. Prefix-Based Operations**

```php
// Save items with prefixes
$cache->save(new CacheItem('user_123'));
$cache->save(new CacheItem('user_456'));
$cache->save(new CacheItem('post_789'));

// Clear all user cache
$cache->clearByPrefix('user_'); // Only removes user_* items

// Statistics show cache organization
$stats = $cache->getStatistics();
echo "Total cache files: " . $stats['cache_files'] . "\n";
echo "Cache size: " . $stats['cache_size_bytes'] . " bytes\n";
```

**3. Automatic Cleanup System**

The adapter includes intelligent cleanup that runs automatically:

```php
// Configure cleanup behavior
$cache->configureAutoCleanup(
    enabled: true,
    probability: 100,    // 1 in 100 chance per operation
    interval: 3600       // Full cleanup every hour
);

// Manual cleanup with detailed stats
$cleanupStats = $cache->runFullCleanup();
echo "Removed {$cleanupStats['expired_cache_files']} expired files\n";
echo "Freed {$cleanupStats['total_freed_bytes']} bytes\n";
echo "Cleaned {$cleanupStats['corrupted_files']} corrupted files\n";
```

**4. Cache Statistics & Monitoring**

```php
$stats = $cache->getStatistics();

echo "Performance:\n";
echo "  Hit Ratio: {$stats['hit_ratio']}%\n";
echo "  Total Operations: {$stats['total_operations']}\n";
echo "  Hits: {$stats['hits']}, Misses: {$stats['misses']}\n";

echo "\nConcurrency:\n";
echo "  Stale Returns: {$stats['stale_returns']}\n";
echo "  Lock Waits: {$stats['lock_waits']}\n";
echo "  Lock Timeouts: {$stats['lock_timeouts']}\n";

echo "\nStorage:\n";
echo "  Cache Files: {$stats['cache_files']}\n";
echo "  Lock Files: {$stats['lock_files']}\n";
echo "  Total Size: {$stats['cache_size_bytes']} bytes\n";

// Reset stats for monitoring periods
$cache->resetStatistics();
```

**5. File Organization Strategy**

The adapter uses a smart file naming strategy for optimal performance:

```php
// Keys like 'user_123', 'user_456' get organized by prefix
// Files: <prefix_hash>_<key_hash>.cache
// Example: a1b2c3_d4e5f6.cache, a1b2c3_g7h8i9.cache

// This allows:
// - Fast prefix-based clearing (glob by prefix hash)
// - Collision prevention (full key hash)
// - Directory organization (related items cluster)
```

#### Cache Configuration Best Practices

```php
// Production settings
$cache = new FileSystemAdapter('/var/cache/app');
$cache->configureAutoCleanup(
    enabled: true,
    probability: 1000,   // Less frequent cleanup (1 in 1000)
    interval: 7200       // Cleanup every 2 hours
);
$cache->setLockExpiration(30); // 30 second lock timeout

// Development settings
$cache = new FileSystemAdapter();
$cache->configureAutoCleanup(
    enabled: true,
    probability: 10,     // More frequent cleanup (1 in 10)
    interval: 300        // Cleanup every 5 minutes
);
```

### Redis Cache Adapter

A high-performance distributed cache adapter using Redis for scalable applications:

```php
use MonkeysLegion\Database\Cache\Adapters\RedisCacheAdapter;
use MonkeysLegion\Database\Cache\Items\CacheItem;

// Method 1: Using existing Redis connection
$redis = new \Redis();
$redis->connect('127.0.0.1', 6379);
$cache = new RedisCacheAdapter($redis, 'myapp:');

// Method 2: Using factory method (recommended)
$cache = new RedisCacheAdapter(
    RedisCacheAdapter::createConnection(
        host: 'localhost',
        port: 6379,
        auth: 'password',      // optional
        database: 0           // optional
    ),
    'myapp:'
);

// Basic caching
$item = new CacheItem('user_session_123');
$item->set(['user_id' => 123, 'roles' => ['admin']]);
$item->expiresAfter(1800); // 30 minutes

$cache->save($item);

// Retrieve cached data
$cachedItem = $cache->getItem('user_session_123');
if ($cachedItem->isHit()) {
    $sessionData = $cachedItem->get();
}
```

#### Advanced Redis Cache Features

**1. High-Performance Batch Operations**

```php
// Efficient multi-get using Redis MGET
$items = $cache->getItems(['user_1', 'user_2', 'user_3']);
foreach ($items as $key => $item) {
    if ($item->isHit()) {
        echo "User {$key}: " . json_encode($item->get()) . "\n";
    }
}

// Efficient multi-delete
$cache->deleteItems(['user_1', 'user_2', 'user_3']);

// Pipeline commits for better performance
$cache->saveDeferred($item1);
$cache->saveDeferred($item2);
$cache->saveDeferred($item3);
$cache->commit(); // All saved in single pipeline
```

**2. Connection Management & Health Monitoring**

```php
// Check connection health
if ($cache->isConnected()) {
    echo "Redis is responsive\n";
}

// Get detailed Redis statistics
$stats = $cache->getStatistics();
echo "Hit Ratio: {$stats['hit_ratio']}%\n";
echo "Redis Version: " . $stats['redis_stats']['redis_version'] . "\n";
echo "Connected Clients: " . $stats['redis_stats']['connected_clients'] . "\n";

// Get connection info
$info = $cache->getConnectionInfo();
echo "Redis Server: {$info['redis_version']}\n";
echo "Used Memory: {$info['used_memory_human']}\n";
```

**3. Advanced Configuration**

```php
// Production Redis setup with authentication
$cache = new RedisCacheAdapter(
    RedisCacheAdapter::createConnection(
        host: 'redis.example.com',
        port: 6379,
        timeout: 2.0,          // 2 second timeout
        auth: 'secure_password',
        database: 1            // Use database 1
    ),
    'prod:cache:'             // Namespace for production
);

// Development setup
$cache = new RedisCacheAdapter(
    RedisCacheAdapter::createConnection(),
    'dev:cache:'
);
```

**4. Error Handling & Resilience**

The Redis adapter includes comprehensive error handling:

```php
try {
    $cache = new RedisCacheAdapter(
        RedisCacheAdapter::createConnection('unreachable-host')
    );
} catch (CacheException $e) {
    // Fallback to file cache
    $cache = new FileSystemAdapter();
    error_log("Redis unavailable, using file cache: " . $e->getMessage());
}

// Operations fail gracefully
$item = $cache->getItem('some_key');
// Always returns a CacheItem, even if Redis is down
if ($item->isHit()) {
    return $item->get();
} else {
    // Generate fresh data
    return expensiveOperation();
}
```

**5. Pattern-Based Operations**

```php
// Clear all user sessions
$cache->clearByPrefix('session:user:');

// Clear all cache (use with caution in production)
$cache->clear();

// Selective cleanup by pattern
$cache->clearByPrefix('temp:');      // Clear temporary data
$cache->clearByPrefix('api:v1:');    // Clear old API cache
```

#### Redis vs Other Adapters Comparison

| Feature               | Array             | FileSystem        | Redis                                 |
|-----------------------|-------------------|-------------------|---------------------------------------|
| **Persistence**       | ❌ Memory only    | ✅ File-based    | ✅ In-memory + optional persistence  |
| **Multi-process**     | ❌ Single process | ✅ Shared files  | ✅ Distributed                       |
| **Performance**       | ⚡ Fastest        | 🔶 Medium        | ⚡ Very fast                         |
| **Scalability**       | ❌ Limited        | 🔶 Single server | ✅ Distributed/Clustered             |
| **Concurrency**       | ❌ No protection  | ✅ Lock-based    | ✅ Atomic operations                 |
| **Memory Usage**      | 🔴 High           | 🟢 Low           | 🔶 Medium                            |
| **Setup Complexity**  | 🟢 None           | 🟢 None          | 🔶 Redis server required             |

#### Best Practices

```php
// Use appropriate TTL based on data type
$userProfile = new CacheItem('user_profile_123');
$userProfile->set($userData);
$userProfile->expiresAfter(3600);    // 1 hour for user data

$apiResponse = new CacheItem('api_weather_london');
$apiResponse->set($weatherData);
$apiResponse->expiresAfter(300);     // 5 minutes for weather

// Use prefixes for organization
$userCache = new RedisCacheAdapter($redis, 'users:');
$apiCache = new RedisCacheAdapter($redis, 'api:');
$sessionCache = new RedisCacheAdapter($redis, 'sessions:');

// Monitor performance
$stats = $cache->getStatistics();
if ($stats['hit_ratio'] < 80) {
    error_log("Cache hit ratio is low: {$stats['hit_ratio']}%");
}
```

## Configuration

### Database Configuration Structure

```php
// config/database.php
return [
    'default' => 'YOUR_CONNECION_DRIVER',
    'connections' => [
        'mysql' => [
            'dsn' => 'mysql:host=localhost;dbname=myapp;charset=utf8mb4',
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        ],
        'postgresql' => [
            'dsn' => 'pgsql:host=localhost;dbname=myapp',
            'username' => env('DB_USERNAME', 'postgres'),
            'password' => env('DB_PASSWORD', ''),
            'options' => []
        ],
        'sqlite' => [
            'dsn' => 'sqlite:' . database_path('database.sqlite'),
            'options' => []
        ]
    ]
};
```

### Component-Based Configuration

Instead of providing a full DSN, you can use component-based configuration:

```php
'connections' => [
    'mysql' => [
        'host' => 'localhost',
        'port' => 3306,
        'database' => 'myapp',
        'charset' => 'utf8mb4',
        'username' => 'root',
        'password' => 'secret'
    ],
    'postgresql' => [
        'host' => 'localhost',
        'port' => 5432,
        'database' => 'myapp',
        'username' => 'postgres',
        'password' => 'secret'
    ],
    'sqlite' => [
        'file' => '/path/to/database.sqlite',
        // or
        'memory' => true  // for in-memory database
    ]
]
```

## DSN Builders

Build DSNs programmatically with fluent API:

```php
use MonkeysLegion\Database\DSN\MySQLDsnBuilder;
use MonkeysLegion\Database\DSN\PostgreSQLDsnBuilder;
use MonkeysLegion\Database\DSN\SQLiteDsnBuilder;

// MySQL
$dsn = MySQLDsnBuilder::localhost('myapp')->build();
$dsn = MySQLDsnBuilder::docker('myapp', 'db')->build();

// PostgreSQL  
$dsn = PostgreSQLDsnBuilder::localhost('myapp')->build();
$dsn = PostgreSQLDsnBuilder::create()
    ->host('localhost')
    ->port(5432)
    ->database('myapp')
    ->sslMode('require')
    ->build();

// SQLite
$dsn = SQLiteDsnBuilder::inMemory()->build();
$dsn = SQLiteDsnBuilder::fromFile('/path/to/db.sqlite')->build();
$dsn = SQLiteDsnBuilder::temporary()->build();
```

## Connection Management

### Basic Operations

```php
$connection = ConnectionFactory::create($config);

function healthCheck(Connection $connection): array
{
    return [
        'connected' => $connection->isConnected(),
        'alive' => $connection->isAlive()
    ];
}

healthCheck($connection); // Returns ['connected' => false, 'alive' => false]

// Get PDO instance, Connection got established after calling pdo()
$pdo = $connection->pdo();

healthCheck($connection); // Returns ['connected' => true, 'alive' => true]

// Disconnect
$connection->disconnect();

healthCheck($connection); // Returns ['connected' => false, 'alive' => false]

```

### Host Fallback

MySQL and PostgreSQL connections automatically fall back to `localhost` if the configured host is unreachable (useful for Docker environments):

```php
// If 'db' host fails, automatically tries 'localhost'
'mysql' => [
    'dsn' => 'mysql:host=db;dbname=myapp',
    'username' => 'root',
    'password' => 'secret'
]
```

## Testing

The package includes comprehensive tests. Run them with:

```bash
composer test
composer phpstan
composer quality  # Runs both tests and static analysis
```
