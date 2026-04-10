<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Exceptions;

use MonkeysLegion\Database\Types\DatabaseDriver;

/**
 * Schema-related errors (tables, columns not found).
 */
class SchemaException extends DatabaseException
{
    /** The schema/database where the error occurred. */
    public private(set) ?string $schema;

    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        ?DatabaseDriver $driver = null,
        ?string $schema = null,
    ) {
        parent::__construct($message, $code, $previous, $driver);
        $this->schema = $schema;
    }
}
