<?php
/**
 * PostgreSQL connection and schema migrations.
 */
defined('SIXPENCE') || exit;

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

    [$host, $port, $dbname, $user, $pass, $query] = db_settings();

    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $dbname);

    // Managed Postgres (Render, Neon, Supabase) requires TLS; a local server
    // usually has none, so only default to require when the host is remote.
    $sslmode = $query['sslmode'] ?? env('DB_SSLMODE', '');
    if ($sslmode === '' && !in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
        $sslmode = 'require';
    }
    if ($sslmode !== '') {
        $dsn .= ';sslmode=' . $sslmode;
    }

    try {
        $pdo = new PDO($dsn, $user, $pass, $options);
    } catch (PDOException $e) {
        error_log('[Sixpence] Database connection failed: ' . $e->getMessage());
        throw new RuntimeException('Could not connect to the database. Check DATABASE_URL or the DB_* settings in .env.');
    }

    migrate($pdo);
    return $pdo;
}

/**
 * Connection settings from DATABASE_URL when present, otherwise the individual
 * DB_* variables. Returns [host, port, dbname, user, pass, urlQuery].
 */
function db_settings(): array
{
    $databaseUrl = env('DATABASE_URL');
    if (!$databaseUrl) {
        return [
            env('DB_HOST', '127.0.0.1'),
            env('DB_PORT', '5432'),
            env('DB_NAME', 'sixpence'),
            env('DB_USER', 'postgres'),
            env('DB_PASS', ''),
            [],
        ];
    }

    $parts = parse_url($databaseUrl);
    $query = [];
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
    }
    return [
        $parts['host'] ?? '127.0.0.1',
        isset($parts['port']) ? (string) $parts['port'] : '5432',
        !empty($parts['path']) && $parts['path'] !== '/' ? ltrim($parts['path'], '/') : env('DB_NAME', 'sixpence'),
        isset($parts['user']) ? urldecode($parts['user']) : env('DB_USER', 'postgres'),
        isset($parts['pass']) ? urldecode($parts['pass']) : env('DB_PASS', ''),
        $query,
    ];
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
    if (schema_version($pdo) >= SCHEMA_VERSION) {
        return;
    }

    if (table_exists($pdo, 'users')) {
        $columns = [
            'users' => [
                'currency' => "VARCHAR(3) NOT NULL DEFAULT 'USD'",
                'notification_preferences' => 'TEXT NULL',
                'session_epoch' => 'BIGINT NOT NULL DEFAULT 0',
            ],
            'income' => ['recurring_source_id' => 'INTEGER NULL'],
            'expenses' => ['recurring_source_id' => 'INTEGER NULL'],
        ];
        foreach ($columns as $table => $defs) {
            // A half-created database can have users without the rest; the schema
            // file below creates whatever is missing, so skip it here.
            if (!table_exists($pdo, $table)) {
                continue;
            }
            $existing = column_names($pdo, $table);
            foreach ($defs as $column => $definition) {
                if (!in_array($column, $existing, true)) {
                    $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
                }
            }
        }
    }

    // Run whole rather than split on ';': the updated_at trigger function body
    // contains semicolons, and CREATE ... IF NOT EXISTS makes it idempotent.
    $pdo->exec(file_get_contents(APP_ROOT . '/database/schema.pgsql.sql'));
    $pdo->prepare('INSERT INTO app_meta (meta_key, meta_value) VALUES (?, ?) ON CONFLICT (meta_key) DO UPDATE SET meta_value = EXCLUDED.meta_value')
        ->execute(['schema_version', (string) SCHEMA_VERSION]);
}

function schema_version(PDO $pdo): int
{
    if (!table_exists($pdo, 'app_meta')) {
        return 0;
    }
    return (int) $pdo->query("SELECT meta_value FROM app_meta WHERE meta_key = 'schema_version'")->fetchColumn();
}

function table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?');
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function column_names(PDO $pdo, string $table): array
{
    $stmt = $pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ?');
    $stmt->execute([$table]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
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
