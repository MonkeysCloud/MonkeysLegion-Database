<?php

declare(strict_types=1);

namespace MonkeysLegion\Database\Factory;

use MonkeysLegion\Database\Cache\Adapters\ArrayCacheAdapter;
use MonkeysLegion\Database\Cache\Adapters\FileSystemAdapter;
use MonkeysLegion\Database\Cache\Adapters\RedisCacheAdapter;
use MonkeysLegion\Database\Cache\Contracts\CacheItemPoolInterface;
use MonkeysLegion\Database\Cache\Enum\Constants;
use MonkeysLegion\Database\Types\CacheType;

final class CacheFactory
{
    /**
     * @param array<string, mixed> $config
     */
    public static function create(array $config): CacheItemPoolInterface
    {
        if (!isset($config['default'])) {
            throw new \InvalidArgumentException('No default connection configuration found.');
        }

        return self::createByType($config['default'], $config);
    }

    /**
     * @param string $type
     * @param array<string, mixed> $config
     */
    public static function createByType(string $type, array $config): CacheItemPoolInterface
    {
        $cacheType = CacheType::fromString($type);
        return self::createByEnum($cacheType, $config);
    }

    /**
     * @param CacheType $type
     * @param array<string, mixed> $config
     */
    public static function createByEnum(CacheType $type, array $config): CacheItemPoolInterface
    {
        $cacheClass = $type->getCacheClass();

        $connectionConfig = $config['drivers'][$type->value]
            ?? throw new \InvalidArgumentException("Missing config for connection type '{$type->value}'");

        return self::build($cacheClass, $connectionConfig);
    }

    /**
     * @param string $cacheClass
     * @param array<string, mixed> $config
     */
    private static function build(string $cacheClass, array $config): CacheItemPoolInterface
    {
        $instance = match ($cacheClass) {
            FileSystemAdapter::class => new FileSystemAdapter(
                $config['file'] ?? null,
                $config['auto_cleanup'] ?? true,
                $config['cleanup_probability'] ?? Constants::CACHE_CLEANUP_PROBABILITY,
                $config['cleanup_interval'] ?? Constants::CACHE_CLEANUP_INTERVAL
            ),

            ArrayCacheAdapter::class => new ArrayCacheAdapter(),

            RedisCacheAdapter::class => (function () use ($config) {
                $redisClient = new \Redis();
                $redisClient->connect(
                    $config['host'] ?? '127.0.0.1',
                    $config['port'] ?? 6379,
                    $config['timeout'] ?? 2.0
                );

                if (!empty($config['password'])) {
                    $redisClient->auth($config['password']);
                }

                if (isset($config['database'])) {
                    $redisClient->select((int)$config['database']);
                }

                if (!empty($config['prefix'])) {
                    $redisClient->setOption(\Redis::OPT_PREFIX, $config['prefix']);
                }

                return new RedisCacheAdapter($redisClient);
            })(),

            default => throw new \InvalidArgumentException("Unsupported cache adapter: {$cacheClass}"),
        };

        return $instance;
    }
}
