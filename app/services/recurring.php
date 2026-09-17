<?php
/**
 * Recurring income and expenses.
 *
 * A recurring entry is an ordinary ledger row with is_recurring = 1, a recurrence_period and
 * a next_recurrence_date. When that date arrives, a copy is added (recurring_source_id points
 * back at the original) and the next date moves forward. Dates are always computed from the
 * original entry's date, so a bill on the 31st lands on Feb 28 and then on Mar 31 again.
 */
defined('SIXPENCE') || exit;

const RECURRING_MAX_CATCH_UP = 400;

function recurrence_periods(): array
{
    return [
        'weekly' => 'Every week',
        'biweekly' => 'Every 2 weeks',
        'monthly' => 'Every month',
        'quarterly' => 'Every 3 months',
        'yearly' => 'Every year',
    ];
}

function recurrence_label(?string $period): string
{
    return recurrence_periods()[$period] ?? 'Repeats';
}

/** The $n-th occurrence (n = 0 is the start date itself). */
function recurrence_date(string $start, string $period, int $n): string
{
    $date = new DateTimeImmutable($start);
    $months = ['monthly' => 1, 'quarterly' => 3, 'yearly' => 12][$period] ?? null;

    if ($months === null) {
        $days = $period === 'biweekly' ? 14 : 7;
        return $date->modify('+' . ($days * $n) . ' days')->format('Y-m-d');
    }

    $day = (int) $date->format('j');
    $total = (int) $date->format('Y') * 12 + ((int) $date->format('n') - 1) + $months * $n;
    $year = intdiv($total, 12);
    $month = $total % 12 + 1;
    $last_day = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');
    return sprintf('%04d-%02d-%02d', $year, $month, min($day, $last_day));
}

/** First occurrence strictly after $after. */
function next_occurrence_after(string $start, string $period, string $after): string
{
    for ($n = 1; $n < 100000; $n++) {
        $date = recurrence_date($start, $period, $n);
        if ($date > $after) {
            return $date;
        }
    }
    throw new RuntimeException('Recurrence never passes ' . $after);
}

/** Which ledger tables take part, with their date columns. */
function recurring_tables(): array
{
    return [
        'income' => ['date' => 'income_date', 'cols' => ['source_id', 'category_id']],
        'expenses' => ['date' => 'expense_date', 'cols' => ['category_id', 'payment_method_id']],
    ];
}

/**
 * Add every recurring entry that has come due, for one user or (with null) everyone.
 * Safe to run repeatedly; each occurrence is added exactly once.
 *
 * @return array<int, array{count: int, names: string[]}> per user id
 */
function process_recurring(?int $user_id = null, ?string $today = null): array
{
    $today ??= today()->format('Y-m-d');
    $created = [];
    $pdo = db();

    foreach (recurring_tables() as $table => $meta) {
        $d = $meta['date'];
        [$c1, $c2] = $meta['cols'];
        $sql = "SELECT * FROM $table WHERE is_recurring = 1 AND next_recurrence_date IS NOT NULL AND next_recurrence_date <= ?" . ($user_id ? ' AND user_id = ?' : '');
        $due = $pdo->prepare($sql);
        $due->execute($user_id ? [$today, $user_id] : [$today]);

        foreach ($due->fetchAll() as $row) {
            $period = $row['recurrence_period'];
            if (!isset(recurrence_periods()[$period])) {
                continue;
            }
            $pdo->beginTransaction();
            try {
                // Lock the row, then re-read it: if another request already
                // advanced the schedule, skip it.
                $fresh = $pdo->prepare("SELECT next_recurrence_date, is_recurring FROM $table WHERE id = ? FOR UPDATE");
                $fresh->execute([$row['id']]);
                $current = $fresh->fetch();
                if (!$current || (int) $current['is_recurring'] !== 1 || $current['next_recurrence_date'] !== $row['next_recurrence_date']) {
                    $pdo->rollBack();
                    continue;
                }

                $n = 1;
                while (recurrence_date($row[$d], $period, $n) < $row['next_recurrence_date']) {
                    $n++;
                }
                $insert = $pdo->prepare("INSERT INTO $table (user_id, amount, $d, $c1, $c2, description, recurring_source_id, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
                $date = recurrence_date($row[$d], $period, $n);
                $added = 0;
                while ($date <= $today && $added < RECURRING_MAX_CATCH_UP) {
                    $insert->execute([$row['user_id'], $row['amount'], $date, $row[$c1], $row[$c2], $row['description'], $row['id']]);
                    $added++;
                    $date = recurrence_date($row[$d], $period, ++$n);
                }
                $pdo->prepare("UPDATE $table SET next_recurrence_date = ? WHERE id = ? AND next_recurrence_date = ?")
                    ->execute([$date, $row['id'], $row['next_recurrence_date']]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            $uid = (int) $row['user_id'];
            $created[$uid] ??= ['count' => 0, 'names' => []];
            $created[$uid]['count'] += $added;
            $created[$uid]['names'][] = $row['description'] ?: ($table === 'income' ? 'Income' : 'Expense');
        }
    }
    return $created;
}

/**
 * Work out a schedule's next date after the entry is saved: the first occurrence after
 * both the entry's own date and any copy that was already added.
 */
function schedule_next_date(string $table, int $id, string $date, string $period): string
{
    $d = recurring_tables()[$table]['date'];
    $latest = db()->prepare("SELECT MAX($d) FROM $table WHERE recurring_source_id = ?");
    $latest->execute([$id]);
    $after = max($date, (string) ($latest->fetchColumn() ?: $date));
    return next_occurrence_after($date, $period, $after);
}

/** Active schedules for a user in one ledger, soonest first. */
function recurring_schedules(int $user_id, string $table): array
{
    $meta = recurring_tables()[$table];
    $name_join = $table === 'income'
        ? 'LEFT JOIN income_sources n ON n.id = t.source_id'
        : 'LEFT JOIN expense_categories n ON n.id = t.category_id';
    $stmt = db()->prepare("SELECT t.id, t.amount, t.{$meta['date']} AS date, t.description, t.recurrence_period, t.next_recurrence_date, n.name AS label
        FROM $table t $name_join WHERE t.user_id = ? AND t.is_recurring = 1 ORDER BY t.next_recurrence_date, t.id");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}

/**
 * Per-request upkeep for the signed-in user: add due recurring entries on every request
 * (a single indexed query when nothing is due) and run notification checks every few minutes.
 */
function run_user_maintenance(array $user): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $created = process_recurring($user['id'])[$user['id']] ?? null;
    if ($created && $created['count'] > 0 && PHP_SAPI !== 'cli') {
        $names = array_unique($created['names']);
        flash('info', 'Added ' . plural($created['count'], 'recurring entry', 'recurring entries') . ': ' . implode(', ', array_slice($names, 0, 3)) . (count($names) > 3 ? ' and more' : '') . '.');
    }

    $last = (int) ($_SESSION['_jobs_at'] ?? 0);
    if ($created || time() - $last > 600) {
        $_SESSION['_jobs_at'] = time();
        run_notification_checks($user);
    }
}
