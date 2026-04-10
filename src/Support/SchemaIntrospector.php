<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Support;

use MonkeysLegion\Database\Contracts\ConnectionInterface;
use MonkeysLegion\Database\Types\DatabaseDriver;
use PDO;

/**
 * MonkeysLegion Framework — Database Package
 *
 * Schema introspection utilities with aggressive static caching.
 * Centralizes table/column/FK/unique-index discovery that was previously
 * scattered across EntityRepository.
 *
 * All results are cached per-connection (keyed by driver + PDO object ID + schema)
 * for maximum performance in long-running workers.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class SchemaIntrospector
{
    // ── Static Caches ───────────────────────────────────────────


    /** @var array<string, string> pdoId → schema/database name */
    private static array $schemaCache = [];

    /** @var array<string, list<string>> cacheKey → table names */
    private static array $tablesCache = [];

    /** @var array<string, list<string>> cacheKey → column names */
    private static array $columnsCache = [];

    /** @var array<string, array<string, array{constraint_name: string, ref_schema: string, ref_table: string, ref_col: string}>> */
    private static array $fkCache = [];

    /** @var array<string, list<array{name: string, columns: list<string>, is_primary: bool}>> */
    private static array $uniqueCache = [];

    /** @var array<string, bool> cacheKey → column existence */
    private static array $columnExistsCache = [];

    /**
     * Clear all static schema caches. Call after running migrations.
     */
    public static function clearCache(): void
    {
        self::$schemaCache = [];
        self::$tablesCache = [];
        self::$columnsCache = [];
        self::$fkCache = [];
        self::$uniqueCache = [];
        self::$columnExistsCache = [];
    }

    // ── Driver & Schema Detection ───────────────────────────────

    /**
     * Get driver name for a connection (cached).
     */
    public static function detectDriver(ConnectionInterface $conn): DatabaseDriver
    {
        return $conn->getDriver();
    }

    /**
     * Get the current schema/database name (cached per PDO instance).
     */
    public static function detectSchema(ConnectionInterface $conn): string
    {
        $pdo = $conn->pdo();
        $key = (string) spl_object_id($pdo);

        if (isset(self::$schemaCache[$key])) {
            return self::$schemaCache[$key];
        }

        $driver = $conn->getDriver();

        return self::$schemaCache[$key] = match ($driver) {
            DatabaseDriver::SQLite => 'main',
            DatabaseDriver::PostgreSQL => (string) ($pdo->query('SELECT current_database()')?->fetchColumn() ?: 'public'),
            DatabaseDriver::MySQL => (function () use ($pdo, $driver): string {
                $schema = (string) ($pdo->query('SELECT DATABASE()')?->fetchColumn() ?: '');
                if ($schema === '') {
                    throw new \RuntimeException(
                        "Unable to determine database/schema for driver '{$driver->label()}'. "
                        . 'Ensure a database is selected.'
                    );
                }
                return $schema;
            })(),
        };
    }

    // ── Table Discovery ─────────────────────────────────────────

    /**
     * List all table names in the current schema (cached).
     *
     * @return list<string>
     */
    public static function listTables(ConnectionInterface $conn): array
    {
        $pdo = $conn->pdo();
        $driver = $conn->getDriver();
        $schema = self::detectSchema($conn);
        $cacheKey = $driver->value . ':' . spl_object_id($pdo) . ':' . $schema;

        if (isset(self::$tablesCache[$cacheKey])) {
            return self::$tablesCache[$cacheKey];
        }

        return self::$tablesCache[$cacheKey] = match ($driver) {
            DatabaseDriver::SQLite => array_map(
                static fn(array $r): string => $r['name'],
                $pdo->query(
                    "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"
                )?->fetchAll(PDO::FETCH_ASSOC) ?: [],
            ),
            DatabaseDriver::PostgreSQL => (function () use ($pdo): array {
                $st = $pdo->prepare(
                    "SELECT table_name FROM information_schema.tables "
                    . "WHERE table_catalog = current_database() AND table_schema = 'public'"
                );
                $st->execute();
                return array_map(
                    static fn(array $r): string => $r['table_name'],
                    $st->fetchAll(PDO::FETCH_ASSOC),
                );
            })(),
            DatabaseDriver::MySQL => (function () use ($pdo, $schema): array {
                $st = $pdo->prepare(
                    'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = :s'
                );
                $st->execute([':s' => $schema]);
                return array_map(
                    static fn(array $r): string => $r['TABLE_NAME'],
                    $st->fetchAll(PDO::FETCH_ASSOC),
                );
            })(),
        };
    }

    /**
     * Check if a table exists (case-insensitive, underscore-tolerant).
     *
     * PHP 8.4 — uses `array_any()` to replace both imperative foreach loops.
     */
    public static function tableExists(ConnectionInterface $conn, string $table): bool
    {
        $tables = self::listTables($conn);
        $needle = strtolower($table);

        // Exact case-insensitive match
        if (array_any($tables, static fn(string $t): bool => strtolower($t) === $needle)) {
            return true;
        }

        // Underscore-folded match (e.g. "order_items" matches "orderitems")
        $needleCompact = str_replace('_', '', $needle);
        return array_any(
            $tables,
            static fn(string $t): bool => str_replace('_', '', strtolower($t)) === $needleCompact,
        );
    }

    /**
     * Resolve the actual table name (handles case/underscore differences).
     *
     * PHP 8.4 — uses `array_find()` to replace both imperative foreach loops.
     */
    public static function resolveTableName(ConnectionInterface $conn, string $name): ?string
    {
        $tables = self::listTables($conn);
        $needle = strtolower($name);

        // Exact case-insensitive match
        $found = array_find($tables, static fn(string $t): bool => strtolower($t) === $needle);
        if ($found !== null) {
            return $found;
        }

        // Underscore-folded match
        $needleCompact = str_replace('_', '', $needle);
        return array_find(
            $tables,
            static fn(string $t): bool => str_replace('_', '', strtolower($t)) === $needleCompact,
        );
    }

    // ── Column Discovery ────────────────────────────────────────

    /**
     * List all columns in a table (cached).
     *
     * @return list<string>
     */
    public static function listColumns(ConnectionInterface $conn, string $table): array
    {
        $pdo = $conn->pdo();
        $driver = $conn->getDriver();
        $schema = self::detectSchema($conn);
        $cacheKey = $driver->value . ':' . spl_object_id($pdo) . ':' . $schema . '.' . $table;

        if (isset(self::$columnsCache[$cacheKey])) {
            return self::$columnsCache[$cacheKey];
        }

        return self::$columnsCache[$cacheKey] = match ($driver) {
            DatabaseDriver::SQLite => (function () use ($pdo, $table): array {
                $safeTable = preg_replace('/[^A-Za-z0-9_]/', '', $table);
                $stmt = $pdo->query("PRAGMA table_info({$safeTable})");
                return array_map(
                    static fn(array $r): string => $r['name'],
                    $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [],
                );
            })(),
            DatabaseDriver::PostgreSQL => (function () use ($pdo, $table): array {
                $st = $pdo->prepare(
                    "SELECT column_name FROM information_schema.columns "
                    . "WHERE table_name = :t AND table_schema = current_schema() "
                    . "AND table_catalog = current_database() ORDER BY ordinal_position"
                );
                $st->execute([':t' => $table]);
                return array_map(
                    static fn(array $r): string => $r['column_name'],
                    $st->fetchAll(PDO::FETCH_ASSOC),
                );
            })(),
            DatabaseDriver::MySQL => (function () use ($pdo, $schema, $table): array {
                $st = $pdo->prepare(
                    'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS '
                    . 'WHERE TABLE_SCHEMA = :s AND TABLE_NAME = :t ORDER BY ORDINAL_POSITION'
                );
                $st->execute([':s' => $schema, ':t' => $table]);
                return array_map(
                    static fn(array $r): string => $r['COLUMN_NAME'],
                    $st->fetchAll(PDO::FETCH_ASSOC),
                );
            })(),
        };
    }

    /**
     * Check if a specific column exists in a table (cached).
     */
    public static function columnExists(
        ConnectionInterface $conn,
        string $table,
        string $column,
        ?string $schema = null,
    ): bool {
        $pdo = $conn->pdo();
        $driver = $conn->getDriver();
        $effectiveSchema = $schema ?: self::detectSchema($conn);
        $key = $driver->value . ':' . spl_object_id($pdo) . ':' . $effectiveSchema . '.' . $table . '.' . $column;

        if (array_key_exists($key, self::$columnExistsCache)) {
            return self::$columnExistsCache[$key];
        }

        $columns = self::listColumns($conn, $table);
        $exists = in_array($column, $columns, true);

        // Also try case-insensitive match
        if (!$exists) {
            $columnLower = strtolower($column);
            foreach ($columns as $col) {
                if (strtolower($col) === $columnLower) {
                    $exists = true;
                    break;
                }
            }
        }

        return self::$columnExistsCache[$key] = $exists;
    }

    // ── Foreign Key Discovery ───────────────────────────────────

    /**
     * Load all foreign key constraints for a table (cached).
     *
     * @return array<string, array{constraint_name: string, ref_schema: string, ref_table: string, ref_col: string}>
     */
    public static function loadForeignKeys(ConnectionInterface $conn, string $table): array
    {
        $pdo = $conn->pdo();
        $driver = $conn->getDriver();
        $schema = self::detectSchema($conn);
        $cacheKey = $driver->value . ':' . spl_object_id($pdo) . ':' . $schema . '.' . $table;

        if (isset(self::$fkCache[$cacheKey])) {
            return self::$fkCache[$cacheKey];
        }

        $out = [];

        match ($driver) {
            DatabaseDriver::SQLite => (function () use ($pdo, $table, $schema, &$out): void {
                try {
                    $st = $pdo->query("PRAGMA foreign_key_list(\"{$table}\")");
                    if ($st) {
                        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                            $out[$r['from']] = [
                                'constraint_name' => "fk_{$table}_{$r['id']}",
                                'ref_schema'      => $schema,
                                'ref_table'       => $r['table'],
                                'ref_col'         => $r['to'] ?: 'id',
                            ];
                        }
                    }
                } catch (\Throwable) {
                }
            })(),
            DatabaseDriver::PostgreSQL => (function () use ($pdo, $table, $schema, &$out): void {
                $sql = "SELECT kcu.column_name, tc.constraint_name,
                               ccu.table_catalog as ref_schema,
                               ccu.table_name as ref_table,
                               ccu.column_name as ref_col
                          FROM information_schema.table_constraints tc
                          JOIN information_schema.key_column_usage kcu
                               ON tc.constraint_name = kcu.constraint_name
                          JOIN information_schema.constraint_column_usage ccu
                               ON ccu.constraint_name = tc.constraint_name
                         WHERE tc.constraint_type = 'FOREIGN KEY'
                           AND tc.table_name = :t";
                $st = $pdo->prepare($sql);
                $st->execute([':t' => $table]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $out[$r['column_name']] = [
                        'constraint_name' => $r['constraint_name'],
                        'ref_schema'      => $r['ref_schema'] ?: $schema,
                        'ref_table'       => $r['ref_table'],
                        'ref_col'         => $r['ref_col'] ?: 'id',
                    ];
                }
            })(),
            DatabaseDriver::MySQL => (function () use ($pdo, $schema, $table, &$out): void {
                $sql = "SELECT COLUMN_NAME, CONSTRAINT_NAME,
                               REFERENCED_TABLE_SCHEMA, REFERENCED_TABLE_NAME,
                               REFERENCED_COLUMN_NAME
                          FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                         WHERE TABLE_SCHEMA = :s
                           AND TABLE_NAME = :t
                           AND REFERENCED_TABLE_NAME IS NOT NULL";
                $st = $pdo->prepare($sql);
                $st->execute([':s' => $schema, ':t' => $table]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $out[$r['COLUMN_NAME']] = [
                        'constraint_name' => $r['CONSTRAINT_NAME'],
                        'ref_schema'      => $r['REFERENCED_TABLE_SCHEMA'] ?: $schema,
                        'ref_table'       => $r['REFERENCED_TABLE_NAME'],
                        'ref_col'         => $r['REFERENCED_COLUMN_NAME'] ?: 'id',
                    ];
                }
            })(),
        };

        return self::$fkCache[$cacheKey] = $out;
    }

    // ── Unique Index Discovery ──────────────────────────────────

    /**
     * Load all unique indexes for a table (cached).
     *
     * @return list<array{name: string, columns: list<string>, is_primary: bool}>
     */
    public static function loadUniqueIndexes(ConnectionInterface $conn, string $table): array
    {
        $pdo = $conn->pdo();
        $driver = $conn->getDriver();
        $schema = self::detectSchema($conn);
        $cacheKey = $driver->value . ':' . spl_object_id($pdo) . ':' . $schema . '.' . $table;

        if (isset(self::$uniqueCache[$cacheKey])) {
            return self::$uniqueCache[$cacheKey];
        }

        $idx = [];

        match ($driver) {
            DatabaseDriver::SQLite => (function () use ($pdo, $table, &$idx): void {
                try {
                    $st = $pdo->query("PRAGMA index_list(\"{$table}\")");
                    if ($st) {
                        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                            if ((int) $r['unique'] !== 1) {
                                continue;
                            }
                            $indexName = $r['name'];
                            $isPrimary = str_starts_with($indexName, 'sqlite_autoindex_')
                                || $indexName === 'PRIMARY';
                            $colSt = $pdo->query("PRAGMA index_info(\"{$indexName}\")");
                            $cols = $colSt
                                ? array_map(
                                    static fn(array $c): string => $c['name'],
                                    $colSt->fetchAll(PDO::FETCH_ASSOC),
                                )
                                : [];
                            if ($cols) {
                                $idx[$indexName] = [
                                    'name'       => $indexName,
                                    'columns'    => $cols,
                                    'is_primary' => $isPrimary,
                                ];
                            }
                        }
                    }
                } catch (\Throwable) {
                }
            })(),
            DatabaseDriver::PostgreSQL => (function () use ($pdo, $table, &$idx): void {
                $sql = "SELECT i.relname as index_name,
                               a.attname as column_name,
                               ix.indisprimary as is_primary
                          FROM pg_catalog.pg_class t
                          JOIN pg_catalog.pg_index ix ON t.oid = ix.indrelid
                          JOIN pg_catalog.pg_class i ON i.oid = ix.indexrelid
                          JOIN pg_catalog.pg_attribute a
                               ON a.attrelid = t.oid AND a.attnum = ANY(ix.indkey)
                         WHERE t.relname = :t AND ix.indisunique = true
                      ORDER BY i.relname, a.attnum";
                $st = $pdo->prepare($sql);
                $st->execute([':t' => $table]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $name = $r['index_name'];
                    if (!isset($idx[$name])) {
                        $idx[$name] = [
                            'name'       => $name,
                            'columns'    => [],
                            'is_primary' => (bool) $r['is_primary'],
                        ];
                    }
                    $idx[$name]['columns'][] = $r['column_name'];
                }
            })(),
            DatabaseDriver::MySQL => (function () use ($pdo, $schema, $table, &$idx): void {
                $sql = "SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE, SEQ_IN_INDEX
                          FROM INFORMATION_SCHEMA.STATISTICS
                         WHERE TABLE_SCHEMA = :s AND TABLE_NAME = :t
                      ORDER BY INDEX_NAME, SEQ_IN_INDEX";
                $st = $pdo->prepare($sql);
                $st->execute([':s' => $schema, ':t' => $table]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    if ((int) $r['NON_UNIQUE'] !== 0) {
                        continue;
                    }
                    $name = $r['INDEX_NAME'];
                    if (!isset($idx[$name])) {
                        $idx[$name] = [
                            'name'       => $name,
                            'columns'    => [],
                            'is_primary' => $name === 'PRIMARY',
                        ];
                    }
                    $idx[$name]['columns'][] = $r['COLUMN_NAME'];
                }
            })(),
        };

        return self::$uniqueCache[$cacheKey] = array_values($idx);
    }
}
