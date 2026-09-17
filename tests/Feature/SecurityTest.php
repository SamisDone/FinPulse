<?php

test('forms without a valid CSRF token change nothing', function () {
    $user = make_user('csrf');
    $http = (new HttpClient())->login($user['username'], $user['password']);
    $http->post('/expenses', ['action' => 'save', 'amount' => '5', 'date' => '2026-09-01', 'primary' => 'X', '_token' => str_repeat('0', 64)])->follow();
    expect_contains('Your session expired', $http->body);
    expect_same(0, count_rows('SELECT COUNT(*) FROM expenses WHERE user_id = ?', [$user['id']]));
});

test("users can't see or change each other's data", function () {
    $owner = make_user('owner');
    $intruder = make_user('intruder');
    $expense = add_expense($owner, 777.77, '2026-09-01', 'Rent', 'Private rent');
    db()->prepare("INSERT INTO budgets (user_id, name, period_type, start_date, end_date, total_limit) VALUES (?, 'Private budget', 'monthly', '2026-09-01', '2026-09-30', 100)")->execute([$owner['id']]);
    $budget = (int) db()->lastInsertId();
    db()->prepare("INSERT INTO financial_goals (user_id, goal_name, target_amount, current_amount) VALUES (?, 'Private goal', 100, 0)")->execute([$owner['id']]);
    $goal = (int) db()->lastInsertId();
    notify($owner, 'test', 'private', 'Private notice', '', 'dashboard', false);
    $notice = count_rows('SELECT id FROM notifications WHERE user_id = ?', [$owner['id']]);

    $http = (new HttpClient())->login($intruder['username'], $intruder['password']);
    expect_not_contains('Private rent', $http->get("/expenses?month=all&edit=$expense")->body);
    expect_not_contains('777.77', $http->body);

    $http->post('/expenses', ['action' => 'save', 'id' => $expense, 'amount' => '1', 'date' => '2026-09-01', 'primary' => 'X']);
    $http->post('/expenses', ['action' => 'delete', 'id' => $expense]);
    $http->post('/budgets', ['action' => 'save', 'id' => $budget, 'name' => 'Hacked', 'period_type' => 'monthly', 'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'total_limit' => '1']);
    $http->post('/budgets', ['action' => 'delete', 'id' => $budget]);
    $http->post('/savings', ['action' => 'goal_contribute', 'id' => $goal, 'amount' => '50']);
    $http->get("/notifications?open=$notice");

    expect_same(777.77, (float) scalar('SELECT amount FROM expenses WHERE id = ?', [$expense]));
    expect_same(1, count_rows("SELECT COUNT(*) FROM budgets WHERE id = ? AND name = 'Private budget'", [$budget]));
    expect_same(0.0, (float) scalar('SELECT current_amount FROM financial_goals WHERE id = ?', [$goal]));
    expect_same(1, count_rows('SELECT COUNT(*) FROM notifications WHERE id = ? AND read_at IS NULL', [$notice]));
});

test('redirect targets from forms and sign-in stay inside the app', function () {
    $user = make_user('redirects');
    $http = (new HttpClient())->login($user['username'], $user['password']);
    add_expense($user, 3, '2026-09-01');
    $id = count_rows('SELECT MAX(id) FROM expenses WHERE user_id = ?', [$user['id']]);
    $http->get('/expenses')->post('/expenses', ['action' => 'delete', 'id' => $id, 'return' => 'https://evil.example/']);
    expect_same('/expenses', $http->redirectPath());
    expect_not_contains('evil.example', $http->headers['location']);
});

test('output is escaped everywhere user text appears', function () {
    $user = make_user('xss');
    add_expense($user, 1, today()->format('Y-m-d'), '<img src=x onerror=alert(1)>', '<script>alert("note")</script>');
    $http = (new HttpClient())->login($user['username'], $user['password']);
    foreach (['/expenses', '/dashboard', '/reports'] as $path) {
        $body = $http->get($path)->body;
        expect_not_contains('<script>alert("note")</script>', $body, $path);
        expect_not_contains('<img src=x onerror', $body, $path);
    }
});

test('code, data and scripts outside public/ cannot be reached over HTTP', function () {
    $http = new HttpClient();
    foreach (['/app/core/db.php', '/../app/core/db.php', '/database/schema.sqlite.sql', '/scripts/seed-demo.php', '/.env', '/tests/run.php', '/storage/sixpence.db'] as $path) {
        $http->get($path);
        expect_true(in_array($http->status, [400, 403, 404], true), "$path returned {$http->status}");
        expect_not_contains('CREATE TABLE', $http->body, $path);
        expect_not_contains('<?php', $http->body, $path);
    }
});
