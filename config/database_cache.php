<?php

/**
 * Database Cache Configuration
 * 
 * This configuration integrates MonkeysLegion-Cache package into the Database package.
 * All cache drivers from the Cache package are available with their full feature set.
 * 
 * Features available:
 * - Multiple drivers: File, Redis, Memcached, Array
 * - Cache tagging for grouped operations
 * - Atomic operations (increment/decrement)
 * - Remember pattern for query caching
 * - PSR-16 compliance
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Default Cache Store
    |--------------------------------------------------------------------------
    |
    | This option controls the default cache store that will be used by the
    | database package. You may use any of the stores defined in the "stores"
    | array below.
    |
    | Supported: "file", "redis", "memcached", "array"
    |
    */
    'default' => env('DB_CACHE_DRIVER', 'file'),

    /*
    |--------------------------------------------------------------------------
    | Cache Stores
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the cache "stores" for your application as
    | well as their drivers. You may even define multiple stores for the
    | same cache driver to group types of items stored in your caches.
    |
    */
    'stores' => [
        
        /*
        |----------------------------------------------------------------------
        | File Cache Store
        |----------------------------------------------------------------------
        |
        | The file driver stores cache on the filesystem. This is great for
        | development and small applications. For production, consider using
        | Redis or Memcached for better performance.
        |
        */
        'file' => [
            'driver' => 'file',
            'path' => env('DB_CACHE_PATH', storage_path('framework/cache/database')),
            'prefix' => env('DB_CACHE_PREFIX', 'db_'),
        ],

        /*
        |----------------------------------------------------------------------
        | Redis Cache Store
        |----------------------------------------------------------------------
        |
        | Redis is an advanced key-value store that offers excellent performance
        | for caching. It's perfect for production environments and distributed
        | applications.
        |
        */
        'redis' => [
            'driver' => 'redis',
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => env('REDIS_PORT', 6379),
            'password' => env('REDIS_PASSWORD', null),
            'database' => env('REDIS_CACHE_DB', 1),
            'prefix' => env('DB_CACHE_PREFIX', 'db_'),
            'timeout' => 2.0,
        ],

        /*
        |----------------------------------------------------------------------
        | Memcached Cache Store
        |----------------------------------------------------------------------
        |
        | Memcached is a distributed memory object caching system. It's
        | suitable for high-traffic websites and distributed architectures.
        |
        */
        'memcached' => [
            'driver' => 'memcached',
            'persistent_id' => env('MEMCACHED_PERSISTENT_ID', 'db_cache'),
            'prefix' => env('DB_CACHE_PREFIX', 'db_'),
            'servers' => [
                [
                    'host' => env('MEMCACHED_HOST', '127.0.0.1'),
                    'port' => env('MEMCACHED_PORT', 11211),
                    'weight' => 100,
                ],
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Array Cache Store
        |----------------------------------------------------------------------
        |
        | The array driver stores everything in memory for the duration of a
        | single request. This is useful for testing and development.
        |
        */
        'array' => [
            'driver' => 'array',
            'prefix' => 'db_',
        ],

        /*
        |----------------------------------------------------------------------
        | Query Cache Store (Example)
        |----------------------------------------------------------------------
        |
        | You can define specialized stores for different caching needs.
        | This example shows a dedicated store for query result caching.
        |
        */
        'query' => [
            'driver' => 'redis',
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => env('REDIS_PORT', 6379),
            'password' => env('REDIS_PASSWORD', null),
            'database' => env('REDIS_QUERY_CACHE_DB', 2),
            'prefix' => 'query_',
            'timeout' => 2.0,
        ],

        /*
        |----------------------------------------------------------------------
        | Session Cache Store (Example)
        |----------------------------------------------------------------------
        |
        | Another example of a specialized store for session data caching.
        |
        */
        'session' => [
            'driver' => 'redis',
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => env('REDIS_PORT', 6379),
            'password' => env('REDIS_PASSWORD', null),
            'database' => env('REDIS_SESSION_CACHE_DB', 3),
            'prefix' => 'session_',
            'timeout' => 2.0,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Key Prefix
    |--------------------------------------------------------------------------
    |
    | When utilizing a RAM based store such as APC or Memcached, there might
    | be other applications utilizing the same cache. So, we'll specify a
    | value to prefix all of our items to avoid collisions.
    |
    */
    'prefix' => env('DB_CACHE_PREFIX', 'monkeyslegion_database'),

    /*
    |--------------------------------------------------------------------------
    | Query Cache TTL (Time To Live)
    |--------------------------------------------------------------------------
    |
    | Default time-to-live for cached query results in seconds.
    | Set to null to cache forever, or 0 to disable query caching.
    |
    */
    'query_ttl' => env('DB_QUERY_CACHE_TTL', 3600), // 1 hour

    /*
    |--------------------------------------------------------------------------
    | Cache Statistics
    |--------------------------------------------------------------------------
    |
    | Enable cache statistics collection for monitoring and debugging.
    | This may have a small performance impact in production.
    |
    */
    'statistics' => env('DB_CACHE_STATISTICS', false),
];
