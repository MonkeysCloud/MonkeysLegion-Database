# MonkeysLegion Database v2

High-performance PDO connection management for PHP 8.4+ with read/write splitting, connection pooling, lazy connections, and multi-driver support.

## Features

- ✅ **Multi-Driver**: MySQL, PostgreSQL, SQLite — unified API via `match()` dispatch
- ✅ **Read/Write Splitting**: Route reads to replicas, writes to primary — automatically
- ✅ **Lazy Connections**: PDO is not allocated until the first query (zero cost in CLI/workers)
- ✅ **Connection Pooling**: In-memory pool with health monitoring, idle eviction, max-lifetime enforcement
- ✅ **Sticky-After-Write**: Reads route to primary after a write to avoid replication lag
- ✅ **18 Typed Exceptions**: Every `PDOException` is classified into a specific actionable type
- ✅ **Schema Introspection**: Table/column/FK/unique-index discovery with aggressive static caching
- ✅ **PHP 8.4 Native**: Property hooks, asymmetric visibility, readonly classes, backed enums
- ✅ **Zero Config Defaults**: Sensible PDO defaults (exceptions, assoc fetch, real prepares)
- ✅ **Type-Safe Config**: Immutable value objects replace raw arrays

## Requirements

- PHP 8.4+
- `ext-pdo` (plus `ext-pdo_mysql`, `ext-pdo_pgsql`, or `ext-pdo_sqlite` per driver)

## Installation

```bash
composer require monkeyscloud/monkeyslegion-database:^2.0
```

## Quick Start

### ConnectionManager (Recommended)

```php
use MonkeysLegion\Database\Connection\ConnectionManager;

// From a config array — the simplest setup
$manager = ConnectionManager::fromArray([
    'default' => [
        'driver'   => 'mysql',
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'database' => 'myapp',
        'username' => 'root',
        'password' => 'secret',
    ],
]);

// Get the default connection (lazy — no PDO until first use)
$conn = $manager->connection();

// Execute queries
$conn->execute('INSERT INTO users (name) VALUES (:name)', [':name' => 'Alice']);
$stmt = $conn->query('SELECT * FROM users WHERE id = :id', [':id' => 1]);
$user = $stmt->fetch();
```

### Direct Connection

```php
use MonkeysLegion\Database\Config\{DatabaseConfig, DsnConfig};
use MonkeysLegion\Database\Connection\Connection;
use MonkeysLegion\Database\Types\DatabaseDriver;

$dsn = new DsnConfig(
    driver: DatabaseDriver::MySQL,
    host: 'localhost',
    port: 3306,
    database: 'myapp',
);

$config = new DatabaseConfig(
    name: 'primary',
    driver: DatabaseDriver::MySQL,
    dsn: $dsn,
    username: 'root',
    password: 'secret',
);

$conn = new Connection($config);
$conn->connect();

// PDO is available
$pdo = $conn->pdo();
```

### SQLite In-Memory (Tests)

```php
$manager = ConnectionManager::fromArray([
    'test' => [
        'driver' => 'sqlite',
        'memory' => true,
    ],
]);

$conn = $manager->connection();
$conn->execute('CREATE TABLE tests (id INTEGER PRIMARY KEY, name TEXT)');
```

## Read/Write Splitting

Route read queries to replicas and writes to the primary connection:

```php
$manager = ConnectionManager::fromArray([
    'primary' => [
        'driver'   => 'mysql',
        'host'     => 'primary-db.internal',
        'database' => 'myapp',
        'username' => 'root',
        'password' => 'secret',
        'read'     => [
            'strategy' => 'round_robin',  // round_robin | random | least_connections
            'sticky'   => true,           // reads go to primary after a write
            'replicas' => [
                ['host' => 'replica-1.internal', 'database' => 'myapp'],
                ['host' => 'replica-2.internal', 'database' => 'myapp'],
            ],
        ],
    ],
]);

// Reads go to a replica
$users = $manager->read()->query('SELECT * FROM users');

// Writes go to primary
$manager->write()->execute('INSERT INTO users (name) VALUES (:n)', [':n' => 'Bob']);

// After a write with sticky enabled, reads also go to primary
$manager->markWritePerformed();
$freshUser = $manager->read()->query('SELECT * FROM users WHERE id = :id', [':id' => 1]);

// Reset sticky at request boundary
$manager->resetSticky();
```

## Transactions

```php
$conn = $manager->connection();

// Callback-based (recommended) — auto commit/rollback
$result = $conn->transaction(function ($c) {
    $c->execute('INSERT INTO orders (user_id, total) VALUES (:u, :t)', [':u' => 1, ':t' => 99.99]);
    $c->execute('UPDATE inventory SET stock = stock - 1 WHERE product_id = :p', [':p' => 42]);
    return 'order_placed';
});

// Manual control
use MonkeysLegion\Database\Types\IsolationLevel;

$conn->beginTransaction(IsolationLevel::RepeatableRead);
try {
    $conn->execute('...');
    $conn->commit();
} catch (\Throwable $e) {
    $conn->rollBack();
    throw $e;
}
```

## Lazy Connections

Connections are lazy by default when obtained through `ConnectionManager`. The PDO is not created until you call `pdo()`, `query()`, `execute()`, or start a transaction:

```php
$conn = $manager->connection(); // No database connection yet
echo $conn->getName();          // "default" — no connection needed
echo $conn->getDriver()->label(); // "MySQL / MariaDB" — still no connection

$conn->query('SELECT 1');       // NOW the connection is established
```

This is ideal for CLI commands and queue workers where many code paths don't touch the database.

## Connection Pooling

```php
use MonkeysLegion\Database\Config\PoolConfig;

$manager = ConnectionManager::fromArray([
    'default' => [
        'driver'   => 'mysql',
        'host'     => 'localhost',
        'database' => 'myapp',
        'username' => 'root',
        'password' => 'secret',
        'pool'     => [
            'min_connections'      => 2,
            'max_connections'      => 20,
            'idle_timeout'         => 300,   // seconds
            'max_lifetime'         => 3600,  // seconds
            'health_check_interval' => 30,
            'validate_on_acquire'  => true,
        ],
    ],
]);
```

### Pool Stats

```php
$stats = $manager->stats();
foreach ($stats as $name => $poolStats) {
    echo "{$name}: {$poolStats->active} active, {$poolStats->idle} idle\n";
    echo "  Utilization: " . round($poolStats->utilization() * 100) . "%\n";
    echo "  Exhausted: " . ($poolStats->isExhausted() ? 'YES' : 'no') . "\n";
}
```

## Exception Handling

Every `PDOException` is automatically classified into a specific, actionable exception type:

```php
use MonkeysLegion\Database\Exceptions\{
    DuplicateKeyException,
    ForeignKeyViolationException,
    DeadlockException,
    ConnectionLostException,
    SyntaxException,
};

try {
    $conn->execute('INSERT INTO users (email) VALUES (:e)', [':e' => 'dup@test.com']);
} catch (DuplicateKeyException $e) {
    // Unique constraint violated
    echo "Duplicate on: {$e->constraintName}, column: {$e->duplicateColumn}";
    echo "SQL: {$e->debugSql}"; // Interpolated SQL for debugging

} catch (ForeignKeyViolationException $e) {
    echo "FK: {$e->constraintName} → {$e->referencedTable}";

} catch (DeadlockException $e) {
    if ($e->canRetry) {
        // Safe to retry — deadlocks are always retryable
        retry($e->retryAttempt + 1);
    }

} catch (ConnectionLostException $e) {
    echo "Lost after {$e->uptimeBeforeLoss}s, retryable: " . ($e->retryable ? 'yes' : 'no');

} catch (SyntaxException $e) {
    // Never retryable
    echo "SQL error near '{$e->nearToken}' at position {$e->errorPosition}";
}
```

### Exception Hierarchy

```
DatabaseException (base — extends RuntimeException)
├── ConnectionException ($host, $port, $connectionName, $endpoint hook)
│   ├── ConnectionFailedException ($attemptedHosts)
│   ├── ConnectionLostException ($uptimeBeforeLoss, $wasInTransaction, $retryable hook)
│   └── AuthenticationException ($username)
├── QueryException ($sql, $params, $sqlState, $driverErrorCode, $debugSql hook)
│   ├── DuplicateKeyException ($constraintName, $duplicateColumn)
│   ├── ForeignKeyViolationException ($constraintName, $referencingColumn, $referencedTable)
│   ├── DeadlockException ($retryAttempt, $maxRetries, $canRetry hook, $retryable hook)
│   ├── LockTimeoutException ($timeoutSeconds, $blockingProcessId)
│   └── SyntaxException ($errorPosition, $nearToken, $retryable=false)
├── SchemaException ($schema)
│   ├── TableNotFoundException ($tableName, $qualifiedName hook)
│   └── ColumnNotFoundException ($columnName, $tableName, $qualifiedName hook)
├── TransactionException ($nestingLevel, $operation)
├── ConfigurationException ($connectionName, $configKey)
└── PoolException ($poolStats, $connectionName)
```

## Schema Introspection

Discover table structure without manual SQL:

```php
use MonkeysLegion\Database\Support\SchemaIntrospector;

$pdo = $conn->pdo();
$driver = $conn->getDriver();

// Get all tables
$tables = SchemaIntrospector::tables($pdo, $driver);
// ['users', 'orders', 'products', ...]

// Get columns for a table
$columns = SchemaIntrospector::columns($pdo, $driver, 'users');
// [['column_name' => 'id', 'data_type' => 'int', 'is_nullable' => 'NO', ...], ...]

// Get primary key
$pk = SchemaIntrospector::primaryKey($pdo, $driver, 'users');
// 'id'

// Get foreign keys
$fks = SchemaIntrospector::foreignKeys($pdo, $driver, 'orders');
// [['column' => 'user_id', 'referenced_table' => 'users', 'referenced_column' => 'id'], ...]

// Results are statically cached — repeated calls are free
```

## Configuration Reference

### DatabaseConfig

```php
use MonkeysLegion\Database\Config\{DatabaseConfig, DsnConfig, PoolConfig, ReadReplicaConfig};
use MonkeysLegion\Database\Types\DatabaseDriver;

$config = new DatabaseConfig(
    name: 'primary',
    driver: DatabaseDriver::MySQL,
    dsn: new DsnConfig(
        driver: DatabaseDriver::MySQL,
        host: 'db.example.com',
        port: 3306,
        database: 'production',
        charset: 'utf8mb4',
    ),
    username: 'appuser',
    password: 'secret',
    pdoOptions: [
        PDO::ATTR_TIMEOUT => 10,
    ],
    timezone: 'UTC',
    pool: new PoolConfig(
        minConnections: 2,
        maxConnections: 20,
    ),
);

// Or from array (backward-compatible)
$config = DatabaseConfig::fromArray('primary', [
    'driver'   => 'mysql',
    'host'     => 'db.example.com',
    'port'     => 3306,
    'database' => 'production',
    'username' => 'appuser',
    'password' => 'secret',
    'timezone' => 'UTC',
    'pool'     => ['max_connections' => 20],
]);
```

### DsnConfig Options

| Driver     | Option      | Description                       |
|------------|-------------|-----------------------------------|
| All        | `host`      | Server hostname                   |
| All        | `port`      | Server port                       |
| All        | `database`  | Database name                     |
| MySQL      | `socket`    | Unix socket path                  |
| MySQL      | `charset`   | Character set (default: utf8mb4)  |
| PostgreSQL | `sslMode`   | SSL mode (disable, require, etc.) |
| SQLite     | `file`      | Database file path                |
| SQLite     | `memory`    | Use `:memory:` database           |

## Health Checks

```php
use MonkeysLegion\Database\Support\HealthChecker;

$result = HealthChecker::check($conn);

echo "Healthy: " . ($result->healthy ? 'yes' : 'no') . "\n";
echo "Latency: {$result->latencyMs}ms\n";
if ($result->error) {
    echo "Error: {$result->error}\n";
}
```

## Connection Lifecycle

```php
$conn = $manager->connection();

// Check state
$conn->isConnected();  // false — lazy, not connected yet
$conn->isAlive();      // false

// Force connection
$conn->connect();
$conn->isConnected();  // true
$conn->isAlive();      // true

// Property hooks
$conn->queryCount;     // 0
$conn->uptimeSeconds;  // 0.001...

$conn->query('SELECT 1');
$conn->queryCount;     // 1

// Reconnect (new PDO, reset counters)
$conn->reconnect();
$conn->queryCount;     // 0

// Disconnect
$conn->disconnect();
$conn->uptimeSeconds;  // 0.0
```

## Multi-Connection Management

```php
$manager = ConnectionManager::fromArray([
    'mysql'    => ['driver' => 'mysql', 'host' => 'mysql-host', 'database' => 'app', ...],
    'postgres' => ['driver' => 'pgsql', 'host' => 'pg-host', 'database' => 'analytics', ...],
    'sqlite'   => ['driver' => 'sqlite', 'memory' => true],
]);

// Access by name
$app = $manager->connection('mysql');
$analytics = $manager->connection('postgres');
$cache = $manager->connection('sqlite');

// Switch default
$manager->setDefaultConnection('postgres');
$conn = $manager->connection(); // now returns postgres

// Disconnect specific
$manager->disconnect('mysql');

// Disconnect all
$manager->disconnectAll();
```

## DI Container Setup

```php
use MonkeysLegion\Database\Contracts\ConnectionManagerInterface;
use MonkeysLegion\Database\Connection\ConnectionManager;

// Register
ConnectionManagerInterface::class => fn() => ConnectionManager::fromArray(
    require base_path('config/database.php')
),

// Usage
public function __construct(
    private readonly ConnectionManagerInterface $db,
) {}

public function getUser(int $id): array
{
    $stmt = $this->db->read()->query(
        'SELECT * FROM users WHERE id = :id',
        [':id' => $id],
    );
    return $stmt->fetch() ?: [];
}
```

## Testing

```bash
composer test         # Run PHPUnit
composer phpstan      # Run PHPStan level 9
composer quality      # Run both
```

**163 tests, 361 assertions** covering all components:

| Component | Tests |
|-----------|-------|
| Types (DatabaseDriver, IsolationLevel, ReadReplicaStrategy) | 21 |
| Config (DsnConfig, PoolConfig, DatabaseConfig) | 25 |
| Connection (Connection, LazyConnection, ConnectionManager, ConnectionPool) | 54 |
| Exceptions (18 classes) | 23 |
| Support (ErrorClassifier, ConnectionPoolStats) | 22 |

## Upgrade from v1

v2 is a clean-slate rewrite. Key migration points:

| v1 | v2 |
|----|------|
| `MySQL\Connection`, `PostgreSQL\Connection`, `SQLite\Connection` | `Connection\Connection` (unified) |
| `DSN\MySQLDsnBuilder`, etc. | `Config\DsnConfig` value object |
| `Factory\ConnectionFactory` | `Connection\ConnectionManager` |
| `Types\DatabaseType` enum | `Types\DatabaseDriver` enum |
| `$connection->pdo()` | Same — `$connection->pdo()` |
| Raw `PDOException` | Typed exceptions (`DuplicateKeyException`, etc.) |
| No read/write splitting | `$manager->read()` / `$manager->write()` |
| No lazy connections | Lazy by default via `ConnectionManager` |
| No connection pooling | `ConnectionPool` with health monitoring |

## License

MIT — © 2026 MonkeysCloud Inc.
