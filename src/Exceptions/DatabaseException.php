<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Exceptions;

use MonkeysLegion\Database\Types\DatabaseDriver;

/**
 * MonkeysLegion Framework — Database Package
 *
 * Base exception for all database-related errors.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
class DatabaseException extends \RuntimeException
{
    public private(set) ?DatabaseDriver $driver;

    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        ?DatabaseDriver $driver = null,
    ) {
        parent::__construct($message, $code, $previous);
        $this->driver = $driver;
    }
}
