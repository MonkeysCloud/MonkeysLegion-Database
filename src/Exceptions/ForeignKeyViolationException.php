<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Exceptions;

use MonkeysLegion\Database\Types\DatabaseDriver;

/**
 * A foreign key constraint was violated.
 */
class ForeignKeyViolationException extends QueryException
{
    /** The FK constraint name (e.g. "orders_user_id_fk"). */
    public private(set) ?string $constraintName;

    /** The referencing column (e.g. "user_id"). */
    public private(set) ?string $referencingColumn;

    /** The referenced table (e.g. "users"). */
    public private(set) ?string $referencedTable;

    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        ?DatabaseDriver $driver = null,
        string $sql = '',
        array $params = [],
        ?string $sqlState = null,
        ?int $driverErrorCode = null,
        ?string $constraintName = null,
        ?string $referencingColumn = null,
        ?string $referencedTable = null,
    ) {
        parent::__construct($message, $code, $previous, $driver, $sql, $params, $sqlState, $driverErrorCode);
        $this->constraintName = $constraintName;
        $this->referencingColumn = $referencingColumn;
        $this->referencedTable = $referencedTable;
    }

    /**
     * Quick check: is this a "parent row not found" (insert/update) or
     * "child row exists" (delete) violation?
     */
    public bool $isParentMissing {
        get => str_contains($this->getMessage(), 'a foreign key constraint fails')
            || str_contains($this->getMessage(), 'not present in table')
            || str_contains($this->getMessage(), 'FOREIGN KEY');
    }
}
