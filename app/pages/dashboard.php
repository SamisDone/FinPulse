<?php
defined('SIXPENCE') || exit;

$user = require_login();
$uid = $user['id'];
$today = today();
$month_name = $today->format('F');
$last_month = $today->modify('first day of last month');
[$ms, $me] = month_bounds($today);
[$ls, $le] = month_bounds($last_month);

// Headline numbers
$income = total_between('income', $uid, $ms, $me);
$spent = total_between('expenses', $uid, $ms, $me);
$net = $income - $spent;

// Spending at the same point last month, for a fair comparison mid-month
$same_day_last_month = $last_month->setDate((int) $last_month->format('Y'), (int) $last_month->format('m'), min((int) $today->format('j'), (int) $last_month->format('t')));
$spent_last_same_point = total_between('expenses', $uid, $ls, $same_day_last_month->format('Y-m-d'));

$stmt = db()->prepare('SELECT COALESCE(SUM(current_balance), 0), COUNT(*) FROM savings_accounts WHERE user_id = ?');
$stmt->execute([$uid]);
[$saved, $account_count] = $stmt->fetch(PDO::FETCH_NUM);
$saved = (float) $saved;

// Spending pace: cumulative spend per day, this month vs last month
$daily = db()->prepare('SELECT expense_date, SUM(amount) AS total FROM expenses WHERE user_id = ? AND expense_date BETWEEN ? AND ? GROUP BY expense_date');
$daily->execute([$uid, $ls, $me]);
$by_day = array_map('floatval', $daily->fetchAll(PDO::FETCH_KEY_PAIR));

$days_this = (int) $today->format('t');
$days_last = (int) $last_month->format('t');
$axis_days = max($days_this, $days_last);
$labels = $this_series = $last_series = [];
$run_this = $run_last = 0.0;
for ($d = 1; $d <= $axis_days; $d++) {
    $labels[] = (string) $d;
    if ($d <= $days_last) {
        $run_last += $by_day[$last_month->format('Y-m-') . sprintf('%02d', $d)] ?? 0;
        $last_series[] = round($run_last, 2);
    } else {
        $last_series[] = null;
    }
    if ($d <= (int) $today->format('j')) {
        $run_this += $by_day[$today->format('Y-m-') . sprintf('%02d', $d)] ?? 0;
        $this_series[] = round($run_this, 2);
    } else {
        $this_series[] = null;
    }
}

// Top categories this month
$cats = db()->prepare('SELECT COALESCE(c.name, \'Uncategorized\') AS name, SUM(e.amount) AS total, COUNT(*) AS n
    FROM expenses e LEFT JOIN expense_categories c ON c.id = e.category_id
    WHERE e.user_id = ? AND e.expense_date BETWEEN ? AND ? GROUP BY c.name ORDER BY total DESC');
$cats->execute([$uid, $ms, $me]);
$categories = $cats->fetchAll();
$top_categories = array_slice($categories, 0, 5);
$other_total = array_sum(array_column(array_slice($categories, 5), 'total'));

// Active budgets and goals
$b = db()->prepare('SELECT * FROM budgets WHERE user_id = ? AND start_date <= ? AND end_date >= ? ORDER BY end_date, start_date DESC LIMIT 3');
$b->execute([$uid, $today->format('Y-m-d'), $today->format('Y-m-d')]);
$budgets = $b->fetchAll();

$g = db()->prepare("SELECT * FROM financial_goals WHERE user_id = ? AND status = 'active' AND current_amount < target_amount ORDER BY CASE WHEN target_date IS NULL THEN 1 ELSE 0 END, target_date LIMIT 3");
$g->execute([$uid]);
$goals = $g->fetchAll();

// Scheduled income and expenses in the next two weeks
$horizon = $today->modify('+14 days')->format('Y-m-d');
$upcoming = [];
foreach (['income' => 'in', 'expenses' => 'out'] as $table => $kind) {
    foreach (recurring_schedules($uid, $table) as $schedule) {
        if ($schedule['next_recurrence_date'] <= $horizon) {
            $upcoming[] = $schedule + ['kind' => $kind];
        }
    }
}
usort($upcoming, static fn($a, $b) => $a['next_recurrence_date'] <=> $b['next_recurrence_date']);

// Recent activity across both ledgers
$recent_income = db()->prepare('SELECT i.id, i.amount, i.income_date AS date, i.description, s.name AS label, i.created_at FROM income i LEFT JOIN income_sources s ON s.id = i.source_id WHERE i.user_id = ? ORDER BY i.income_date DESC, i.id DESC LIMIT 6');
$recent_income->execute([$uid]);
$recent_expenses = db()->prepare('SELECT e.id, e.amount, e.expense_date AS date, e.description, c.name AS label, e.created_at FROM expenses e LEFT JOIN expense_categories c ON c.id = e.category_id WHERE e.user_id = ? ORDER BY e.expense_date DESC, e.id DESC LIMIT 6');
$recent_expenses->execute([$uid]);
$recent = array_merge(
    array_map(static fn($r) => $r + ['kind' => 'in'], $recent_income->fetchAll()),
    array_map(static fn($r) => $r + ['kind' => 'out'], $recent_expenses->fetchAll())
);
usort($recent, static fn($a, $b) => [$b['date'], $b['created_at']] <=> [$a['date'], $a['created_at']]);
$recent = array_slice($recent, 0, 6);

// First-run checklist
$counts = db()->prepare('SELECT
    (SELECT COUNT(*) FROM income WHERE user_id = :a) AS income,
    (SELECT COUNT(*) FROM expenses WHERE user_id = :b) AS expenses,
    (SELECT COUNT(*) FROM budgets WHERE user_id = :c) AS budgets,
    (SELECT COUNT(*) FROM financial_goals WHERE user_id = :d) AS goals');
$counts->execute([':a' => $uid, ':b' => $uid, ':c' => $uid, ':d' => $uid]);
$setup = array_map('intval', $counts->fetch());
$is_new = $setup['income'] === 0 && $setup['expenses'] === 0;

$hour = (int) date('G');
$greeting = $hour < 5 ? 'Up late' : ($hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening'));

app_open('Overview');
page_header(
    $greeting . ', ' . $user['username'],
    e($today->format('l, F j')) . ($is_new ? '' : ' · day ' . $today->format('j') . ' of ' . $days_this),
    '<a class="btn" href="' . e(url('income', [], 'add')) . '">' . icon('income') . 'Add income</a><a class="btn btn-primary" href="' . e(url('expenses', [], 'add')) . '">' . icon('plus') . 'Add expense</a>'
);
?>

<?php if ($is_new): ?>
<section class="panel panel-pad" aria-labelledby="setup-title">
  <h2 class="panel-title" id="setup-title">Set up your ledger</h2>
  <p class="panel-sub">Four steps and your overview fills itself in. Start with what came in this month.</p>
  <div class="setup">
    <?php foreach ([
        [url('income', [], 'add'), 'Record your income', 'Paychecks, freelance work, anything that came in.', $setup['income'] > 0],
        [url('expenses', [], 'add'), 'Log a few expenses', 'Rent and bills first, then day-to-day spending.', $setup['expenses'] > 0],
        [url('budgets', ['new' => 1]), 'Create a budget', 'A monthly limit with optional per-category caps.', $setup['budgets'] > 0],
        [url('savings', ['new' => 'goal']), 'Add a savings goal', 'Pick a target and a date to get a monthly number.', $setup['goals'] > 0],
    ] as $i => [$href, $title, $text, $done]): ?>
    <a class="setup-step<?= $done ? ' done' : '' ?>" href="<?= e($href) ?>">
      <span class="n"><?= $done ? icon('check', 'icon icon-lg') : $i + 1 ?></span>
      <strong><?= e($title) ?></strong>
      <span><?= e($text) ?></span>
    </a>
    <?php endforeach; ?>
  </div>
</section>
<?php else: ?>

<div class="overview-top">
  <section class="panel overview-hero" aria-labelledby="net-title">
    <div>
      <p class="eyebrow" id="net-title"><?= $net >= 0 ? 'Left over in ' . e($month_name) : 'Overspent in ' . e($month_name) ?></p>
      <p class="figure <?= $net < 0 ? 'neg' : '' ?>"><?= money_figure($net) ?></p>
      <p class="muted hero-caption">
        <?php if ($income > 0): ?>
          You've spent <strong class="num"><?= percent($spent, $income) ?>%</strong> of what came in this month.
        <?php else: ?>
          No income recorded for <?= e($month_name) ?> yet. <a class="link" href="<?= e(url('income', [], 'add')) ?>">Add it</a> to see what's left.
        <?php endif; ?>
      </p>
    </div>
    <dl class="hero-split">
      <div>
        <dt><span class="swatch swatch-in"></span>Money in</dt>
        <dd><?= money($income) ?></dd>
      </div>
      <div>
        <dt><span class="swatch swatch-out"></span>Money out</dt>
        <dd><?= money($spent) ?>
          <?php if ($spent_last_same_point > 0):
              $change = (int) round(($spent - $spent_last_same_point) / $spent_last_same_point * 100); ?>
          <small><?= $change === 0 ? 'Same as' : abs($change) . '% ' . ($change > 0 ? 'more than' : 'less than') ?> this point in <?= e($last_month->format('F')) ?></small>
          <?php endif; ?>
        </dd>
      </div>
      <div>
        <dt><?= icon('savings', 'icon icon-sm') ?>In savings</dt>
        <dd><?= money($saved) ?><small><?= plural((int) $account_count, 'account') ?></small></dd>
      </div>
    </dl>
  </section>

  <section class="panel" aria-labelledby="where-title">
    <div class="panel-head">
      <div>
        <h2 class="panel-title" id="where-title">Where it went</h2>
        <p class="panel-sub"><?= e($month_name) ?> by category</p>
      </div>
      <a class="btn btn-ghost btn-sm" href="<?= e(url('reports')) ?>">Reports <?= icon('arrow-right', 'icon icon-sm') ?></a>
    </div>
    <div class="panel-pad">
      <?php if ($top_categories): ?>
      <div class="bars">
        <?php foreach ($top_categories as $cat): ?>
        <div class="bar-row">
          <div class="spread"><span class="truncate"><?= e($cat['name']) ?></span><span class="num"><?= money($cat['total']) ?> <span class="faint small"><?= percent((float) $cat['total'], $spent) ?>%</span></span></div>
          <div class="bar-track"><div class="bar-fill" style="--value: <?= percent((float) $cat['total'], (float) $top_categories[0]['total']) ?>%"></div></div>
        </div>
        <?php endforeach; ?>
        <?php if ($other_total > 0): ?>
        <p class="small faint">Plus <?= money($other_total) ?> across <?= plural(count($categories) - 5, 'other category', 'other categories') ?>.</p>
        <?php endif; ?>
      </div>
      <?php else: ?>
      <?= empty_state('expense', 'Nothing spent yet', 'Expenses you log this month will be broken down here.') ?>
      <?php endif; ?>
    </div>
  </section>
</div>

<div class="overview-grid">
  <div class="overview-col">
    <section class="panel" aria-labelledby="pace-title">
      <div class="panel-head flush">
        <div>
          <h2 class="panel-title" id="pace-title">Spending pace</h2>
          <p class="panel-sub">Running total by day of the month</p>
        </div>
        <div class="legend">
          <span><span class="line-key swatch-out"></span><?= e($month_name) ?></span>
          <span><span class="line-key swatch-muted"></span><?= e($last_month->format('F')) ?></span>
        </div>
      </div>
      <div class="panel-pad">
        <div class="chart" data-chart="chart-pace">
          <canvas role="img" aria-label="Cumulative spending in <?= e($month_name) ?> compared with <?= e($last_month->format('F')) ?>. <?= e($month_name) ?> so far: <?= e(money($spent)) ?>. <?= e($last_month->format('F')) ?> total: <?= e(money($run_last)) ?>."></canvas>
        </div>
        <?= json_script('chart-pace', [
            'type' => 'line',
            'currency' => currency_symbol(),
            'labels' => $labels,
            'series' => [
                ['label' => $last_month->format('F'), 'data' => $last_series, 'tone' => 'muted'],
                ['label' => $month_name, 'data' => $this_series, 'tone' => 'out', 'fill' => true],
            ],
        ]) ?>
        <details class="data-table">
          <summary>Show as table</summary>
          <div class="table-wrap">
            <table class="table num">
              <thead><tr><th>Day</th><th class="r"><?= e($month_name) ?></th><th class="r"><?= e($last_month->format('F')) ?></th></tr></thead>
              <tbody>
                <?php foreach ($labels as $i => $day): ?>
                <tr><td><?= e($day) ?></td><td class="r"><?= $this_series[$i] === null ? '—' : money($this_series[$i]) ?></td><td class="r"><?= $last_series[$i] === null ? '—' : money($last_series[$i]) ?></td></tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </details>
      </div>
    </section>

    <section class="panel" aria-labelledby="recent-title">
      <div class="panel-head">
        <h2 class="panel-title" id="recent-title">Recent activity</h2>
        <div class="cluster">
          <a class="btn btn-ghost btn-sm" href="<?= e(url('income')) ?>">Income</a>
          <a class="btn btn-ghost btn-sm" href="<?= e(url('expenses')) ?>">Expenses</a>
        </div>
      </div>
      <?php foreach ($recent as $row):
          $title = $row['description'] ?: ($row['label'] ?: ($row['kind'] === 'in' ? 'Income' : 'Expense')); ?>
      <div class="ledger-row compact">
        <span class="ledger-icon <?= $row['kind'] ?>" aria-hidden="true"><?= icon($row['kind'] === 'in' ? 'income' : 'expense', 'icon icon-sm') ?></span>
        <div class="ledger-main">
          <p class="ledger-title truncate"><?= e($title) ?></p>
          <p class="ledger-meta truncate"><?= e(day_label($row['date'])) ?><?= $row['label'] && $row['description'] ? ' · ' . e($row['label']) : '' ?></p>
        </div>
        <span class="ledger-amount <?= $row['kind'] === 'in' ? 'pos' : '' ?>"><?= money((float) $row['amount'] * ($row['kind'] === 'in' ? 1 : -1), 'always') ?></span>
      </div>
      <?php endforeach; ?>
    </section>
  </div>

  <div class="overview-col">
    <section class="panel" aria-labelledby="budgets-title">
      <div class="panel-head">
        <h2 class="panel-title" id="budgets-title">Budgets</h2>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('budgets')) ?>">All budgets <?= icon('arrow-right', 'icon icon-sm') ?></a>
      </div>
      <?php if ($budgets): ?>
      <div class="mini-list">
        <?php foreach ($budgets as $budget): $status = budget_status($budget, $uid); ?>
        <div class="mini-row">
          <span class="truncate"><strong class="small"><?= e($budget['name']) ?></strong></span>
          <span class="tag <?= tone_class($status['tone']) ?>"><?= e($status['label']) ?></span>
          <span class="small faint num"><?= money($status['spent']) ?> of <?= money($status['limit']) ?></span>
          <span class="small faint"><?= e($status['detail']) ?></span>
          <div class="progress progress-sm" role="img" aria-label="<?= $status['pct'] ?>% of budget used, <?= $status['elapsed_pct'] ?>% of the period elapsed">
            <div class="progress-bar <?= bar_class($status['tone']) ?>" style="--value: <?= min(100, $status['pct']) ?>%"></div>
            <?php if ($status['state'] === 'active'): ?><span class="progress-marker" style="--at: <?= $status['elapsed_pct'] ?>%" title="Where you'd be at an even pace"></span><?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
        <?= empty_state('budget', 'No active budgets', 'Set a monthly limit to see your pace here.', '<a class="btn btn-sm" href="' . e(url('budgets', ['new' => 1])) . '">' . icon('plus', 'icon icon-sm') . 'Create a budget</a>') ?>
      <?php endif; ?>
    </section>

    <?php if ($upcoming): ?>
    <section class="panel" aria-labelledby="upcoming-title">
      <div class="panel-head">
        <div>
          <h2 class="panel-title" id="upcoming-title">Coming up</h2>
          <p class="panel-sub">Scheduled for the next two weeks</p>
        </div>
      </div>
      <?php foreach (array_slice($upcoming, 0, 5) as $item):
          $title = $item['description'] ?: ($item['label'] ?: ($item['kind'] === 'in' ? 'Income' : 'Expense')); ?>
      <div class="ledger-row compact">
        <span class="ledger-icon <?= $item['kind'] ?>" aria-hidden="true"><?= icon('repeat', 'icon icon-sm') ?></span>
        <div class="ledger-main">
          <p class="ledger-title truncate"><?= e($title) ?></p>
          <p class="ledger-meta truncate"><?= e(day_label($item['next_recurrence_date'])) ?> · <?= e(recurrence_label($item['recurrence_period'])) ?></p>
        </div>
        <span class="ledger-amount <?= $item['kind'] === 'in' ? 'pos' : '' ?>"><?= money((float) $item['amount'] * ($item['kind'] === 'in' ? 1 : -1), 'always') ?></span>
      </div>
      <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <section class="panel" aria-labelledby="goals-title">
      <div class="panel-head">
        <h2 class="panel-title" id="goals-title">Savings goals</h2>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('savings')) ?>">All goals <?= icon('arrow-right', 'icon icon-sm') ?></a>
      </div>
      <?php if ($goals): ?>
      <div class="mini-list">
        <?php foreach ($goals as $goal): $status = goal_status($goal); ?>
        <div class="mini-row">
          <span class="truncate"><strong class="small"><?= e($goal['goal_name']) ?></strong></span>
          <span class="small num"><?= $status['pct'] ?>%</span>
          <span class="small faint truncate full"><?= e($status['note']) ?></span>
          <div class="progress progress-sm"><div class="progress-bar" style="--value: <?= $status['pct'] ?>%"></div></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
        <?= empty_state('target', 'No goals yet', 'Pick something to save for and get a monthly number to hit it.', '<a class="btn btn-sm" href="' . e(url('savings', ['new' => 'goal'])) . '">' . icon('plus', 'icon icon-sm') . 'Add a goal</a>') ?>
      <?php endif; ?>
    </section>
  </div>
</div>
<?php endif; ?>

<?php app_close(chart_scripts()); ?>
