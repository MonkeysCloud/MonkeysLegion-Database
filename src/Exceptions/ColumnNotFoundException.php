<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Exceptions;

use MonkeysLegion\Database\Types\DatabaseDriver;

/**
 * The referenced column does not exist in the table.
 */
class ColumnNotFoundException extends SchemaException
{
    /** The column name that was not found. */
    public private(set) string $columnName;

    /** The table where the column was expected. */
    public private(set) ?string $tableName;

    public function __construct(
        string $columnName,
        ?string $tableName = null,
        ?DatabaseDriver $driver = null,
        ?string $schema = null,
        ?\Throwable $previous = null,
    ) {
        $context = $tableName ? " in table '{$tableName}'" : '';
        parent::__construct(
            message: "Column '{$columnName}' not found{$context}",
            previous: $previous,
            driver: $driver,
            schema: $schema,
        );
        $this->columnName = $columnName;
        $this->tableName = $tableName;
    }

    /**
     * Fully qualified column reference (table.column).
     */
    public string $qualifiedName {
        get => $this->tableName
            ? "{$this->tableName}.{$this->columnName}"
            : $this->columnName;
    }
}
