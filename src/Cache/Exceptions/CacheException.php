<?php

declare(strict_types=1);

namespace MonkeysLegion\Database\Cache\Exceptions;

use Psr\Cache\CacheException as PsrCacheException;

/**
 * General exception for cache-related errors.
 */
class CacheException extends \Exception implements PsrCacheException
{
    // No additional methods needed for now.
}
