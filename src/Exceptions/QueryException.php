<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Exceptions;

use MonkeysLegion\Database\Types\DatabaseDriver;

/**
 * Errors that occur during SQL query execution.
 *
 * Carries the SQL statement and bound parameters for debugging.
 */
class QueryException extends DatabaseException
{
    public private(set) string $sql;

    /** @var array<string, mixed> */
    public private(set) array $params;

    public private(set) ?string $sqlState;

    public private(set) ?int $driverErrorCode;

    /**
     * SQL with parameters interpolated (for debugging only — never log in production).
     */
    public string $debugSql {
        get {
            $result = $this->sql;
            foreach ($this->params as $key => $value) {
                $quoted = is_numeric($value)
                    ? (string) $value
                    : "'" . addslashes((string) $value) . "'";
                $result = str_replace($key, $quoted, $result);
            }
            return $result;
        }
    }

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
    ) {
        parent::__construct($message, $code, $previous, $driver);
        $this->sql = $sql;
        $this->params = $params;
        $this->sqlState = $sqlState;
        $this->driverErrorCode = $driverErrorCode;
    }
}
