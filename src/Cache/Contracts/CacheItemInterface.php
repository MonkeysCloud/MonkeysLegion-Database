<?php

declare(strict_types=1);

namespace MonkeysLegion\Database\Cache\Contracts;

use Psr\Cache\CacheItemInterface as PsrCacheItemInterface;

interface CacheItemInterface extends PsrCacheItemInterface
{
    /**
     * Determine if the cache item is expired.
     */
    public function isExpired(): bool;

    /**
     * Get the expiration timestamp of the cache item.
     */
    public function getExpiration(): ?\DateTimeInterface;

    /**
     * Set the hit status of the cache item.
     */
    public function setHit(bool $hit): static;

    /**
     * Set the value of the cache item.
     */
    public function set(mixed $value): static;

    /**
     * Set the expiration time of the cache item.
     */
    public function expiresAt(?\DateTimeInterface $expiration): static;

    /**
     * Set the expiration time of the cache item relative to now.
     */
    public function expiresAfter(\DateInterval|int|null $time): static;
}
