<?php
declare(strict_types=1);

namespace MonkeysLegion\Database\Types;

/**
 * MonkeysLegion Framework — Database Package
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
enum ReadReplicaStrategy: string
{
    case RoundRobin       = 'round_robin';
    case Random           = 'random';
    case LeastConnections = 'least_connections';

    /**
     * Human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::RoundRobin       => 'Round Robin',
            self::Random           => 'Random',
            self::LeastConnections => 'Least Connections',
        };
    }
}
