<?php
/**
 * Report data shared by the Reports page, the PDF export and the monthly summary.
 */
defined('SIXPENCE') || exit;

function report_presets(): array
{
    return [
        'this-month' => 'This month',
        'last-month' => 'Last month',
        '3m' => 'Last 3 months',
        'ytd' => 'Year to date',
        '12m' => 'Last 12 months',
    ];
}

/**
 * Turn request parameters into a date range.
 *
 * @return array{0: string, 1: string, 2: string} [range key, start, end]
 */
function resolve_report_range(string $range, string $start, string $end, ?DateTimeImmutable $today = null): array
{
    $today ??= today();
    if ($range === 'custom' && is_valid_date($start) && is_valid_date($end)) {
        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }
        if (days_between($start, $end) > 3660) {
            $start = (new DateTimeImmutable($end))->modify('-10 years')->format('Y-m-d');
        }
        return ['custom', $start, $end];
    }

    $range = isset(report_presets()[$range]) ? $range : 'this-month';
    $first_of_month = $today->modify('first day of this month');
    [$start, $end] = match ($range) {
        'last-month' => month_bounds($today->modify('first day of last month')),
        '3m' => [$first_of_month->modify('-2 months')->format('Y-m-d'), $today->format('Y-m-d')],
        'ytd' => [$today->format('Y') . '-01-01', $today->format('Y-m-d')],
        '12m' => [$first_of_month->modify('-11 months')->format('Y-m-d'), $today->format('Y-m-d')],
        default => [$first_of_month->format('Y-m-d'), $today->format('Y-m-d')],
    };
    return [$range, $start, $end];
}

function build_report(int $user_id, string $start, string $end): array
{
    $span_days = days_between($start, $end) + 1;
    $prev_end = (new DateTimeImmutable($start))->modify('-1 day')->format('Y-m-d');
    $prev_start = (new DateTimeImmutable($start))->modify('-' . $span_days . ' days')->format('Y-m-d');

    $stmt = db()->prepare('SELECT i.income_date AS date, i.amount, i.description, s.name AS name, c.name AS secondary
        FROM income i LEFT JOIN income_sources s ON s.id = i.source_id LEFT JOIN income_categories c ON c.id = i.category_id
        WHERE i.user_id = ? AND i.income_date BETWEEN ? AND ? ORDER BY i.income_date, i.id');
    $stmt->execute([$user_id, $start, $end]);
    $incomes = $stmt->fetchAll();

    $stmt = db()->prepare('SELECT e.expense_date AS date, e.amount, e.description, c.name AS name, m.name AS secondary
        FROM expenses e LEFT JOIN expense_categories c ON c.id = e.category_id LEFT JOIN payment_methods m ON m.id = e.payment_method_id
        WHERE e.user_id = ? AND e.expense_date BETWEEN ? AND ? ORDER BY e.expense_date, e.id');
    $stmt->execute([$user_id, $start, $end]);
    $expenses = $stmt->fetchAll();

    $total_income = array_sum(array_map('floatval', array_column($incomes, 'amount')));
    $total_spent = array_sum(array_map('floatval', array_column($expenses, 'amount')));
    $net = $total_income - $total_spent;

    // Trend buckets: daily for up to a month, weekly up to four months, monthly beyond.
    $granularity = $span_days <= 31 ? 'day' : ($span_days <= 124 ? 'week' : 'month');
    $bucket_of = static function (string $date) use ($granularity): string {
        $d = new DateTimeImmutable($date);
        return match ($granularity) {
            'day' => $d->format('Y-m-d'),
            'week' => $d->modify('monday this week')->format('Y-m-d'),
            'month' => $d->format('Y-m'),
        };
    };
    $buckets = [];
    for ($cursor = new DateTimeImmutable($start), $last = new DateTimeImmutable($end); $cursor <= $last; $cursor = $cursor->modify('+1 day')) {
        $key = $bucket_of($cursor->format('Y-m-d'));
        $buckets[$key] ??= [
            'label' => match ($granularity) {
                'day' => $cursor->format('M j'),
                'week' => 'Wk of ' . (new DateTimeImmutable($key))->format('M j'),
                'month' => $cursor->format($span_days > 366 ? 'M Y' : 'M'),
            },
            'in' => 0.0,
            'out' => 0.0,
        ];
    }
    foreach ($incomes as $row) {
        $buckets[$bucket_of($row['date'])]['in'] += (float) $row['amount'];
    }
    foreach ($expenses as $row) {
        $buckets[$bucket_of($row['date'])]['out'] += (float) $row['amount'];
    }
    $running = 0.0;
    $running_series = [];
    foreach ($buckets as $bucket) {
        $running += $bucket['in'] - $bucket['out'];
        $running_series[] = round($running, 2);
    }

    $stmt = db()->prepare("SELECT COALESCE(c.name, 'Uncategorized') AS name, SUM(e.amount) FROM expenses e LEFT JOIN expense_categories c ON c.id = e.category_id WHERE e.user_id = ? AND e.expense_date BETWEEN ? AND ? GROUP BY c.name");
    $stmt->execute([$user_id, $prev_start, $prev_end]);
    $prev_by_category = array_map('floatval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));

    $largest = $expenses;
    usort($largest, static fn($a, $b) => (float) $b['amount'] <=> (float) $a['amount']);

    return [
        'start' => $start,
        'end' => $end,
        'span_days' => $span_days,
        'range_label' => fmt_date($start, 'M j, Y') . ' – ' . fmt_date($end, 'M j, Y'),
        'incomes' => $incomes,
        'expenses' => $expenses,
        'has_data' => $incomes || $expenses,
        'total_income' => $total_income,
        'total_spent' => $total_spent,
        'net' => $net,
        'savings_rate' => $total_income > 0 ? (int) round($net / $total_income * 100) : null,
        'prev_start' => $prev_start,
        'prev_end' => $prev_end,
        'prev_income' => total_between('income', $user_id, $prev_start, $prev_end),
        'prev_spent' => total_between('expenses', $user_id, $prev_start, $prev_end),
        'granularity' => $granularity,
        'buckets' => array_values($buckets),
        'running_series' => $running_series,
        'by_category' => report_breakdown($expenses),
        'by_source' => report_breakdown($incomes),
        'prev_by_category' => $prev_by_category,
        'largest' => array_slice($largest, 0, 6),
    ];
}

/** Totals and counts per name, largest first. */
function report_breakdown(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        $name = $row['name'] ?: 'Uncategorized';
        $out[$name]['total'] = ($out[$name]['total'] ?? 0) + (float) $row['amount'];
        $out[$name]['count'] = ($out[$name]['count'] ?? 0) + 1;
    }
    uasort($out, static fn($a, $b) => $b['total'] <=> $a['total']);
    return $out;
}

/** Percentage change, or null when there's nothing to compare against. */
function percent_change(float $now, float $before): ?int
{
    return $before > 0 ? (int) round(($now - $before) / $before * 100) : null;
}

/** Rows for the CSV export, oldest first. */
function report_csv_rows(array $report): array
{
    $rows = array_merge(
        array_map(static fn($r) => ['Income', $r['date'], number_format((float) $r['amount'], 2, '.', ''), $r['name'], $r['secondary'], $r['description']], $report['incomes']),
        array_map(static fn($r) => ['Expense', $r['date'], number_format(-(float) $r['amount'], 2, '.', ''), $r['name'], $r['secondary'], $r['description']], $report['expenses'])
    );
    usort($rows, static fn($a, $b) => strcmp($a[1], $b[1]));
    return $rows;
}
