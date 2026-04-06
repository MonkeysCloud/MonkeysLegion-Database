<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Types;

/**
 * MonkeysLegion Framework — Database Package
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
enum IsolationLevel: string
{
    case ReadUncommitted = 'READ UNCOMMITTED';
    case ReadCommitted   = 'READ COMMITTED';
    case RepeatableRead  = 'REPEATABLE READ';
    case Serializable    = 'SERIALIZABLE';

    /**
     * Human-readable short label.
     */
    public function label(): string
    {
        return match ($this) {
            self::ReadUncommitted => 'Read Uncommitted',
            self::ReadCommitted   => 'Read Committed',
            self::RepeatableRead  => 'Repeatable Read',
            self::Serializable    => 'Serializable',
        };
    }
}
