<?php
/**
 * Database connection (SQLite by default, MySQL/MariaDB optional) and schema migrations.
 */
defined('FINPULSE') || exit;

const SCHEMA_VERSION = 3;

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];

    try {
        if (db_is_mysql()) {
            $databaseUrl = env('DATABASE_URL');
            if ($databaseUrl) {
                $parts = parse_url($databaseUrl);
                $host = $parts['host'] ?? '127.0.0.1';
                $port = isset($parts['port']) ? (string) $parts['port'] : '3306';
                $dbname = !empty($parts['path']) && $parts['path'] !== '/' ? ltrim($parts['path'], '/') : env('DB_NAME', 'finpulse');
                $user = isset($parts['user']) ? urldecode($parts['user']) : env('DB_USER', 'root');
                $pass = isset($parts['pass']) ? urldecode($parts['pass']) : env('DB_PASS', '');
            } else {
                $host = env('DB_HOST', '127.0.0.1');
                $port = env('DB_PORT', '3306');
                $dbname = env('DB_NAME', 'finpulse');
                $user = env('DB_USER', 'root');
                $pass = env('DB_PASS', '');
            }

            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $host,
                $port,
                $dbname
            );

            // TiDB Cloud Serverless and remote MySQL providers require SSL/TLS
            if (env_bool('DB_SSL', false) || str_contains($host, 'tidbcloud.com')) {
                $caBundles = [
                    '/etc/ssl/certs/ca-certificates.crt', // Debian / Ubuntu / Docker
                    '/etc/pki/tls/certs/ca-bundle.crt',   // RHEL / CentOS
                    '/etc/ssl/cert.pem',                 // macOS / Alpine
                ];
                foreach ($caBundles as $bundle) {
                    if (is_file($bundle)) {
                        $options[PDO::MYSQL_ATTR_SSL_CA] = $bundle;
                        break;
                    }
                }
                if (env('DB_SSL_VERIFY') === 'false') {
                    $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
                }
            }

            $pdo = new PDO($dsn, $user, $pass, $options);
        } else {
            $path = sqlite_path();
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }
            $pdo = new PDO('sqlite:' . $path, null, null, $options);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 5000');
            // Write-ahead logging lets page views, cron and the mail queue read while another request writes.
            if (env_bool('DB_SQLITE_WAL', true)) {
                $pdo->exec('PRAGMA journal_mode = WAL');
                $pdo->exec('PRAGMA synchronous = NORMAL');
            }
        }
    } catch (PDOException $e) {
        error_log('[FinPulse] Database connection failed: ' . $e->getMessage());
        throw new RuntimeException('Could not connect to the database. Check the DB_* settings in .env.');
    }

    migrate($pdo);
    return $pdo;
}

function db_is_mysql(): bool
{
    $dbUrl = env('DATABASE_URL');
    if ($dbUrl && (str_starts_with($dbUrl, 'mysql://') || str_starts_with($dbUrl, 'mysql:'))) {
        return true;
    }
    return strtolower((string) env('DB_TYPE', 'sqlite')) === 'mysql';
}

/**
 * Resolve the SQLite file. Relative DB_PATH values start at the project root.
 * Installs from before 2.0 keep using finance_tracker.db in the project root.
 */
function sqlite_path(): string
{
    if ($configured = env('DB_PATH')) {
        return project_path($configured);
    }
    $legacy = APP_ROOT . '/finance_tracker.db';
    return is_file($legacy) ? $legacy : APP_ROOT . '/storage/finpulse.db';
}

/* ---------------------------------------------------------------------------
 * Migrations
 * ------------------------------------------------------------------------ */

/**
 * Bring any database up to SCHEMA_VERSION. Existing installs first get the columns
 * that newer versions added; then the (idempotent) schema file creates missing tables
 * and indexes. Fresh installs simply run the schema file.
 */
function migrate(PDO $pdo): void
{
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    if (schema_version($pdo, $mysql) >= SCHEMA_VERSION) {
        return;
    }

    if (table_exists($pdo, 'users', $mysql)) {
        $columns = [
            'users' => [
                'currency' => ["TEXT NOT NULL DEFAULT 'USD'", "VARCHAR(3) NOT NULL DEFAULT 'USD'"],
                'notification_preferences' => ['TEXT', 'TEXT NULL'],
                'session_epoch' => ['INTEGER NOT NULL DEFAULT 0', 'INT UNSIGNED NOT NULL DEFAULT 0'],
            ],
            'income' => ['recurring_source_id' => ['INTEGER', 'INT UNSIGNED NULL']],
            'expenses' => ['recurring_source_id' => ['INTEGER', 'INT UNSIGNED NULL']],
        ];
        foreach ($columns as $table => $defs) {
            $existing = column_names($pdo, $table, $mysql);
            foreach ($defs as $column => [$sqlite_def, $mysql_def]) {
                if (!in_array($column, $existing, true)) {
                    $pdo->exec("ALTER TABLE $table ADD COLUMN $column " . ($mysql ? $mysql_def : $sqlite_def));
                }
            }
        }
    }

    $schema = file_get_contents(APP_ROOT . '/database/schema.' . ($mysql ? 'mysql' : 'sqlite') . '.sql');
    if ($mysql) {
        foreach (array_filter(array_map('trim', explode(';', $schema))) as $statement) {
            $pdo->exec($statement);
        }
        foreach (['income', 'expenses'] as $table) {
            $has_index = $pdo->query("SHOW INDEX FROM $table WHERE Key_name = 'idx_{$table}_recurring'")->fetch();
            if (!$has_index) {
                $pdo->exec("CREATE INDEX idx_{$table}_recurring ON $table (is_recurring, next_recurrence_date)");
            }
        }
        $pdo->prepare('REPLACE INTO app_meta (meta_key, meta_value) VALUES (?, ?)')->execute(['schema_version', (string) SCHEMA_VERSION]);
    } else {
        $pdo->exec($schema);
        $pdo->exec('PRAGMA user_version = ' . SCHEMA_VERSION);
    }
}

function schema_version(PDO $pdo, bool $mysql): int
{
    if (!$mysql) {
        return (int) $pdo->query('PRAGMA user_version')->fetchColumn();
    }
    if (!table_exists($pdo, 'app_meta', true)) {
        return 0;
    }
    return (int) $pdo->query("SELECT meta_value FROM app_meta WHERE meta_key = 'schema_version'")->fetchColumn();
}

function table_exists(PDO $pdo, string $table, bool $mysql): bool
{
    $stmt = $mysql
        ? $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?')
        : $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function column_names(PDO $pdo, string $table, bool $mysql): array
{
    if ($mysql) {
        $stmt = $pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?');
        $stmt->execute([$table]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    return array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(), 'name');
}

/* ---------------------------------------------------------------------------
 * Lookup tables (categories, sources, payment methods)
 * ------------------------------------------------------------------------ */

/** Insert-or-fetch a named lookup row for a user. */
function lookup_id(int $user_id, string $table, string $name): ?int
{
    $allowed = ['income_sources', 'income_categories', 'expense_categories', 'payment_methods'];
    if (!in_array($table, $allowed, true)) {
        throw new InvalidArgumentException("Unknown lookup table: $table");
    }
    $name = trim($name);
    if ($name === '') {
        return null;
    }

    $find = db()->prepare("SELECT id FROM $table WHERE user_id = ? AND LOWER(name) = LOWER(?)");
    $find->execute([$user_id, $name]);
    if ($id = $find->fetchColumn()) {
        return (int) $id;
    }
    db()->prepare("INSERT INTO $table (user_id, name) VALUES (?, ?)")->execute([$user_id, $name]);
    return (int) db()->lastInsertId();
}

function lookup_names(int $user_id, string $table): array
{
    $stmt = db()->prepare("SELECT id, name FROM $table WHERE user_id = ? ORDER BY name");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** Seed the default categories, sources and payment methods for a new user. */
function seed_user_defaults(int $user_id): void
{
    $defaults = [
        'expense_categories' => ['Groceries', 'Dining out', 'Rent', 'Transport', 'Utilities', 'Health', 'Shopping', 'Entertainment', 'Education', 'Other'],
        'payment_methods' => ['Cash', 'Debit card', 'Credit card', 'Bank transfer', 'Mobile wallet'],
        'income_sources' => ['Salary', 'Freelance', 'Interest', 'Investments', 'Gifts'],
        'income_categories' => ['Primary job', 'Side work', 'Bonus', 'Passive', 'Other'],
    ];
    foreach ($defaults as $table => $names) {
        $count = db()->prepare("SELECT COUNT(*) FROM $table WHERE user_id = ?");
        $count->execute([$user_id]);
        if ((int) $count->fetchColumn() > 0) {
            continue;
        }
        $insert = db()->prepare("INSERT INTO $table (user_id, name) VALUES (?, ?)");
        foreach ($names as $name) {
            $insert->execute([$user_id, $name]);
        }
    }
}
