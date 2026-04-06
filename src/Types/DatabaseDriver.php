<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Types;

/**
 * MonkeysLegion Framework — Database Package
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
enum DatabaseDriver: string
{
    case MySQL      = 'mysql';
    case PostgreSQL = 'pgsql';
    case SQLite     = 'sqlite';

    // ── Computed Methods ────────────────────────────────────────

    /**
     * PDO driver name used in DSN and getAttribute(ATTR_DRIVER_NAME).
     */
    public function pdoDriver(): string
    {
        return $this->value;
    }

    /**
     * Human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::MySQL      => 'MySQL / MariaDB',
            self::PostgreSQL => 'PostgreSQL',
            self::SQLite     => 'SQLite',
        };
    }

    /**
     * All recognized string aliases for this driver.
     *
     * @return list<string>
     */
    public function aliases(): array
    {
        return match ($this) {
            self::MySQL      => ['mysql', 'mariadb'],
            self::PostgreSQL => ['pgsql', 'postgres', 'postgresql'],
            self::SQLite     => ['sqlite', 'sqlite3'],
        };
    }

    /**
     * Required PHP extension name.
     */
    public function requiredExtension(): string
    {
        return match ($this) {
            self::MySQL      => 'pdo_mysql',
            self::PostgreSQL => 'pdo_pgsql',
            self::SQLite     => 'pdo_sqlite',
        };
    }

    /**
     * Default port number (null for SQLite).
     */
    public function defaultPort(): ?int
    {
        return match ($this) {
            self::MySQL      => 3306,
            self::PostgreSQL => 5432,
            self::SQLite     => null,
        };
    }

    /**
     * Health-check SQL appropriate for the driver.
     */
    public function healthCheckSql(): string
    {
        return 'SELECT 1';
    }

    // ── Factory Methods ─────────────────────────────────────────

    /**
     * Resolve a driver from any recognized alias string.
     *
     * PHP 8.4 — uses `array_find()` to replace the imperative foreach.
     *
     * @throws \InvalidArgumentException If the type is not recognized
     */
    public static function fromString(string $type): self
    {
        $normalized = strtolower(trim($type));

        // PHP 8.4 array_find() — returns the first matching case or null.
        $found = array_find(
            self::cases(),
            static fn(self $case): bool =>
                $normalized === $case->value || in_array($normalized, $case->aliases(), true),
        );

        if ($found !== null) {
            return $found;
        }

        throw new \InvalidArgumentException("Unsupported database driver: {$type}");
    }

    // ── Detection Helpers ───────────────────────────────────────

    /**
     * Detect whether the connected server is MariaDB (MySQL-compatible).
     */
    public function isMariaDb(\PDO $pdo): bool
    {
        if ($this !== self::MySQL) {
            return false;
        }

        try {
            $version = $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);
            return is_string($version) && str_contains(strtolower($version), 'mariadb');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Check whether the required PHP extension is loaded.
     */
    public function isExtensionLoaded(): bool
    {
        return extension_loaded($this->requiredExtension());
    }
}
