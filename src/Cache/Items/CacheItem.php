<?php

declare(strict_types=1);

namespace MonkeysLegion\Database\Cache\Items;

use MonkeysLegion\Database\Cache\Contracts\CacheItemInterface;

class CacheItem implements CacheItemInterface
{
    private string $key;
    private mixed $value = null;
    private bool $hit = false;
    private ?\DateTimeInterface $expiration = null;

    public function __construct(string $key)
    {
        $this->key = $key;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function get(): mixed
    {
        return $this->value;
    }

    public function isHit(): bool
    {
        return $this->hit;
    }

    public function set(mixed $value): static
    {
        $this->value = $value;
        return $this;
    }

    public function expiresAt(?\DateTimeInterface $expiration): static
    {
        $this->expiration = $expiration;
        return $this;
    }

    public function expiresAfter(\DateInterval|int|null $time): static
    {
        if ($time === null) {
            $this->expiration = null;
        } elseif ($time instanceof \DateInterval) {
            $this->expiration = (new \DateTimeImmutable())->add($time);
        } else {
            $this->expiration = (new \DateTimeImmutable())->modify("+{$time} seconds");
        }
        return $this;
    }

    /**
     * Set the hit status of the cache item.
     */
    public function setHit(bool $hit): static
    {
        $this->hit = $hit;
        return $this;
    }

    /**
     * Get the expiration time of the cache item.
     */
    public function getExpiration(): ?\DateTimeInterface
    {
        return $this->expiration;
    }

    /**
     * Determine if the cache item is expired.
     */
    public function isExpired(): bool
    {
        return $this->expiration !== null && $this->expiration <= new \DateTimeImmutable();
    }
}
