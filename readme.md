# MonkeysLegion Database

A flexible PDO-based database abstraction layer supporting MySQL, PostgreSQL, and SQLite with elegant DSN builders and connection management.

## Installation

```bash
composer require monkeyscloud/monkeyslegion-database
```

## Basic Usage

### 1. Direct Connection Creation

```php
use MonkeysLegion\Database\MySQL\Connection as MySQLConnection;
use MonkeysLegion\Database\PostgreSQL\Connection as PostgreSQLConnection;
use MonkeysLegion\Database\SQLite\Connection as SQLiteConnection;

// MySQL
$config = [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'dsn' => 'mysql:host=localhost;dbname=myapp',
            'username' => 'root',
            'password' => 'secret',
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        ],
        ...
    ]
];

// You must pass only the sub-config specific to the DB type, not the whole config array
$connection = new MySQLConnection($config['connections']['mysql']);
$connection->connect();
$pdo = $connection->pdo();
```

### 2. Using ConnectionFactory (Recommended)

```php
use MonkeysLegion\Database\Factory\ConnectionFactory;
use MonkeysLegion\Database\Types\DatabaseType;

$config = require base_path('config/database.php');

// Create connection using the 'default' type from config; throws if missing
$connection = ConnectionFactory::create($config);

// Or specify connection type
$connection = ConnectionFactory::createByType('mysql', $config);

// Or specify by available connection enum
$connection = ConnectionFactory::createByEnum(
    DatabaseType::MYSQL,
    $config
);
```

### 3. Dependency Injection Container Setup

#### Old Way (Direct Connection)
```php
// Before
Connection::class => fn() => new Connection(
    require base_path('config/database.php')
),
```

#### New Way (Factory Pattern)
```php
// Register interface with factory
ConnectionInterface::class => fn() =>
    ConnectionFactory::create(require base_path('config/database.php')),

// Or for dynamic switching
ConnectionInterface::class => function() {
    $config = require base_path('config/database.php');
    $type = env('DB_CONNECTION', 'mysql');
    
    return ConnectionFactory::createByType($type, $config);
}
```

## Configuration

### Database Configuration Structure

```php
// config/database.php
return [
    'default' => 'YOUR_CONNECION_DRIVER',
    'connections' => [
        'mysql' => [
            'dsn' => 'mysql:host=localhost;dbname=myapp;charset=utf8mb4',
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        ],
        'postgresql' => [
            'dsn' => 'pgsql:host=localhost;dbname=myapp',
            'username' => env('DB_USERNAME', 'postgres'),
            'password' => env('DB_PASSWORD', ''),
            'options' => []
        ],
        'sqlite' => [
            'dsn' => 'sqlite:' . database_path('database.sqlite'),
            'options' => []
        ]
    ]
];
```

### Component-Based Configuration

Instead of providing a full DSN, you can use component-based configuration:

```php
'connections' => [
    'mysql' => [
        'host' => 'localhost',
        'port' => 3306,
        'database' => 'myapp',
        'charset' => 'utf8mb4',
        'username' => 'root',
        'password' => 'secret'
    ],
    'postgresql' => [
        'host' => 'localhost',
        'port' => 5432,
        'database' => 'myapp',
        'username' => 'postgres',
        'password' => 'secret'
    ],
    'sqlite' => [
        'file' => '/path/to/database.sqlite',
        // or
        'memory' => true  // for in-memory database
    ]
]
```

## DSN Builders

Build DSNs programmatically with fluent API:

```php
use MonkeysLegion\Database\DSN\MySQLDsnBuilder;
use MonkeysLegion\Database\DSN\PostgreSQLDsnBuilder;
use MonkeysLegion\Database\DSN\SQLiteDsnBuilder;

// MySQL
$dsn = MySQLDsnBuilder::localhost('myapp')->build();
$dsn = MySQLDsnBuilder::docker('myapp', 'db')->build();

// PostgreSQL  
$dsn = PostgreSQLDsnBuilder::localhost('myapp')->build();
$dsn = PostgreSQLDsnBuilder::create()
    ->host('localhost')
    ->port(5432)
    ->database('myapp')
    ->sslMode('require')
    ->build();

// SQLite
$dsn = SQLiteDsnBuilder::inMemory()->build();
$dsn = SQLiteDsnBuilder::fromFile('/path/to/db.sqlite')->build();
$dsn = SQLiteDsnBuilder::temporary()->build();
```

## Connection Management

### Basic Operations

```php
$connection = ConnectionFactory::create($config);

// Connect
$connection->connect();

// Check status
if ($connection->isConnected()) {
    echo "Connected!";
}

// Health check
if ($connection->isAlive()) {
    echo "Database is responsive!";
}

// Get PDO instance
$pdo = $connection->pdo();

// Disconnect
$connection->disconnect();
```

### Host Fallback

MySQL and PostgreSQL connections automatically fall back to `localhost` if the configured host is unreachable (useful for Docker environments):

```php
// If 'db' host fails, automatically tries 'localhost'
'mysql' => [
    'dsn' => 'mysql:host=db;dbname=myapp',
    'username' => 'root',
    'password' => 'secret'
]
```

## Testing

The package includes comprehensive tests. Run them with:

```bash
composer test
composer phpstan
composer quality  # Runs both tests and static analysis
```

## Features

- ✅ **Multi-Database Support**: MySQL, PostgreSQL, SQLite
- ✅ **DSN Builders**: Fluent API for building connection strings
- ✅ **Host Fallback**: Automatic localhost fallback for unreachable hosts
- ✅ **Connection Health Checks**: `isConnected()` and `isAlive()` methods
- ✅ **Type Safety**: Full enum support and strict typing
- ✅ **Factory Pattern**: Flexible connection creation
- ✅ **Docker Ready**: Built-in support for containerized environments
- ✅ **Zero Dependencies**: Pure PDO implementation
