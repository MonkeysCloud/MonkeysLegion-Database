<?php

declare(strict_types=1);

namespace MonkeysLegion\Database\Cache\Utils;

use MonkeysLegion\Database\Cache\Enum\Constants;
use MonkeysLegion\Database\Cache\Exceptions\InvalidArgumentException;

class CacheKeyValidator
{
    /**
     * Validate a cache key against the validation pattern
     *
     * @throws InvalidArgumentException
     */
    public static function validateKey(string $key): void
    {
        if (preg_match(Constants::CACHE_KEY_VALIDATION_PATTERN, $key)) {
            throw new InvalidArgumentException(
                sprintf('Cache key "%s" contains invalid characters', $key)
            );
        }
    }

    /**
     * Check if a cache key is valid
     */
    public static function isValidKey(string $key): bool
    {
        return !preg_match(Constants::CACHE_KEY_VALIDATION_PATTERN, $key);
    }
}
