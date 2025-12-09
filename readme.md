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
- ✅ **Advanced Caching**: Integrates with MonkeysLegion Cache package
- ✅ **Multi-Store Support**: File, Redis, Array, and Memcached cache stores
- ✅ **Cache Tagging**: Group and manage related cache entries
- ✅ **Cache Bridge**: Prefix-based isolation for database-specific caching

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

The database package integrates with the **MonkeysLegion Cache** package through a bridge adapter, providing access to all advanced caching features including tagging, atomic operations, and multiple store drivers.

### Architecture Overview

The cache system uses a **bridge pattern** to delegate all cache operations to the `monkeyslegion-cache` package:

```
Database Package (CacheManagerBridge)
        ↓ delegates to
MonkeysLegion Cache Package (CacheManager)
        ↓ uses
Cache Stores (File, Redis, Array, Memcached)
```

## Quick Start

### 1. Basic Setup

```php
use MonkeysLegion\Cache\CacheManager;
use MonkeysLegion\Database\Cache\CacheManagerBridge;

// Configure cache manager from monkeyslegion-cache package
$cacheConfig = [
    'default' => 'file',
    'stores' => [
        'file' => [
            'driver' => 'file',
            'path' => '/path/to/cache',
            'prefix' => 'app_'
        ],
        'redis' => [
            'driver' => 'redis',
            'host' => '127.0.0.1',
            'port' => 6379,
            'prefix' => 'app_'
        ],
        'array' => [
            'driver' => 'array',
            'prefix' => 'app_'
        ]
    ]
];

$cacheManager = new CacheManager($cacheConfig);

// Create bridge with optional prefix for database-specific caching
$cache = new CacheManagerBridge($cacheManager, 'db:');
```

### 2. Basic Cache Operations

```php
// Store data (prefix automatically applied)
$cache->set('user:123', $userData, 3600); // Stored as 'db:user:123'

// Retrieve data
$userData = $cache->get('user:123', $default = null);

// Check existence
if ($cache->has('user:123')) {
    echo "User cached!";
}

// Delete
$cache->delete('user:123');

// Clear all cache
$cache->clear();
```

### 3. Batch Operations

```php
// Get multiple values
$values = $cache->getMultiple(['user:1', 'user:2', 'user:3']);

// Set multiple values
$cache->setMultiple([
    'user:1' => $user1Data,
    'user:2' => $user2Data,
    'user:3' => $user3Data
], 3600);

// Delete multiple
$cache->deleteMultiple(['user:1', 'user:2', 'user:3']);
```

## Advanced Features

### Remember Pattern (Cache-or-Compute)

```php
// Cache expensive operations automatically
$queryResult = $cache->remember('expensive_query', 3600, function() {
    return expensiveOperation();
});

// First call: executes callback and caches result
// Subsequent calls: returns cached value
```

### Atomic Operations

```php
// Increment/decrement counters
$cache->increment('page_views', 1);  // Returns new value
$cache->decrement('stock_count', 5); // Returns new value

// Add only if not exists
if ($cache->add('user:123', $userData, 3600)) {
    echo "User cached for first time!";
}

// Get and delete in one operation
$value = $cache->pull('temporary_token'); // Returns value and deletes
```

### Permanent Storage

```php
// Store without expiration
$cache->forever('app_config', $configData);
```

## Multi-Store Support

Switch between different cache stores dynamically:

```php
// Use Redis for sessions
$sessionCache = $cache->store('redis');
$sessionCache->set('session:abc123', $sessionData, 1800);

// Use file cache for API responses
$apiCache = $cache->store('file');
$apiCache->set('api:weather', $weatherData, 300);

// Use array cache for request-scoped data
$requestCache = $cache->store('array');
$requestCache->set('current_user', $user);
```

## Cache Tagging

Group related cache entries for bulk operations:

```php
// Tag cache entries
$cache->tags(['users', 'profiles'])->set('user:123:profile', $profileData, 3600);
$cache->tags(['users', 'posts'])->set('user:123:posts', $postsData, 3600);

// Clear all user-related cache
$cache->tags(['users'])->clear();

// Tags work with all operations
$cache->tags(['api', 'v1'])->remember('endpoint:data', 300, function() {
    return fetchApiData();
});
```

## Prefix Management

The bridge supports prefix customization for namespace isolation:

```php
// Create bridge with custom prefix
$dbCache = new CacheManagerBridge($cacheManager, 'database:');
$dbCache->set('query:123', $result); // Stored as 'database:query:123'

// No prefix (uses underlying cache manager's prefix only)
$cache = new CacheManagerBridge($cacheManager);
$cache->set('key', 'value'); // Stored as 'key' (or manager's prefix + 'key')

// Get configured prefix
echo $dbCache->getPrefix(); // 'database:
```

### Clear by Prefix

```php
// Clear all database query cache
$cache->clearByPrefix('query:'); // Clears 'db:query:*'

// Note: Support depends on underlying store
// - File store: Direct prefix clearing
// - Redis/Tagged stores: Uses tagging
// - Others: May not support prefix clearing
```

## Connection Health

```php
// Check if cache is operational
if ($cache->isConnected()) {
    echo "Cache is working!";
} else {
    echo "Cache unavailable, falling back...";
}
```

## Cache Statistics

```php
// Get cache statistics
$stats = $cache->getStatistics();

// Available stats depend on the underlying store:
// - driver: Cache driver class name
// - prefix: Configured prefix
// - connected: Connection status
// - hits/misses: Performance metrics (if supported)
// - redis_version: Redis info (if using Redis)
```

## Available Cache Stores

The underlying `monkeyslegion-cache` package provides multiple store drivers:

### File Store (Recommended for Single-Server)

```php
'stores' => [
    'file' => [
        'driver' => 'file',
        'path' => '/var/cache/app',
        'prefix' => 'app_'
    ]
]
```

**Features:**

- ✅ Persistent storage
- ✅ No external dependencies
- ✅ Automatic cleanup
- ✅ Lock-based concurrency
- ✅ Prefix-based clearing

### Redis Store (Recommended for Distributed)

```php
'stores' => [
    'redis' => [
        'driver' => 'redis',
        'host' => 'localhost',
        'port' => 6379,
        'password' => null,
        'database' => 0,
        'prefix' => 'app_'
    ]
]
```

**Features:**

- ✅ Distributed caching
- ✅ Atomic operations
- ✅ High performance
- ✅ Cache tagging support
- ✅ Pub/sub capabilities

### Array Store (Development/Testing)

```php
'stores' => [
    'array' => [
        'driver' => 'array',
        'prefix' => 'test_'
    ]
]
```

**Features:**

- ✅ In-memory (fast)
- ✅ No setup required
- ✅ Request-scoped
- ❌ Not persistent
- ❌ Not shared across processes

### Memcached Store

```php
'stores' => [
    'memcached' => [
        'driver' => 'memcached',
        'servers' => [
            ['host' => '127.0.0.1', 'port' => 11211, 'weight' => 100]
        ],
        'prefix' => 'app_'
    ]
]
```

## Bridge-Specific Methods

The `CacheManagerBridge` provides additional methods:

```php
// Get the underlying cache manager for advanced operations
$manager = $cache->getCacheManager();
$manager->purge(); // Use any CacheManager method

// Get configured prefix
$prefix = $cache->getPrefix();
```

### Production Cache Configuration

```php
// config/cache.php
return [
    'default' => env('CACHE_DRIVER', 'file'),

    'stores' => [
        'file' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache/data'),
            'prefix' => 'app_cache'
        ],

        'redis' => [
            'driver' => 'redis',
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => env('REDIS_PORT', 6379),
            'password' => env('REDIS_PASSWORD'),
            'database' => env('REDIS_DB', 0),
            'prefix' => 'app_cache'
        ],

        'array' => [
            'driver' => 'array',
            'prefix' => 'test_'
        ],

        'memcached' => [
            'driver' => 'memcached',
            'persistent_id' => env('MEMCACHED_PERSISTENT_ID'),
            'servers' => [
                [
                    'host' => env('MEMCACHED_HOST', '127.0.0.1'),
                    'port' => env('MEMCACHED_PORT', 11211),
                    'weight' => 100,
                ],
            ],
            'prefix' => 'app_cache'
        ],
    ],
];
```

### Usage in Your Application

```php
use MonkeysLegion\Cache\CacheManager;
use MonkeysLegion\Database\Cache\CacheManagerBridge;

// Load configuration
$config = require 'config/cache.php';

// Create cache manager
$cacheManager = new CacheManager($config);

// Create bridge for database-specific caching
$dbCache = new CacheManagerBridge($cacheManager, 'db:');

// Use in your application
$queryResult = $dbCache->remember('heavy_query', 3600, function() {
    return executeExpensiveQuery();
});
```

For more details on cache stores and advanced features, see the [MonkeysLegion Cache](https://github.com/monkeyscloud/monkeyslegion-cache) package documentation.

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
