<?php
/**
 * Create a demo account filled with a year of realistic data.
 *
 *   php scripts/seed-demo.php            # creates user "demo" (password: Demo#2026)
 *   php scripts/seed-demo.php --reset    # deletes and recreates the demo user
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/core/bootstrap.php';

const DEMO_USER = 'demo';
const DEMO_PASSWORD = 'Demo#2026';

$reset = in_array('--reset', $argv, true);
$pdo = db();

$existing = $pdo->prepare('SELECT id FROM users WHERE username = ?');
$existing->execute([DEMO_USER]);
if ($id = $existing->fetchColumn()) {
    if (!$reset) {
        fwrite(STDERR, "User '" . DEMO_USER . "' already exists. Run with --reset to recreate it.\n");
        exit(1);
    }
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
}

[$uid, $errors] = register_user(DEMO_USER, 'demo@finpulse.local', DEMO_PASSWORD, DEMO_PASSWORD);
if ($errors) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(1);
}

mt_srand(20260917);
$today = today();
$start = $today->modify('first day of this month')->modify('-12 months');

$pdo->beginTransaction();

$expense = $pdo->prepare('INSERT INTO expenses (user_id, amount, expense_date, category_id, payment_method_id, description) VALUES (?, ?, ?, ?, ?, ?)');
$income = $pdo->prepare('INSERT INTO income (user_id, amount, income_date, source_id, category_id, description) VALUES (?, ?, ?, ?, ?, ?)');

$spend = static function (DateTimeImmutable $date, float $amount, string $category, string $method, string $note) use ($expense, $uid, $today): void {
    if ($date > $today) {
        return;
    }
    $expense->execute([$uid, round($amount, 2), $date->format('Y-m-d'), lookup_id($uid, 'expense_categories', $category), lookup_id($uid, 'payment_methods', $method), $note]);
};
$earn = static function (DateTimeImmutable $date, float $amount, string $source, string $category, string $note) use ($income, $uid, $today): void {
    if ($date > $today) {
        return;
    }
    $income->execute([$uid, round($amount, 2), $date->format('Y-m-d'), lookup_id($uid, 'income_sources', $source), lookup_id($uid, 'income_categories', $category), $note]);
};
$rand = static fn(float $min, float $max): float => $min + mt_rand() / mt_getrandmax() * ($max - $min);
$pick = static fn(array $items) => $items[array_rand($items)];

$grocers = ["Trader Joe's", 'Whole Foods', 'Farmers market', 'Aldi', 'Corner store'];
$restaurants = ['Pho Saigon', 'Blue Bottle Coffee', 'Sweetgreen', 'Joe\'s Pizza', 'Dinner with Priya', 'Thai Basil', 'Bagel run'];
$shops = ['Target', 'Uniqlo', 'Amazon order', 'IKEA', 'Bookshop', 'Hardware store'];
$fun = ['Movie tickets', 'Concert tickets', 'Bowling night', 'Museum entry', 'Board game café'];

// Fixed bills and the paycheck are real recurring schedules; the recurring engine fills in their history.
$schedule = static function (string $table, string $date, float $amount, string $primary, ?string $secondary, string $note, string $period) use ($pdo, $uid): void {
    [$d, $c1, $t1, $c2, $t2] = $table === 'income'
        ? ['income_date', 'source_id', 'income_sources', 'category_id', 'income_categories']
        : ['expense_date', 'category_id', 'expense_categories', 'payment_method_id', 'payment_methods'];
    $pdo->prepare("INSERT INTO $table (user_id, amount, $d, $c1, $c2, description, is_recurring, recurrence_period, next_recurrence_date) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)")
        ->execute([$uid, $amount, $date, lookup_id($uid, $t1, $primary), $secondary ? lookup_id($uid, $t2, $secondary) : null, $note, $period, recurrence_date($date, $period, 1)]);
};
$first = static fn(int $d) => $start->setDate((int) $start->format('Y'), (int) $start->format('m'), $d)->format('Y-m-d');
$schedule('income', $first(1), 4380.00, 'Salary', 'Primary job', 'Paycheck', 'monthly');
$schedule('expenses', $first(1), 1450.00, 'Rent', 'Bank transfer', 'Apartment rent', 'monthly');
$schedule('expenses', $first(2), 89.00, 'Transport', 'Mobile wallet', 'Monthly transit pass', 'monthly');
$schedule('expenses', $first(3), 39.00, 'Health', 'Credit card', 'Gym membership', 'monthly');
$schedule('expenses', $first(12), 59.99, 'Utilities', 'Credit card', 'Home internet', 'monthly');
$schedule('expenses', $first(18), 15.49, 'Entertainment', 'Credit card', 'Netflix', 'monthly');
$schedule('expenses', $first(21), 10.99, 'Entertainment', 'Credit card', 'Spotify', 'monthly');

for ($month = $start; $month <= $today; $month = $month->modify('+1 month')) {
    $day = static fn(int $d) => $month->setDate((int) $month->format('Y'), (int) $month->format('m'), min($d, (int) $month->format('t')));
    $earn($day(28), 11.42 + $rand(0, 3), 'Interest', 'Passive', 'Savings interest');
    for ($i = 0, $n = mt_rand(0, 2); $i < $n; $i++) {
        $earn($day(mt_rand(6, 26)), round($rand(380, 1250) / 5) * 5, 'Freelance', 'Side work', $pick(['Logo design for Northwind', 'Website fixes', 'Illustration commission', 'Consulting call']));
    }

    $spend($day(5), $rand(92, 138), 'Utilities', 'Bank transfer', 'Electricity & gas');

    for ($d = 1; $d <= (int) $month->format('t'); $d += mt_rand(3, 4)) {
        $spend($day($d), $rand(38, 124), 'Groceries', 'Debit card', $pick($grocers));
    }
    for ($i = 0, $n = mt_rand(4, 8); $i < $n; $i++) {
        $spend($day(mt_rand(1, 28)), $rand(6.5, 68), 'Dining out', $pick(['Credit card', 'Debit card']), $pick($restaurants));
    }
    for ($i = 0, $n = mt_rand(1, 3); $i < $n; $i++) {
        $spend($day(mt_rand(1, 28)), $rand(22, 180), 'Shopping', 'Credit card', $pick($shops));
    }
    for ($i = 0, $n = mt_rand(1, 3); $i < $n; $i++) {
        $spend($day(mt_rand(1, 28)), $rand(9, 26), 'Transport', 'Mobile wallet', $pick(['Uber home', 'Lyft to airport', 'Bike share']));
    }
    if (mt_rand(0, 1)) {
        $spend($day(mt_rand(8, 26)), $rand(14, 62), 'Entertainment', 'Credit card', $pick($fun));
    }
    if (mt_rand(0, 2) === 0) {
        $spend($day(mt_rand(4, 24)), $rand(9, 45), 'Health', 'Debit card', 'CVS Pharmacy');
    }
}

// Budgets
$budget = $pdo->prepare('INSERT INTO budgets (user_id, name, period_type, start_date, end_date, total_limit) VALUES (?, ?, ?, ?, ?, ?)');
$limit = $pdo->prepare('INSERT INTO budget_categories (budget_id, expense_category_id, limit_amount) VALUES (?, ?, ?)');
$add_budget = static function (string $name, string $period, string $from, string $to, float $total, array $limits) use ($budget, $limit, $pdo, $uid): void {
    $budget->execute([$uid, $name, $period, $from, $to, $total]);
    $bid = (int) $pdo->lastInsertId();
    foreach ($limits as $category => $amount) {
        $limit->execute([$bid, lookup_id($uid, 'expense_categories', $category), $amount]);
    }
};

[$ms, $me] = month_bounds($today);
$add_budget('Monthly spending', 'monthly', $ms, $me, 2950, ['Groceries' => 520, 'Dining out' => 220, 'Shopping' => 250, 'Transport' => 160, 'Entertainment' => 90]);
$week_start = $today->modify('monday this week');
$add_budget('This week', 'weekly', $week_start->format('Y-m-d'), $week_start->modify('+6 days')->format('Y-m-d'), 400, ['Dining out' => 80, 'Groceries' => 140]);
[$ls, $le] = month_bounds($today->modify('first day of last month'));
$add_budget('Monthly spending', 'monthly', $ls, $le, 2950, ['Groceries' => 520, 'Dining out' => 220, 'Shopping' => 250]);
$add_budget($today->format('Y') . ' spending', 'yearly', $today->format('Y') . '-01-01', $today->format('Y') . '-12-31', 36000, ['Shopping' => 2400, 'Entertainment' => 1200]);

// Savings
$account = $pdo->prepare('INSERT INTO savings_accounts (user_id, account_name, current_balance) VALUES (?, ?, ?)');
foreach ([['High-yield savings', 8420.55], ['Emergency fund', 10240.00], ['Brokerage account', 12948.31]] as [$name, $balance]) {
    $account->execute([$uid, $name, $balance]);
}

$goal = $pdo->prepare('INSERT INTO financial_goals (user_id, goal_name, target_amount, current_amount, target_date, description, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
$goal->execute([$uid, 'Six-month emergency fund', 15000, 10240, $today->modify('+8 months')->format('Y-m-d'), 'Covers rent and essentials if work dries up', 'active']);
$goal->execute([$uid, 'Two weeks in Japan', 4800, 1965, $today->modify('+11 months')->format('Y-m-d'), 'Tokyo, Kyoto, Kanazawa', 'active']);
$goal->execute([$uid, 'Replace the old bike', 900, 310, null, null, 'active']);
$goal->execute([$uid, 'New laptop', 2200, 2200, $today->modify('-2 months')->format('Y-m-d'), null, 'completed']);

$pdo->commit();

process_recurring($uid);
$user = db()->prepare('SELECT * FROM users WHERE id = ?');
$user->execute([$uid]);
run_notification_checks($user->fetch());
db()->prepare('DELETE FROM mail_queue WHERE to_email = ?')->execute(['demo@finpulse.local']);

echo "Demo account ready.\n  Username: " . DEMO_USER . "\n  Password: " . DEMO_PASSWORD . "\n";
