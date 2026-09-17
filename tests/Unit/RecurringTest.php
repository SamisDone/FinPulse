<?php

test('monthly recurrence keeps the original day and clamps short months', function () {
    expect_same('2026-01-31', recurrence_date('2026-01-31', 'monthly', 0));
    expect_same('2026-02-28', recurrence_date('2026-01-31', 'monthly', 1));
    expect_same('2026-03-31', recurrence_date('2026-01-31', 'monthly', 2), 'returns to the 31st after February');
    expect_same('2026-04-30', recurrence_date('2026-01-31', 'monthly', 3));
    expect_same('2027-01-31', recurrence_date('2026-01-31', 'monthly', 12));
});

test('weekly, biweekly, quarterly and yearly recurrence', function () {
    expect_same('2026-09-24', recurrence_date('2026-09-17', 'weekly', 1));
    expect_same('2026-10-01', recurrence_date('2026-09-17', 'biweekly', 1));
    expect_same('2026-12-15', recurrence_date('2026-09-15', 'quarterly', 1));
    expect_same('2025-02-28', recurrence_date('2024-02-29', 'yearly', 1), 'leap day in a normal year');
    expect_same('2028-02-29', recurrence_date('2024-02-29', 'yearly', 4));
});

test('next_occurrence_after skips past dates', function () {
    expect_same('2026-10-01', next_occurrence_after('2026-01-01', 'monthly', '2026-09-17'));
    expect_same('2026-09-24', next_occurrence_after('2026-09-17', 'weekly', '2026-09-17'), 'strictly after, never the same day');
});

test('process_recurring catches up every missed occurrence exactly once', function () {
    $user = make_user('recur');
    db()->prepare("INSERT INTO expenses (user_id, amount, expense_date, description, is_recurring, recurrence_period, next_recurrence_date) VALUES (?, 1450, '2026-01-31', 'Rent', 1, 'monthly', '2026-02-28')")
        ->execute([$user['id']]);
    $template = (int) db()->lastInsertId();

    $created = process_recurring($user['id'], '2026-05-10');
    expect_same(3, $created[$user['id']]['count'], 'Feb, Mar and Apr');

    $dates = db()->prepare('SELECT expense_date FROM expenses WHERE recurring_source_id = ? ORDER BY expense_date');
    $dates->execute([$template]);
    expect_same(['2026-02-28', '2026-03-31', '2026-04-30'], $dates->fetchAll(PDO::FETCH_COLUMN));

    $next = db()->prepare('SELECT next_recurrence_date FROM expenses WHERE id = ?');
    $next->execute([$template]);
    expect_same('2026-05-31', $next->fetchColumn());

    expect_same([], process_recurring($user['id'], '2026-05-10'), 'second run adds nothing');
    expect_same(1, process_recurring($user['id'], '2026-05-31')[$user['id']]['count']);
});

test('stopped schedules and other users are left alone', function () {
    $alice = make_user('alice');
    $bob = make_user('bob');
    db()->prepare("INSERT INTO income (user_id, amount, income_date, is_recurring, recurrence_period, next_recurrence_date) VALUES (?, 100, '2026-01-01', 0, 'weekly', '2026-01-08')")->execute([$alice['id']]);
    db()->prepare("INSERT INTO income (user_id, amount, income_date, is_recurring, recurrence_period, next_recurrence_date) VALUES (?, 100, '2026-01-01', 1, 'weekly', '2026-01-08')")->execute([$bob['id']]);

    expect_same([], process_recurring($alice['id'], '2026-02-01'));
    expect_same(0, count_rows('SELECT COUNT(*) FROM income WHERE user_id = ? AND recurring_source_id IS NOT NULL', [$bob['id']]), 'scoped to alice');
    expect_same(4, process_recurring($bob['id'], '2026-02-01')[$bob['id']]['count']);
});

test('schedule_next_date continues after copies that already exist', function () {
    $user = make_user('sched');
    db()->prepare("INSERT INTO expenses (user_id, amount, expense_date, is_recurring, recurrence_period, next_recurrence_date) VALUES (?, 10, '2026-01-10', 1, 'monthly', '2026-02-10')")->execute([$user['id']]);
    $id = (int) db()->lastInsertId();
    process_recurring($user['id'], '2026-03-15');
    expect_same('2026-04-10', schedule_next_date('expenses', $id, '2026-01-10', 'monthly'));
});
