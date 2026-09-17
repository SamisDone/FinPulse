<?php

function make_budget(array $user, float $limit, string $start, string $end, array $caps = []): array
{
    db()->prepare("INSERT INTO budgets (user_id, name, period_type, start_date, end_date, total_limit) VALUES (?, 'Test budget', 'monthly', ?, ?, ?)")
        ->execute([$user['id'], $start, $end, $limit]);
    $id = (int) db()->lastInsertId();
    foreach ($caps as $category => $cap) {
        db()->prepare('INSERT INTO budget_categories (budget_id, expense_category_id, limit_amount) VALUES (?, ?, ?)')
            ->execute([$id, lookup_id($user['id'], 'expense_categories', $category), $cap]);
    }
    $stmt = db()->prepare('SELECT * FROM budgets WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
}

test('budget_status is on pace when spending tracks the calendar', function () {
    $user = make_user('pace');
    $budget = make_budget($user, 3000, '2026-09-01', '2026-09-30');
    add_expense($user, 1000, '2026-09-05');
    $status = budget_status($budget, $user['id'], '2026-09-15');
    expect_same('active', $status['state']);
    expect_same('good', $status['tone']);
    expect_same(33, $status['pct']);
    expect_same(50, $status['elapsed_pct']);
    expect_contains('/day left for 15 days', $status['detail']);
});

test('budget_status flags spending fast and over limit', function () {
    $user = make_user('fast');
    $budget = make_budget($user, 1000, '2026-09-01', '2026-09-30');
    add_expense($user, 700, '2026-09-02');
    expect_same('warn', budget_status($budget, $user['id'], '2026-09-10')['tone']);
    add_expense($user, 400, '2026-09-03');
    $over = budget_status($budget, $user['id'], '2026-09-10');
    expect_same('bad', $over['tone']);
    expect_same('Over by $100.00', $over['detail']);
    expect_same('Went over', budget_status($budget, $user['id'], '2026-10-02')['label']);
});

test('budget_status ignores expenses outside the period and from other users', function () {
    $user = make_user('scope');
    $other = make_user('other');
    $budget = make_budget($user, 500, '2026-09-01', '2026-09-30');
    add_expense($user, 50, '2026-08-31');
    add_expense($other, 999, '2026-09-10');
    add_expense($user, 20, '2026-09-10');
    expect_same(20.0, budget_status($budget, $user['id'], '2026-09-12')['spent']);
});

test('goal_status works out a monthly amount and completion', function () {
    $in_ten_months = today()->modify('+10 months')->format('Y-m-d');
    $goal = goal_status(['target_amount' => 1200, 'current_amount' => 200, 'target_date' => $in_ten_months, 'status' => 'active']);
    expect_same(16, $goal['pct']);
    expect_false($goal['done']);
    expect_same(100.0, round($goal['per_month'], 2));

    expect_true(goal_status(['target_amount' => 100, 'current_amount' => 100, 'target_date' => null, 'status' => 'active'])['done']);
    expect_true(goal_status(['target_amount' => 100, 'current_amount' => 10, 'target_date' => '2000-01-01', 'status' => 'active'])['overdue']);
});

test('report range presets and custom ranges', function () {
    $today = new DateTimeImmutable('2026-09-17');
    expect_same(['this-month', '2026-09-01', '2026-09-17'], resolve_report_range('this-month', '', '', $today));
    expect_same(['last-month', '2026-08-01', '2026-08-31'], resolve_report_range('last-month', '', '', $today));
    expect_same(['12m', '2025-10-01', '2026-09-17'], resolve_report_range('12m', '', '', $today));
    expect_same(['custom', '2026-01-01', '2026-02-01'], resolve_report_range('custom', '2026-02-01', '2026-01-01', $today), 'reversed dates are swapped');
    expect_same('this-month', resolve_report_range('bogus', '', '', $today)[0]);
});

test('build_report totals, buckets and previous period', function () {
    $user = make_user('report');
    add_income($user, 3000, '2026-08-01');
    add_expense($user, 500, '2026-08-10', 'Rent');
    add_expense($user, 250, '2026-08-20', 'Groceries');
    add_expense($user, 100, '2026-07-15', 'Groceries');

    $report = build_report($user['id'], '2026-08-01', '2026-08-31');
    expect_same(3000.0, $report['total_income']);
    expect_same(750.0, $report['total_spent']);
    expect_same(75, $report['savings_rate']);
    expect_same('day', $report['granularity']);
    expect_same(31, count($report['buckets']));
    expect_same(['Rent', 'Groceries'], array_keys($report['by_category']));
    expect_same(100.0, $report['prev_spent'], 'previous 31 days');
    expect_same(2250.0, end($report['running_series']));

    expect_same('month', build_report($user['id'], '2025-09-01', '2026-08-31')['granularity']);
    $rows = report_csv_rows($report);
    expect_same(3, count($rows));
    expect_same(['Income', '2026-08-01', '3000.00'], array_slice($rows[0], 0, 3), 'sorted by date, income positive');
    expect_same('-500.00', $rows[1][2], 'expenses are negative');
});
