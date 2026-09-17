<?php
/**
 * Notifications: in-app messages plus optional email for budget alerts,
 * upcoming recurring payments, a monthly summary, and security notices.
 */
defined('FINPULSE') || exit;

/** Preference key => [label, description]. Every type is on by default. */
function notification_types(): array
{
    return [
        'budget_alerts' => ['Budget alerts', 'When a budget passes 80% or goes over its limit, or a category goes over its cap.'],
        'upcoming_payments' => ['Upcoming payments', 'Three days before a recurring expense is added.'],
        'monthly_summary' => ['Monthly summary', 'On the 1st: what came in, what went out and your biggest categories.'],
    ];
}

/** @return array{budget_alerts: bool, upcoming_payments: bool, monthly_summary: bool, email: bool} */
function notification_prefs(array $user): array
{
    $stored = json_decode((string) ($user['notification_preferences'] ?? ''), true);
    $stored = is_array($stored) ? $stored : [];
    // 1.x stored "reminders" and "budget_alerts"; carry those choices over.
    if (!array_key_exists('upcoming_payments', $stored) && array_key_exists('reminders', $stored)) {
        $stored['upcoming_payments'] = $stored['reminders'];
    }
    $prefs = [];
    foreach (array_keys(notification_types()) as $key) {
        $prefs[$key] = (bool) ($stored[$key] ?? true);
    }
    $prefs['email'] = (bool) ($stored['email'] ?? true);
    return $prefs;
}

function save_notification_prefs(int $user_id, array $prefs): void
{
    db()->prepare('UPDATE users SET notification_preferences = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
        ->execute([json_encode($prefs), $user_id]);
}

/**
 * Create a notification once per dedupe key. Returns false when it already existed.
 * $email: also queue an email (when the user has email notifications on).
 * $silent: record it as already read and never email (used to suppress stale alerts).
 */
function notify(array $user, string $type, string $dedupe_key, string $title, string $body, string $link, bool $email = true, bool $silent = false, array $email_rows = []): bool
{
    $exists = db()->prepare('SELECT 1 FROM notifications WHERE user_id = ? AND dedupe_key = ?');
    $exists->execute([$user['id'], $dedupe_key]);
    if ($exists->fetchColumn()) {
        return false;
    }
    try {
        $now = date('Y-m-d H:i:s');
        db()->prepare('INSERT INTO notifications (user_id, type, title, body, link, dedupe_key, read_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$user['id'], $type, $title, $body, $link, $dedupe_key, $silent ? $now : null, $now]);
    } catch (PDOException) {
        return false; // Lost a race with another request creating the same notification.
    }

    if ($email && !$silent && notification_prefs($user)['email']) {
        queue_mail($user['email'], $user['username'], $title, email_template($title, [$body], ['Open FinPulse', app_url() . '/' . ltrim($link, '/')], $email_rows));
    }
    return true;
}

function unread_notification_count(int $user_id): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
    $stmt->execute([$user_id]);
    return (int) $stmt->fetchColumn();
}

/** Run every check this user has switched on. Each check is idempotent. */
function run_notification_checks(array $user, ?string $today = null): void
{
    $prefs = notification_prefs($user);
    $today ??= today()->format('Y-m-d');
    use_currency($user['currency'] ?? 'USD');
    if ($prefs['budget_alerts']) {
        check_budget_alerts($user, $today);
    }
    if ($prefs['upcoming_payments']) {
        check_upcoming_payments($user, $today);
    }
    if ($prefs['monthly_summary']) {
        check_monthly_summary($user, $today);
    }
}

function check_budget_alerts(array $user, string $today): void
{
    $stmt = db()->prepare('SELECT * FROM budgets WHERE user_id = ? AND start_date <= ? AND end_date >= ?');
    $stmt->execute([$user['id'], $today, $today]);

    foreach ($stmt->fetchAll() as $budget) {
        $status = budget_status($budget, $user['id'], $today);
        $name = '“' . $budget['name'] . '”';
        $key = 'budget:' . $budget['id'];
        $rows = ['Spent' => money($status['spent']), 'Limit' => money($status['limit']), 'Period' => fmt_date($budget['start_date'], 'M j') . ' – ' . fmt_date($budget['end_date'], 'M j')];

        if ($status['limit'] > 0 && $status['spent'] > $status['limit']) {
            notify($user, 'budget', "$key:100", "$name is over its limit", 'You\'ve spent ' . money($status['spent']) . ' of ' . money($status['limit']) . ', ' . money($status['spent'] - $status['limit']) . ' over.', 'budgets', true, false, $rows);
            // Don't send the 80% warning afterwards if it never went out.
            notify($user, 'budget', "$key:80", "You've used 80% of $name", 'Recorded when the budget went over its limit.', 'budgets', false, true);
        } elseif ($status['pct'] >= 80) {
            notify($user, 'budget', "$key:80", "You've used {$status['pct']}% of $name", $status['detail'] . '.', 'budgets', true, false, $rows);
        }

        $limits = db()->prepare('SELECT bc.expense_category_id, bc.limit_amount, c.name FROM budget_categories bc JOIN expense_categories c ON c.id = bc.expense_category_id WHERE bc.budget_id = ?');
        $limits->execute([$budget['id']]);
        foreach ($limits->fetchAll() as $limit) {
            $spent = $status['by_category'][(int) $limit['expense_category_id']] ?? 0.0;
            if ($spent > (float) $limit['limit_amount']) {
                notify($user, 'budget', "$key:cat:{$limit['expense_category_id']}", $limit['name'] . ' is over its cap in ' . $name, 'You\'ve spent ' . money($spent) . ' on ' . $limit['name'] . ' against a cap of ' . money($limit['limit_amount']) . '.', 'budgets');
            }
        }
    }
}

function check_upcoming_payments(array $user, string $today): void
{
    $until = (new DateTimeImmutable($today))->modify('+3 days')->format('Y-m-d');
    foreach (recurring_schedules($user['id'], 'expenses') as $schedule) {
        $due = $schedule['next_recurrence_date'];
        if ($due <= $today || $due > $until) {
            continue;
        }
        $name = $schedule['description'] ?: ($schedule['label'] ?: 'A recurring expense');
        $when = days_between($today, $due) === 1 ? 'tomorrow' : 'on ' . fmt_date($due, 'l, M j');
        notify(
            $user,
            'upcoming',
            "upcoming:{$schedule['id']}:$due",
            "$name (" . money($schedule['amount']) . ") is due $when",
            'It repeats ' . strtolower(recurrence_label($schedule['recurrence_period'])) . ' and will be added to your expenses automatically.',
            'expenses'
        );
    }
}

function check_monthly_summary(array $user, string $today): void
{
    $month = (new DateTimeImmutable($today))->modify('first day of last month');
    [$start, $end] = month_bounds($month);
    // Only summarise months the account existed for, and only once there's something to say.
    if (substr((string) $user['created_at'], 0, 10) > $end) {
        return;
    }
    $key = 'summary:' . $month->format('Y-m');
    $exists = db()->prepare('SELECT 1 FROM notifications WHERE user_id = ? AND dedupe_key = ?');
    $exists->execute([$user['id'], $key]);
    if ($exists->fetchColumn()) {
        return;
    }

    $report = build_report($user['id'], $start, $end);
    if (!$report['has_data']) {
        return;
    }
    $top = array_key_first($report['by_category']);
    $rows = [
        'Money in' => money($report['total_income']),
        'Money out' => money($report['total_spent']),
        'Kept' => money($report['net']) . ($report['savings_rate'] !== null ? ' (' . $report['savings_rate'] . '%)' : ''),
    ];
    if ($top !== null) {
        $rows['Biggest category'] = $top . ' · ' . money($report['by_category'][$top]['total']);
    }
    $body = $report['net'] >= 0
        ? 'You kept ' . money($report['net']) . ' of the ' . money($report['total_income']) . ' that came in.'
        : 'You spent ' . money(-$report['net']) . ' more than came in.';

    notify($user, 'summary', $key, 'Your ' . $month->format('F') . ' summary', $body, 'reports?range=last-month', true, false, $rows);
}

/** Always sent, regardless of preferences: someone should hear about a password change. */
function notify_password_changed(int $user_id, string $email, string $username): void
{
    $stmt = db()->prepare('SELECT id, username, email, notification_preferences FROM users WHERE id = ?');
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    if (!$user) {
        return;
    }
    notify($user, 'security', 'password:' . time() . ':' . bin2hex(random_bytes(3)), 'Your password was changed', 'The password for ' . $username . ' was changed on ' . date('M j, Y \a\t g:i a') . '. Every other device was signed out. If this wasn\'t you, reset your password right away.', 'settings', false);
    queue_mail($email, $username, 'Your FinPulse password was changed', email_template(
        'Your password was changed',
        ['The password for your FinPulse account ' . $username . ' was just changed, and every other signed-in device was signed out.', 'If you didn\'t do this, reset your password now.'],
        ['Reset password', absolute_url('forgot-password')]
    ));
}

function notification_icon(string $type): string
{
    return ['budget' => 'budget', 'upcoming' => 'calendar', 'summary' => 'reports', 'security' => 'lock', 'test' => 'bell'][$type] ?? 'bell';
}
