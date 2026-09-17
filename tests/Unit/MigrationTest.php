<?php

test('a 1.x SQLite database upgrades to the current schema without losing data', function () {
    if (db_is_mysql()) {
        return; // The fixture is the original SQLite schema.
    }
    $file = getenv('FINPULSE_TEST_DIR') . '/legacy-' . bin2hex(random_bytes(3)) . '.db';
    $legacy = new PDO('sqlite:' . $file);
    $legacy->exec(file_get_contents(__DIR__ . '/../fixtures/schema-v1.sqlite.sql'));
    $legacy->exec("ALTER TABLE users ADD COLUMN notification_preferences TEXT DEFAULT '{}'");
    $legacy->exec("INSERT INTO users (username, email, password_hash, notification_preferences) VALUES ('maya', 'maya@example.test', 'x', '{\"reminders\":0,\"budget_alerts\":1}')");
    $legacy->exec("INSERT INTO expense_categories (user_id, name) VALUES (1, 'Food')");
    $legacy->exec("INSERT INTO expenses (user_id, category_id, amount, expense_date, description) VALUES (1, 1, 12.5, '2025-01-02', 'Lunch')");
    expect_same(0, (int) $legacy->query('PRAGMA user_version')->fetchColumn());
    $legacy = null;

    $pdo = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    migrate($pdo);
    migrate($pdo); // idempotent

    expect_same(SCHEMA_VERSION, (int) $pdo->query('PRAGMA user_version')->fetchColumn());
    foreach (['currency', 'notification_preferences', 'session_epoch'] as $column) {
        expect_true(in_array($column, column_names($pdo, 'users', false), true), "users.$column");
    }
    expect_true(in_array('recurring_source_id', column_names($pdo, 'expenses', false), true));
    foreach (['login_attempts', 'password_resets', 'notifications', 'mail_queue'] as $table) {
        expect_true(table_exists($pdo, $table, false), "table $table");
    }
    expect_same('Lunch', $pdo->query('SELECT description FROM expenses')->fetchColumn());
    expect_same('USD', $pdo->query('SELECT currency FROM users')->fetchColumn());
    expect_false(notification_prefs($pdo->query('SELECT * FROM users')->fetchAll()[0])['upcoming_payments'], '1.x reminder choice is kept');
});

test('the live test database is at the current schema version', function () {
    expect_same(SCHEMA_VERSION, schema_version(db(), db_is_mysql()));
});
