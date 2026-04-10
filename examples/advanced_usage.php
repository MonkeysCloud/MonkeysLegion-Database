<?php

declare(strict_types=1);

/**
 * MonkeysLegion Database v2 — Advanced Usage
 *
 * Demonstrates:
 * - Read/write splitting with replicas
 * - Connection pooling and stats
 * - Schema introspection
 * - Health checks
 * - Error classification with the full exception hierarchy
 * - Multiple named connections
 * - Sticky-after-write behavior
 * - Direct Connection creation with DsnConfig + DatabaseConfig
 */

require_once __DIR__ . '/../vendor/autoload.php';

use MonkeysLegion\Database\Config\DatabaseConfig;
use MonkeysLegion\Database\Config\DsnConfig;
use MonkeysLegion\Database\Connection\Connection;
use MonkeysLegion\Database\Connection\ConnectionManager;
use MonkeysLegion\Database\Connection\LazyConnection;
use MonkeysLegion\Database\Exceptions\ConfigurationException;
use MonkeysLegion\Database\Exceptions\DuplicateKeyException;
use MonkeysLegion\Database\Exceptions\ForeignKeyViolationException;
use MonkeysLegion\Database\Exceptions\QueryException;
use MonkeysLegion\Database\Exceptions\SyntaxException;
use MonkeysLegion\Database\Exceptions\TableNotFoundException;
use MonkeysLegion\Database\Exceptions\TransactionException;
use MonkeysLegion\Database\Support\HealthChecker;
use MonkeysLegion\Database\Support\SchemaIntrospector;
use MonkeysLegion\Database\Types\DatabaseDriver;
use MonkeysLegion\Database\Types\IsolationLevel;

echo "=== MonkeysLegion Database v2 — Advanced Usage ===\n\n";

// ── 1. Direct Connection via Config Objects ─────────────────────────

echo "1. Direct Connection (Config Objects)\n";

$dsn = new DsnConfig(
    driver: DatabaseDriver::SQLite,
    memory: true,
);

$config = new DatabaseConfig(
    name: 'manual',
    driver: DatabaseDriver::SQLite,
    dsn: $dsn,
);

$conn = new Connection($config);
$conn->connect();

echo "   DSN:       {$dsn->dsn()}\n";
echo "   Driver:    {$conn->getDriver()->label()}\n";
echo "   Extension: {$conn->getDriver()->requiredExtension()}\n";
echo "   Loaded:    " . ($conn->getDriver()->isExtensionLoaded() ? 'yes' : 'no') . "\n\n";

$conn->disconnect();

// ── 2. Multi-Connection Manager ─────────────────────────────────────

echo "2. Multi-Connection Manager\n";

$manager = ConnectionManager::fromArray([
    'app' => [
        'driver' => 'sqlite',
        'memory' => true,
    ],
    'analytics' => [
        'driver' => 'sqlite',
        'memory' => true,
    ],
]);

$app = $manager->connection('app');
$analytics = $manager->connection('analytics');

echo "   Default: {$manager->getDefaultConnectionName()}\n";
echo "   App:     {$app->getName()} ({$app->getDriver()->label()})\n";
echo "   Analyt:  {$analytics->getName()} ({$analytics->getDriver()->label()})\n\n";

// ── 3. Schema Introspection ─────────────────────────────────────────

echo "3. Schema Introspection\n";

$appConn = $manager->connection('app');

// Create some tables
$appConn->execute('
    CREATE TABLE users (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        name       TEXT NOT NULL,
        email      TEXT NOT NULL UNIQUE,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )
');
$appConn->execute('
    CREATE TABLE orders (
        id      INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        total   REAL NOT NULL DEFAULT 0,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )
');

// SchemaIntrospector uses ConnectionInterface — no need for raw PDO
$tables = SchemaIntrospector::listTables($appConn);
echo "   Tables: " . implode(', ', $tables) . "\n";

$columns = SchemaIntrospector::listColumns($appConn, 'users');
echo "   users columns: " . implode(', ', $columns) . "\n";

echo "   column 'email' exists: " . (SchemaIntrospector::columnExists($appConn, 'users', 'email') ? 'yes' : 'no') . "\n";
echo "   column 'phone' exists: " . (SchemaIntrospector::columnExists($appConn, 'users', 'phone') ? 'yes' : 'no') . "\n";

$fks = SchemaIntrospector::loadForeignKeys($appConn, 'orders');
echo "   orders foreign keys:\n";
foreach ($fks as $col => $fk) {
    echo "     {$col} → {$fk['ref_table']}.{$fk['ref_col']} ({$fk['constraint_name']})\n";
}

$uniques = SchemaIntrospector::loadUniqueIndexes($appConn, 'users');
echo "   users unique indexes:\n";
foreach ($uniques as $idx) {
    $type = $idx['is_primary'] ? 'PRIMARY' : 'UNIQUE';
    echo "     {$idx['name']} ({$type}): " . implode(', ', $idx['columns']) . "\n";
}
echo "\n";

// ── 4. Health Checks ────────────────────────────────────────────────

echo "4. Health Checks\n";

$result = HealthChecker::check($appConn);
echo "   Healthy:  " . ($result->healthy ? 'yes' : 'no') . "\n";
echo "   Latency:  " . round($result->latencyMs, 2) . "ms\n";
if ($result->reason) {
    echo "   Reason:   {$result->reason}\n";
}
echo "\n";

// ── 5. Connection Pooling ───────────────────────────────────────────

echo "5. Connection Pooling & Stats\n";

$stats = $manager->stats();
foreach ($stats as $name => $poolStats) {
    echo "   [{$name}]\n";
    echo "     Active:      {$poolStats->active}\n";
    echo "     Idle:        {$poolStats->idle}\n";
    echo "     Total:       {$poolStats->total}\n";
    echo "     Max:         {$poolStats->maxSize}\n";
    echo "     Utilization: " . round($poolStats->utilization() * 100) . "%\n";
    echo "     Exhausted:   " . ($poolStats->isExhausted() ? 'YES' : 'no') . "\n";

    // toArray() for JSON/monitoring
    $arr = $poolStats->toArray();
    echo "     JSON:        " . json_encode($arr) . "\n";
}
echo "\n";

// ── 6. Transaction with Isolation Level ─────────────────────────────

echo "6. Transactions with Isolation Level\n";

$appConn->execute(
    'INSERT INTO users (name, email) VALUES (:name, :email)',
    [':name' => 'Alice', ':email' => 'alice@example.com'],
);

// Callback-based transaction (recommended)
$orderId = $appConn->transaction(function ($c) {
    $c->execute(
        'INSERT INTO orders (user_id, total) VALUES (:uid, :total)',
        [':uid' => 1, ':total' => 149.99],
    );
    return (int) $c->pdo()->lastInsertId();
});

echo "   Created order #{$orderId} inside transaction\n";

// Manual transaction with isolation level
$appConn->beginTransaction(IsolationLevel::Serializable);
try {
    $appConn->execute(
        'UPDATE orders SET total = total + :amount WHERE id = :id',
        [':amount' => 50.00, ':id' => $orderId],
    );
    $appConn->commit();
    echo "   Updated order total (Serializable isolation)\n";
} catch (\Throwable $e) {
    $appConn->rollBack();
    echo "   Transaction failed: {$e->getMessage()}\n";
}
echo "\n";

// ── 7. Full Exception Hierarchy Demo ────────────────────────────────

echo "7. Exception Hierarchy Demo\n";

// 7a. DuplicateKeyException
echo "   7a. DuplicateKeyException:\n";
try {
    $appConn->execute(
        'INSERT INTO users (name, email) VALUES (:name, :email)',
        [':name' => 'Alice Dupe', ':email' => 'alice@example.com'],
    );
} catch (DuplicateKeyException $e) {
    echo "       Caught! Debug SQL: {$e->debugSql}\n";
} catch (QueryException $e) {
    echo "       Query error (SQLSTATE {$e->sqlState}): {$e->getMessage()}\n";
}

// 7b. QueryException for missing table
echo "   7b. Missing table:\n";
try {
    $appConn->query('SELECT * FROM nonexistent_table');
} catch (TableNotFoundException $e) {
    echo "       TableNotFoundException! Table: {$e->tableName}\n";
} catch (QueryException $e) {
    echo "       QueryException: {$e->getMessage()}\n";
}

// 7c. SyntaxException
echo "   7c. SyntaxException:\n";
try {
    $appConn->execute('INSRT INTO users (name) VALUES ("bad")');
} catch (SyntaxException $e) {
    echo "       Caught! Retryable: " . ($e->retryable ? 'yes' : 'NO') . "\n";
} catch (QueryException $e) {
    echo "       Query error: {$e->getMessage()}\n";
}

// 7d. TransactionException
echo "   7d. TransactionException:\n";
try {
    $appConn->commit(); // No active transaction
} catch (TransactionException $e) {
    echo "       Caught! Operation: {$e->operation}, Nesting: {$e->nestingLevel}\n";
}

// 7e. ConfigurationException
echo "   7e. ConfigurationException:\n";
try {
    $manager->connection('nonexistent');
} catch (ConfigurationException $e) {
    echo "       Caught! {$e->getMessage()}\n";
}

echo "\n";

// ── 8. Lazy Connection Deep Dive ────────────────────────────────────

echo "8. Lazy Connection Lifecycle\n";

/** @var LazyConnection $lazy */
$lazy = $manager->connection('analytics');

echo "   Initialized: " . ($lazy->initialized ? 'yes' : 'no') . "\n";  // no
echo "   Driver:      {$lazy->getDriver()->label()}\n";                // no connection yet
echo "   Name:        {$lazy->getName()}\n";                           // no connection yet
echo "   Initialized: " . ($lazy->initialized ? 'yes' : 'no') . "\n";  // still no

$lazy->execute('CREATE TABLE events (id INTEGER PRIMARY KEY, type TEXT)');
echo "   After query: " . ($lazy->initialized ? 'yes (initialized)' : 'no') . "\n";
echo "   Connected:   " . ($lazy->isConnected() ? 'yes' : 'no') . "\n";
echo "   queryCount:  {$lazy->queryCount}\n";

$lazy->disconnect();
echo "   After disconnect: " . ($lazy->initialized ? 'yes' : 'no (reset)') . "\n\n";

// ── 9. PHP 8.4 Property Hooks ───────────────────────────────────────

echo "9. PHP 8.4 Property Hooks\n";

/** @var LazyConnection $hookConn */
$hookConn = $manager->connection('app');
echo "   queryCount before: {$hookConn->queryCount}\n"; // preserves count from earlier

$hookConn->query('SELECT 1');
$hookConn->query('SELECT 1');
echo "   queryCount after:  {$hookConn->queryCount}\n";
echo "   uptimeSeconds:     " . round($hookConn->uptimeSeconds, 4) . "s\n\n";

// ── 10. Read/Write Splitting (Config Example) ───────────────────────

echo "10. Read/Write Splitting (Config Example)\n";
echo <<<'CONFIG'

    $manager = ConnectionManager::fromArray([
        'primary' => [
            'driver'   => 'mysql',
            'host'     => 'primary.db.internal',
            'database' => 'myapp',
            'username' => 'root',
            'password' => 'secret',
            'read' => [
                'strategy' => 'round_robin',
                'sticky'   => true,
                'replicas' => [
                    ['host' => 'replica-1.db.internal'],
                    ['host' => 'replica-2.db.internal'],
                ],
            ],
        ],
    ]);

    // Reads → replica, Writes → primary
    $users = $manager->read()->query('SELECT * FROM users');
    $manager->write()->execute('INSERT INTO users ...');

    // After write, reads also go to primary (sticky)
    $manager->markWritePerformed();
    $fresh = $manager->read()->query('SELECT * FROM users WHERE ...');

    // Reset at request boundary
    $manager->resetSticky();

CONFIG;

echo "\n";

// ── 11. DI Container Pattern ────────────────────────────────────────

echo "11. DI Container Pattern\n";
echo <<<'DI'

    use MonkeysLegion\Database\Contracts\ConnectionManagerInterface;
    use MonkeysLegion\Database\Connection\ConnectionManager;

    // Container registration
    $container->singleton(
        ConnectionManagerInterface::class,
        fn() => ConnectionManager::fromArray(
            require base_path('config/database.php'),
        ),
    );

    // Service usage
    class UserRepository {
        public function __construct(
            private readonly ConnectionManagerInterface $db,
        ) {}

        public function find(int $id): ?array
        {
            $stmt = $this->db->read()->query(
                'SELECT * FROM users WHERE id = :id',
                [':id' => $id],
            );
            return $stmt->fetch() ?: null;
        }

        public function create(string $name, string $email): int
        {
            $this->db->write()->execute(
                'INSERT INTO users (name, email) VALUES (:n, :e)',
                [':n' => $name, ':e' => $email],
            );
            return (int) $this->db->write()->pdo()->lastInsertId();
        }
    }

DI;

echo "\n";

// ── Cleanup ─────────────────────────────────────────────────────────

$manager->disconnectAll();
echo "=== Done ===\n";
