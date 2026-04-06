<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Exceptions;

use MonkeysLegion\Database\Types\DatabaseDriver;

/**
 * The referenced table does not exist in the database.
 */
class TableNotFoundException extends SchemaException
{
    /** The table name that was not found. */
    public private(set) string $tableName;

    public function __construct(
        string $tableName,
        ?DatabaseDriver $driver = null,
        ?string $schema = null,
        ?\Throwable $previous = null,
    ) {
        $qualified = $schema ? "{$schema}.{$tableName}" : $tableName;
        parent::__construct(
            message: "Table '{$qualified}' does not exist",
            previous: $previous,
            driver: $driver,
            schema: $schema,
        );
        $this->tableName = $tableName;
    }

    /**
     * Fully qualified table reference including schema.
     */
    public string $qualifiedName {
        get => $this->schema
            ? "{$this->schema}.{$this->tableName}"
            : $this->tableName;
    }
}
