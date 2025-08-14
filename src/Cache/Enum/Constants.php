<?php

declare(strict_types=1);

namespace MonkeysLegion\Database\Cache\Enum;

class Constants
{
    /** Cache item filename prefix */
    public const CACHE_ITEM_PREFIX = 'cache_item_';

    /** Cache item file extension */
    public const CACHE_ITEM_SUFFIX = '.cache';

    /** Lock file extension */
    public const CACHE_LOCK_SUFFIX = '.lock';

    /** Default cache item expiration time in seconds (1 hour) */
    public const CACHE_ITEM_EXPIRATION = 3600;

    /** Lock file expiration time in seconds (5 minutes) */
    public const CACHE_LOCK_EXPIRATION = 300;

    /**
     * Regex pattern to detect invalid cache key characters.
     * Disallows: { } ( ) / \ @ : whitespace and control characters.
     */
    public const CACHE_KEY_VALIDATION_PATTERN = '#[{}\(\)/@:\\\\\s\x00-\x1F\x7F]#';

    /** Default cache TTL (Time To Live) in seconds (1 hour) */
    public const CACHE_DEFAULT_TTL = 3600;
}
