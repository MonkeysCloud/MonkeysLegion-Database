<?php

declare(strict_types=1);

/**
 * MonkeysLegion Database v2 — Basic Usage
 *
 * Demonstrates:
 * - Creating connections via ConnectionManager
 * - Basic CRUD operations
 * - Transaction handling
 * - Lazy connections
 * - Property hooks (queryCount, uptimeSeconds)
 * - Typed exception handling
 */

require_once __DIR__ . '/../vendor/autoload.php';

use MonkeysLegion\Database\Connection\ConnectionManager;
use MonkeysLegion\Database\Exceptions\DuplicateKeyException;
use MonkeysLegion\Database\Exceptions\QueryException;

// ── 1. ConnectionManager from Array ──────────────────────────────────

$manager = ConnectionManager::fromArray([
    'default' => [
        'driver'   => 'sqlite',
        'memory'   => true,
    ],
]);

echo "=== MonkeysLegion Database v2 — Basic Usage ===\n\n";

// ── 2. Lazy Connection ──────────────────────────────────────────────

$conn = $manager->connection();

echo "1. Lazy Connection\n";
echo "   Driver:    {$conn->getDriver()->label()}\n";
echo "   Name:      {$conn->getName()}\n";
echo "   Connected: " . ($conn->isConnected() ? 'yes' : 'no (lazy — not yet)') . "\n\n";

// ── 3. Schema Setup ─────────────────────────────────────────────────

$conn->execute('
    CREATE TABLE users (
        id    INTEGER PRIMARY KEY AUTOINCREMENT,
        name  TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE,
        tier  TEXT NOT NULL DEFAULT "free"
    )
');

$conn->execute('
    CREATE TABLE posts (
        id      INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        title   TEXT NOT NULL,
        body    TEXT,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )
');

echo "2. Schema Created\n";
echo "   Connected: " . ($conn->isConnected() ? 'yes (first query triggered connection)' : 'no') . "\n";
echo "   Queries:   {$conn->queryCount}\n\n";

// ── 4. Insert — Basic Execute ───────────────────────────────────────

echo "3. Insert Users\n";

$conn->execute(
    'INSERT INTO users (name, email, tier) VALUES (:name, :email, :tier)',
    [':name' => 'Alice', ':email' => 'alice@example.com', ':tier' => 'premium'],
);

$conn->execute(
    'INSERT INTO users (name, email, tier) VALUES (:name, :email, :tier)',
    [':name' => 'Bob', ':email' => 'bob@example.com', ':tier' => 'free'],
);

$conn->execute(
    'INSERT INTO users (name, email, tier) VALUES (:name, :email, :tier)',
    [':name' => 'Carol', ':email' => 'carol@example.com', ':tier' => 'premium'],
);

echo "   3 users inserted. Query count: {$conn->queryCount}\n\n";

// ── 5. Query — SELECT ───────────────────────────────────────────────

echo "4. Query Users\n";

$stmt = $conn->query('SELECT id, name, email, tier FROM users ORDER BY name');

while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
    printf("   [%d] %-8s %-25s %s\n", $row['id'], $row['name'], $row['email'], $row['tier']);
}
echo "\n";

// ── 6. Query with Parameters ────────────────────────────────────────

echo "5. Query Premium Users\n";

$stmt = $conn->query(
    'SELECT name, email FROM users WHERE tier = :tier',
    [':tier' => 'premium'],
);

$premiumUsers = $stmt->fetchAll(\PDO::FETCH_ASSOC);
echo "   Found " . count($premiumUsers) . " premium users\n";
foreach ($premiumUsers as $u) {
    echo "   - {$u['name']} ({$u['email']})\n";
}
echo "\n";

// ── 7. Transaction — Callback Style (Recommended) ──────────────────

echo "6. Transaction — Callback (auto commit/rollback)\n";

$result = $conn->transaction(function ($c) {
    $c->execute(
        'INSERT INTO posts (user_id, title, body) VALUES (:uid, :title, :body)',
        [':uid' => 1, ':title' => 'Hello World', ':body' => 'First post!'],
    );
    $c->execute(
        'INSERT INTO posts (user_id, title, body) VALUES (:uid, :title, :body)',
        [':uid' => 1, ':title' => 'Second Post', ':body' => 'More content'],
    );
    return 2; // return value from transaction
});

echo "   Inserted {$result} posts inside a transaction\n\n";

// ── 8. Transaction — Rollback on Error ──────────────────────────────

echo "7. Transaction — Rollback on Error\n";

try {
    $conn->transaction(function ($c) {
        $c->execute(
            'INSERT INTO posts (user_id, title) VALUES (:uid, :title)',
            [':uid' => 2, ':title' => 'Will be rolled back'],
        );
        // This will fail — duplicate email
        $c->execute(
            'INSERT INTO users (name, email) VALUES (:name, :email)',
            [':name' => 'Alice Dupe', ':email' => 'alice@example.com'],
        );
    });
} catch (DuplicateKeyException $e) {
    echo "   Caught DuplicateKeyException: rolled back automatically\n";
    echo "   Constraint: {$e->constraintName}\n";
    echo "   Debug SQL:  {$e->debugSql}\n";
} catch (QueryException $e) {
    echo "   Caught QueryException: {$e->getMessage()}\n";
    echo "   SQL State: {$e->sqlState}\n";
}

// Verify the post was NOT persisted
$stmt = $conn->query('SELECT COUNT(*) as cnt FROM posts WHERE user_id = 2');
$count = (int) $stmt->fetch(\PDO::FETCH_ASSOC)['cnt'];
echo "   Posts by user 2: {$count} (should be 0 — rolled back)\n\n";

// ── 9. Update & Delete ──────────────────────────────────────────────

echo "8. Update & Delete\n";

$affected = $conn->execute(
    'UPDATE users SET tier = :tier WHERE name = :name',
    [':tier' => 'enterprise', ':name' => 'Alice'],
);
echo "   Updated {$affected} row(s)\n";

$affected = $conn->execute(
    'DELETE FROM users WHERE name = :name',
    [':name' => 'Bob'],
);
echo "   Deleted {$affected} row(s)\n\n";

// ── 10. Connection Stats ────────────────────────────────────────────

echo "9. Connection Stats\n";
echo "   Total queries:  {$conn->queryCount}\n";
echo "   Uptime:         " . round($conn->uptimeSeconds, 4) . "s\n";
echo "   Alive:          " . ($conn->isAlive() ? 'yes' : 'no') . "\n\n";

// ── 11. Pool Stats ──────────────────────────────────────────────────

echo "10. Pool Stats\n";
$stats = $manager->stats();
foreach ($stats as $name => $poolStats) {
    echo "   [{$name}] active={$poolStats->active}, idle={$poolStats->idle}, "
        . "utilization=" . round($poolStats->utilization() * 100) . "%\n";
}
echo "\n";

// ── 12. Disconnect ──────────────────────────────────────────────────

echo "11. Disconnect\n";
$manager->disconnectAll();
echo "   All connections disconnected.\n";
echo "   Connected: " . ($conn->isConnected() ? 'yes' : 'no') . "\n\n";

echo "=== Done ===\n";
