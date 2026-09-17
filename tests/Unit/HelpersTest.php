<?php

test('parse_amount accepts plain, decimal and comma-grouped amounts', function () {
    expect_same(42.0, parse_amount('42'));
    expect_same(42.5, parse_amount('42.50'));
    expect_same(1234.5, parse_amount('1,234.5'));
    expect_same(0.01, parse_amount(' 0.01 '));
});

test('parse_amount rejects zero, negatives, junk and too many decimals', function () {
    foreach (['', '0', '-5', 'abc', '12.345', '1e5', '12..3', '99999999999'] as $raw) {
        expect_same(null, parse_amount($raw), "input '$raw'");
    }
    expect_same(0.0, parse_amount('0', allow_zero: true));
});

test('is_valid_date only accepts real Y-m-d dates', function () {
    expect_true(is_valid_date('2024-02-29'));
    expect_false(is_valid_date('2023-02-29'));
    expect_false(is_valid_date('2024-13-01'));
    expect_false(is_valid_date('17/09/2026'));
    expect_false(is_valid_date(''));
});

test('money formats signs and currencies', function () {
    use_currency('USD');
    expect_same('$1,234.50', money(1234.5));
    expect_same('−$12.00', money(-12));
    expect_same('+$3.10', money(3.1, 'always'));
    expect_same('$12.00', money(-12, 'none'));
    use_currency('EUR');
    expect_same('€9.99', money(9.99));
    use_currency('AED');
    expect_same('AED 100.00', money(100), 'letter symbols get a space');
    use_currency('XXX');
    expect_same('$1.00', money(1), 'unknown currency falls back to USD');
});

test('csv_safe neutralises spreadsheet formulas but keeps numbers', function () {
    expect_same("'=HYPERLINK(\"x\")", csv_safe('=HYPERLINK("x")'));
    expect_same("'+cmd", csv_safe('+cmd'));
    expect_same("'@SUM(A1)", csv_safe('@SUM(A1)'));
    expect_same('-12.50', csv_safe('-12.50'));
    expect_same('Groceries', csv_safe('Groceries'));
    expect_same('', csv_safe(null));
});

test('day_label names nearby days', function () {
    $today = today();
    expect_same('Today', day_label($today->format('Y-m-d')));
    expect_same('Yesterday', day_label($today->modify('-1 day')->format('Y-m-d')));
    expect_same('Tomorrow', day_label($today->modify('+1 day')->format('Y-m-d')));
    expect_same('Mon, Jan 5, 2015', day_label('2015-01-05'));
});

test('plural and percent', function () {
    expect_same('1 entry', plural(1, 'entry', 'entries'));
    expect_same('2,500 entries', plural(2500, 'entry', 'entries'));
    expect_same('3 days', plural(3, 'day'));
    expect_same(33, percent(1, 3));
    expect_same(0, percent(5, 0));
});

test('url builds clean paths with query and fragment', function () {
    expect_same('/expenses', url('expenses'));
    expect_same('/', url());
    expect_same('/reports?range=12m&export=csv', url('reports', ['range' => '12m', 'export' => 'csv', 'empty' => '']));
    expect_same('/budgets?new=1#budget-form', url('budgets', ['new' => 1], 'budget-form'));
});

test('every route has a page file', function () {
    foreach (routes() as $path => [$page]) {
        expect_true(is_file(APP_ROOT . "/app/pages/$page.php"), "page for /$path");
    }
});
