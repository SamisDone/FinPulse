<?php

test('notification preferences default on and carry over 1.x settings', function () {
    expect_same(['budget_alerts' => true, 'upcoming_payments' => true, 'monthly_summary' => true, 'email' => true], notification_prefs(['notification_preferences' => null]));
    $legacy = notification_prefs(['notification_preferences' => '{"reminders":0,"budget_alerts":0}']);
    expect_false($legacy['upcoming_payments']);
    expect_false($legacy['budget_alerts']);
    expect_true($legacy['monthly_summary']);
});

test('notify creates each notification once and emails when enabled', function () {
    $user = make_user('notify');
    expect_true(notify($user, 'test', 'k1', 'Hello', 'Body', 'dashboard'));
    expect_false(notify($user, 'test', 'k1', 'Hello again', 'Body', 'dashboard'), 'deduplicated');
    expect_same(1, unread_notification_count($user['id']));
    expect_same(1, count_rows('SELECT COUNT(*) FROM mail_queue WHERE to_email = ?', [$user['email']]));

    save_notification_prefs($user['id'], ['budget_alerts' => true, 'upcoming_payments' => true, 'monthly_summary' => true, 'email' => false]);
    $user['notification_preferences'] = json_encode(['email' => false]);
    notify($user, 'test', 'k2', 'Quiet', 'Body', 'dashboard');
    expect_same(1, count_rows('SELECT COUNT(*) FROM mail_queue WHERE to_email = ?', [$user['email']]), 'no email when email is off');

    notify($user, 'test', 'k3', 'Silent', 'Body', 'dashboard', true, true);
    expect_same(2, unread_notification_count($user['id']), 'silent notifications are stored as read');
});

test('budget alerts fire at 80% and 100% without repeating', function () {
    $user = make_user('alerts');
    db()->prepare("INSERT INTO budgets (user_id, name, period_type, start_date, end_date, total_limit) VALUES (?, 'Monthly', 'monthly', '2026-09-01', '2026-09-30', 1000)")->execute([$user['id']]);
    $budget_id = (int) db()->lastInsertId();
    db()->prepare('INSERT INTO budget_categories (budget_id, expense_category_id, limit_amount) VALUES (?, ?, 100)')
        ->execute([$budget_id, lookup_id($user['id'], 'expense_categories', 'Dining out')]);

    add_expense($user, 500, '2026-09-02');
    check_budget_alerts($user, '2026-09-10');
    expect_same(0, count_rows('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$user['id']]), 'nothing at 50%');

    add_expense($user, 350, '2026-09-03');
    check_budget_alerts($user, '2026-09-10');
    check_budget_alerts($user, '2026-09-10');
    expect_same(1, count_rows("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND dedupe_key = ?", [$user['id'], "budget:$budget_id:80"]));

    add_expense($user, 200, '2026-09-04', 'Dining out');
    check_budget_alerts($user, '2026-09-10');
    expect_same(1, count_rows("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND dedupe_key = ?", [$user['id'], "budget:$budget_id:100"]));
    expect_same(1, count_rows("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND dedupe_key LIKE ?", [$user['id'], "budget:$budget_id:cat:%"]), 'category cap alert');
});

test('upcoming payment reminders cover the next three days', function () {
    $user = make_user('upcoming');
    db()->prepare("INSERT INTO expenses (user_id, amount, expense_date, description, is_recurring, recurrence_period, next_recurrence_date) VALUES (?, 15.49, '2026-08-18', 'Netflix', 1, 'monthly', '2026-09-18')")->execute([$user['id']]);
    db()->prepare("INSERT INTO expenses (user_id, amount, expense_date, description, is_recurring, recurrence_period, next_recurrence_date) VALUES (?, 1450, '2026-09-01', 'Rent', 1, 'monthly', '2026-10-01')")->execute([$user['id']]);

    use_currency('USD');
    check_upcoming_payments($user, '2026-09-17');
    $titles = db()->prepare('SELECT title FROM notifications WHERE user_id = ?');
    $titles->execute([$user['id']]);
    expect_same(['Netflix ($15.49) is due tomorrow'], $titles->fetchAll(PDO::FETCH_COLUMN));
});

test('monthly summary is created once for the previous month', function () {
    $user = make_user('summary');
    db()->prepare("UPDATE users SET created_at = '2026-01-01 00:00:00' WHERE id = ?")->execute([$user['id']]);
    $user['created_at'] = '2026-01-01 00:00:00';
    add_income($user, 4000, '2026-08-01');
    add_expense($user, 1000, '2026-08-05', 'Rent');

    check_monthly_summary($user, '2026-09-02');
    check_monthly_summary($user, '2026-09-20');
    $rows = db()->prepare("SELECT title, body FROM notifications WHERE user_id = ? AND type = 'summary'");
    $rows->execute([$user['id']]);
    $all = $rows->fetchAll();
    expect_same(1, count($all));
    expect_same('Your August summary', $all[0]['title']);
    expect_contains('You kept $3,000.00', $all[0]['body']);
});
