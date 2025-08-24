<?php

declare(strict_types=1);

namespace MonkeysLegion\Database\Types;

use MonkeysLegion\Database\Cache\Adapters\ArrayCacheAdapter;
use MonkeysLegion\Database\Cache\Adapters\FileSystemAdapter;
use MonkeysLegion\Database\Cache\Adapters\RedisCacheAdapter;

enum CacheType: string
{
    case FILE = 'file';
    case REDIS = 'redis';
    case MEMORY = 'memcached';

    public static function fromString(string $type): self
    {
        return match (strtolower($type)) {
            self::FILE->value => self::FILE,
            self::REDIS->value => self::REDIS,
            self::MEMORY->value => self::MEMORY,
            default => throw new \InvalidArgumentException("Unsupported cache type: {$type}")
        };
    }

    public function getConfigKey(): string
    {
        return $this->value;
    }

    public function getCacheClass(): string
    {
        return match ($this) {
            self::FILE => FileSystemAdapter::class,
            self::REDIS => RedisCacheAdapter::class,
            self::MEMORY => ArrayCacheAdapter::class,
        };
    }
}
