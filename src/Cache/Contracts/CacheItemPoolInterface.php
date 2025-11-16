<?php

declare(strict_types=1);

namespace MonkeysLegion\Database\Cache\Contracts;

use MonkeysLegion\Database\Cache\Items\CacheItem;
use Psr\Cache\CacheItemPoolInterface as PsrCacheItemPoolInterface;

interface CacheItemPoolInterface extends PsrCacheItemPoolInterface
{
    public function getItem(string $key): CacheItem;

    /**
     * @param array<string> $keys
     * @return iterable<string, \Psr\Cache\CacheItemInterface>
     */
    public function getItems(array $keys = []): iterable;

    public function hasItem(string $key): bool;

    public function save(\Psr\Cache\CacheItemInterface $item): bool;

    public function saveDeferred(\Psr\Cache\CacheItemInterface $item): bool;

    public function commit(): bool;

    public function deleteItem(string $key): bool;

    /**
     * @param array<string> $keys
     */
    public function deleteItems(array $keys): bool;

    public function clear(): bool;

    public function getStatistics(): array;

    public function resetStatistics(): void;

    public function getHitRatio(): float;
}
