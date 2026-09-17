<?php

test('every signed-in page renders', function () {
    $user = make_user('pages');
    add_income($user, 3000, today()->format('Y-m-01'));
    add_expense($user, 42, today()->format('Y-m-d'));
    $http = (new HttpClient())->login($user['username'], $user['password']);
    foreach (['/dashboard', '/income', '/expenses', '/budgets', '/savings', '/reports', '/reports?range=12m', '/notifications', '/settings'] as $path) {
        $http->get($path);
        expect_same(200, $http->status, $path);
        expect_not_contains('Fatal error', $http->body, $path);
        expect_not_contains('Warning:', $http->body, $path);
    }
});

test('adding an expense validates, saves, edits and deletes', function () {
    $user = make_user('ledger');
    $http = (new HttpClient())->login($user['username'], $user['password']);
    $http->get('/expenses');

    $http->post('/expenses', ['action' => 'save', 'amount' => '-4', 'date' => 'soon', 'primary' => ''])->follow();
    expect_contains('Enter an amount greater than zero', $http->body);
    expect_contains('Pick a valid date', $http->body);

    $http->post('/expenses', ['action' => 'save', 'amount' => '1,234.50', 'date' => '2026-09-10', 'primary' => 'Brand new category', 'secondary' => 'Card', 'description' => 'Laptop stand'])->follow();
    expect_contains('$1,234.50 expense added', $http->body);
    expect_contains('Laptop stand', $http->body);
    expect_same(1, count_rows("SELECT COUNT(*) FROM expense_categories WHERE user_id = ? AND name = 'Brand new category'", [$user['id']]));

    $id = count_rows('SELECT MAX(id) FROM expenses WHERE user_id = ?', [$user['id']]);
    $http->get("/expenses?month=2026-09&edit=$id");
    expect_contains('value="1234.50"', $http->body);
    $http->post('/expenses', ['action' => 'save', 'id' => $id, 'amount' => '99', 'date' => '2026-09-10', 'primary' => 'Brand new category'])->follow();
    expect_contains('Changes saved', $http->body);
    expect_same(99, count_rows('SELECT amount FROM expenses WHERE id = ?', [$id]));

    $http->post('/expenses', ['action' => 'delete', 'id' => $id, 'return' => '/expenses?month=2026-09'])->follow();
    expect_contains('Expense deleted', $http->body);
    expect_same(0, count_rows('SELECT COUNT(*) FROM expenses WHERE id = ?', [$id]));
});

test('search treats % and _ literally', function () {
    $user = make_user('search');
    add_expense($user, 5, '2026-09-01', 'Groceries', '100% juice');
    add_expense($user, 6, '2026-09-01', 'Groceries', 'plain water');
    $http = (new HttpClient())->login($user['username'], $user['password']);
    expect_contains('100% juice', $http->get('/expenses?month=all&q=' . rawurlencode('100%'))->body);
    expect_not_contains('plain water', $http->body);
    expect_contains('No matches', $http->get('/expenses?month=all&q=' . rawurlencode('_'))->body);
});

test('a recurring expense backfills past dates, shows its schedule, and can be stopped', function () {
    $user = make_user('recurweb');
    $http = (new HttpClient())->login($user['username'], $user['password']);
    $start = today()->modify('first day of this month')->modify('-2 months')->format('Y-m-d');

    $http->get('/expenses')->post('/expenses', ['action' => 'save', 'amount' => '1450', 'date' => $start, 'primary' => 'Rent', 'description' => 'Apartment rent', 'repeat' => 'monthly'])->follow();
    expect_contains('Repeats every month', $http->body);
    expect_contains('Added 2 earlier entries', $http->body);
    expect_same(3, count_rows("SELECT COUNT(*) FROM expenses WHERE user_id = ? AND description = 'Apartment rent'", [$user['id']]));

    $http->get('/expenses');
    expect_contains('Recurring', $http->body);
    expect_contains('Every month · Next:', $http->body);

    $template = count_rows('SELECT id FROM expenses WHERE user_id = ? AND is_recurring = 1', [$user['id']]);
    $http->post('/expenses', ['action' => 'stop_recurring', 'id' => $template, 'return' => '/expenses'])->follow();
    expect_contains('Stopped repeating', $http->body);
    expect_same(0, count_rows('SELECT COUNT(*) FROM expenses WHERE user_id = ? AND is_recurring = 1', [$user['id']]));
    expect_same(3, count_rows("SELECT COUNT(*) FROM expenses WHERE user_id = ? AND description = 'Apartment rent'", [$user['id']]), 'past entries stay');
});

test('budgets reject bad dates and trigger alerts when saved over the limit', function () {
    $user = make_user('budgetweb');
    [$start, $end] = month_bounds(today());
    add_expense($user, 900, $start);
    $http = (new HttpClient())->login($user['username'], $user['password']);

    $http->get('/budgets')->post('/budgets', ['action' => 'save', 'name' => 'Month', 'period_type' => 'custom', 'start_date' => $end, 'end_date' => $start, 'total_limit' => '500'])->follow();
    expect_contains('The end date must be on or after the start date', $http->body);

    $http->post('/budgets', ['action' => 'save', 'name' => 'Month', 'period_type' => 'monthly', 'start_date' => $start, 'end_date' => $end, 'total_limit' => '500'])->follow();
    expect_contains('Budget created', $http->body);
    expect_contains('Over limit', $http->body);

    $http->get('/notifications');
    expect_contains('is over its limit', $http->body);
    $notification = count_rows('SELECT id FROM notifications WHERE user_id = ?', [$user['id']]);
    $http->get("/notifications?open=$notification");
    expect_same('/budgets', $http->redirectPath());
    expect_same(0, unread_notification_count($user['id']));
});

test('savings goals can be created and funded to completion', function () {
    $user = make_user('goalweb');
    $http = (new HttpClient())->login($user['username'], $user['password']);
    $http->get('/savings')->post('/savings', ['action' => 'goal_save', 'goal_name' => 'Bike', 'target_amount' => '500', 'current_amount' => '100'])->follow();
    expect_contains('Goal added', $http->body);
    $goal = count_rows('SELECT id FROM financial_goals WHERE user_id = ?', [$user['id']]);
    $http->post('/savings', ['action' => 'goal_contribute', 'id' => $goal, 'amount' => '400'])->follow();
    expect_contains('You reached', $http->body);
    expect_same(1, count_rows("SELECT COUNT(*) FROM financial_goals WHERE id = ? AND status = 'completed'", [$goal]));
});

test('reports export CSV with formula protection and a real PDF', function () {
    $user = make_user('exports');
    add_expense($user, 12.5, today()->format('Y-m-d'), 'Groceries', '=HYPERLINK("http://evil.example")');
    $http = (new HttpClient())->login($user['username'], $user['password']);

    $http->get('/reports?range=this-month&export=csv');
    expect_contains('text/csv', $http->headers['content-type']);
    expect_contains("'=HYPERLINK", $http->body);

    $http->get('/reports?range=this-month&export=pdf');
    expect_same('application/pdf', $http->headers['content-type']);
    expect_same('%PDF-1.4', substr($http->body, 0, 8));
    expect_contains('attachment; filename="finpulse_', $http->headers['content-disposition']);
});

test('notification and currency settings are saved', function () {
    $user = make_user('prefs');
    $http = (new HttpClient())->login($user['username'], $user['password']);
    $http->get('/settings')->post('/settings', ['action' => 'notifications', 'budget_alerts' => '1'])->follow();
    expect_contains('Notification settings saved', $http->body);
    $stored = db()->prepare('SELECT notification_preferences FROM users WHERE id = ?');
    $stored->execute([$user['id']]);
    $prefs = json_decode($stored->fetchColumn(), true);
    $stored->closeCursor();
    expect_same(['budget_alerts' => true, 'upcoming_payments' => false, 'monthly_summary' => false, 'email' => false], $prefs);

    $http->post('/settings', ['action' => 'test_email'])->follow();
    expect_contains('Email is in log mode', $http->body);

    $http->post('/settings', ['action' => 'preferences', 'currency' => 'BDT'])->follow();
    add_income($user, 10, today()->format('Y-m-d'));
    expect_contains('৳', $http->get('/income')->body);
});
