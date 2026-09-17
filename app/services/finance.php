<?php
/**
 * Domain calculations shared by the overview, budgets and savings pages.
 */
defined('SIXPENCE') || exit;

function total_between(string $table, int $user_id, string $from, string $to): float
{
    $column = ['income' => 'income_date', 'expenses' => 'expense_date'][$table] ?? throw new InvalidArgumentException($table);
    $stmt = db()->prepare("SELECT COALESCE(SUM(amount), 0) FROM $table WHERE user_id = ? AND $column BETWEEN ? AND ?");
    $stmt->execute([$user_id, $from, $to]);
    return (float) $stmt->fetchColumn();
}

/** @return array<int, float> expense totals keyed by category id (0 for uncategorised) */
function spending_by_category(int $user_id, string $from, string $to): array
{
    $stmt = db()->prepare('SELECT COALESCE(category_id, 0) AS cid, SUM(amount) AS total FROM expenses WHERE user_id = ? AND expense_date BETWEEN ? AND ? GROUP BY category_id');
    $stmt->execute([$user_id, $from, $to]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[(int) $row['cid']] = (float) $row['total'];
    }
    return $out;
}

/**
 * Where a budget stands today: spend, pace and a human status.
 *
 * @return array{spent: float, limit: float, remaining: float, pct: int, elapsed_pct: int, state: string,
 *               tone: string, label: string, detail: string, by_category: array<int, float>}
 */
function budget_status(array $budget, int $user_id, ?string $today = null): array
{
    $today ??= today()->format('Y-m-d');
    $limit = (float) $budget['total_limit'];
    $by_category = spending_by_category($user_id, $budget['start_date'], $budget['end_date']);
    $spent = array_sum($by_category);
    $remaining = $limit - $spent;
    $pct = $limit > 0 ? (int) round($spent / $limit * 100) : 0;

    $total_days = max(1, days_between($budget['start_date'], $budget['end_date']) + 1);

    if ($today < $budget['start_date']) {
        $state = 'upcoming';
        $elapsed_days = 0;
    } elseif ($today > $budget['end_date']) {
        $state = 'past';
        $elapsed_days = $total_days;
    } else {
        $state = 'active';
        $elapsed_days = days_between($budget['start_date'], $today) + 1;
    }
    $elapsed_pct = (int) round($elapsed_days / $total_days * 100);
    $days_left = $total_days - $elapsed_days;

    if ($state === 'upcoming') {
        [$tone, $label, $detail] = ['muted', 'Upcoming', 'Starts ' . fmt_date($budget['start_date'], 'M j')];
    } elseif ($spent > $limit) {
        [$tone, $label, $detail] = ['bad', $state === 'past' ? 'Went over' : 'Over limit', 'Over by ' . money($spent - $limit)];
    } elseif ($state === 'past') {
        [$tone, $label, $detail] = ['good', 'Stayed under', money($remaining) . ' left unspent'];
    } elseif ($limit > 0 && $pct > $elapsed_pct + 10) {
        $per_day = $days_left > 0 ? $remaining / $days_left : $remaining;
        [$tone, $label, $detail] = ['warn', 'Spending fast', money($per_day) . '/day left for ' . plural($days_left, 'day')];
    } else {
        $per_day = $days_left > 0 ? $remaining / $days_left : $remaining;
        [$tone, $label, $detail] = ['good', 'On pace', $days_left > 0 ? money($per_day) . '/day left for ' . plural($days_left, 'day') : money($remaining) . ' left today'];
    }

    return compact('spent', 'limit', 'remaining', 'pct', 'elapsed_pct', 'state', 'tone', 'label', 'detail', 'by_category');
}

/**
 * Progress and the monthly amount needed to hit a goal on time.
 *
 * @return array{pct: int, remaining: float, done: bool, per_month: ?float, months_left: ?int, overdue: bool, note: string}
 */
function goal_status(array $goal): array
{
    $target = (float) $goal['target_amount'];
    $current = (float) $goal['current_amount'];
    $remaining = max(0, $target - $current);
    $pct = $target > 0 ? min(100, (int) floor($current / $target * 100)) : 0;
    $done = $current >= $target || $goal['status'] === 'completed';

    $per_month = null;
    $months_left = null;
    $overdue = false;

    if ($done) {
        $note = 'Fully funded';
    } elseif (!empty($goal['target_date'])) {
        $today = today();
        $target_date = new DateTimeImmutable($goal['target_date']);
        if ($target_date < $today) {
            $overdue = true;
            $note = 'Target date passed · ' . money($remaining) . ' to go';
        } else {
            $diff = $today->diff($target_date);
            $months_left = max(1, $diff->y * 12 + $diff->m + ($diff->d > 0 ? 1 : 0));
            $per_month = $remaining / $months_left;
            $note = money($per_month) . '/month to reach it by ' . $target_date->format('M Y');
        }
    } else {
        $note = money($remaining) . ' to go · no target date';
    }

    return compact('pct', 'remaining', 'done', 'per_month', 'months_left', 'overdue', 'note');
}

function tone_class(string $tone): string
{
    return ['good' => 'tag-good', 'warn' => 'tag-warn', 'bad' => 'tag-bad'][$tone] ?? '';
}

function bar_class(string $tone): string
{
    return ['warn' => 'warn', 'bad' => 'bad', 'muted' => 'muted'][$tone] ?? '';
}
