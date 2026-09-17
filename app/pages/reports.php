<?php
defined('FINPULSE') || exit;

$user = require_login();
$today = today();

[$range, $start, $end] = resolve_report_range(query('range', 'this-month'), query('start'), query('end'), $today);
$report = build_report($user['id'], $start, $end);
$file_base = 'finpulse_' . $start . '_to_' . $end;

/* ---- Exports (before any output) ------------------------------------------ */
if (query('export') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $file_base . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Type', 'Date', 'Amount (' . currency_code() . ')', 'Category / Source', 'Payment method / Category', 'Note']);
    foreach (report_csv_rows($report) as $row) {
        fputcsv($out, array_map('csv_safe', $row));
    }
    fclose($out);
    return;
}

if (query('export') === 'pdf') {
    $pdf = build_report_pdf($report, $user);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $file_base . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: no-store');
    echo $pdf;
    return;
}

function change_note(float $now, float $before, bool $higher_is_good, int $span_days): string
{
    $pct = percent_change($now, $before);
    if ($pct === null) {
        return '<span class="faint">No data for the previous period</span>';
    }
    if ($pct === 0) {
        return '<span class="faint">Same as the previous period</span>';
    }
    $good = ($pct > 0) === $higher_is_good;
    return '<span class="' . ($good ? 'pos' : 'neg') . '">' . icon($pct > 0 ? 'trend-up' : 'trend-down', 'icon icon-sm') . ' ' . abs($pct) . '%</span> <span class="faint">vs previous ' . plural($span_days, 'day') . '</span>';
}

$symbol = currency_symbol();
$labels = array_column($report['buckets'], 'label');

/* ---- View --------------------------------------------------------------- */
app_open('Reports');
page_header(
    'Reports',
    e($report['range_label']) . ' · ' . plural($report['span_days'], 'day'),
    $report['has_data']
        ? '<a class="btn" href="' . e(url_with(['export' => 'csv'])) . '">' . icon('download') . 'CSV</a>'
          . '<a class="btn btn-primary" href="' . e(url_with(['export' => 'pdf'])) . '">' . icon('file') . 'Download PDF</a>'
        : ''
);
?>
<div class="report-filters no-print">
  <nav class="seg" aria-label="Date range">
    <?php foreach (report_presets() as $key => $label): ?>
    <a href="<?= e(url('reports', ['range' => $key])) ?>"<?= $range === $key ? ' aria-current="true"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <form method="get" action="<?= e(url('reports')) ?>">
    <input type="hidden" name="range" value="custom">
    <div class="field">
      <label class="label small" for="start">From</label>
      <input class="input" id="start" name="start" type="date" value="<?= e($start) ?>" max="<?= e($today->format('Y-m-d')) ?>">
    </div>
    <div class="field">
      <label class="label small" for="end">To</label>
      <input class="input" id="end" name="end" type="date" value="<?= e($end) ?>">
    </div>
    <button class="btn btn-sm<?= $range === 'custom' ? ' btn-primary' : '' ?>" type="submit">Apply</button>
  </form>
</div>

<?php if (!$report['has_data']): ?>
  <section class="panel">
    <?= empty_state('reports', 'Nothing to report for these dates', 'There are no income or expense entries between ' . $report['range_label'] . '. Try a longer range.', '<a class="btn btn-sm" href="' . e(url('reports', ['range' => '12m'])) . '">Show the last 12 months</a>') ?>
  </section>
<?php else: ?>

  <section class="panel stats" aria-label="Summary">
    <div class="stat">
      <p class="eyebrow"><span class="swatch swatch-in"></span>Income</p>
      <p class="value figure-md num"><?= money_figure($report['total_income']) ?></p>
      <p class="note"><?= change_note($report['total_income'], $report['prev_income'], true, $report['span_days']) ?></p>
    </div>
    <div class="stat">
      <p class="eyebrow"><span class="swatch swatch-out"></span>Spending</p>
      <p class="value figure-md num"><?= money_figure($report['total_spent']) ?></p>
      <p class="note"><?= change_note($report['total_spent'], $report['prev_spent'], false, $report['span_days']) ?></p>
    </div>
    <div class="stat">
      <p class="eyebrow">Net</p>
      <p class="value figure-md num <?= $report['net'] < 0 ? 'neg' : '' ?>"><?= money_figure($report['net']) ?></p>
      <p class="note faint"><?= $report['net'] >= 0 ? 'Kept after spending' : 'Spent more than came in' ?></p>
    </div>
    <div class="stat">
      <p class="eyebrow">Savings rate</p>
      <p class="value figure-md num"><?= $report['savings_rate'] === null ? '—' : $report['savings_rate'] . '%' ?></p>
      <p class="note faint"><?= $report['savings_rate'] === null ? 'No income in this range' : 'Share of income not spent' ?></p>
    </div>
  </section>

  <div class="report-grid">
    <section class="panel wide" aria-labelledby="flow-title">
      <div class="panel-head flush">
        <div>
          <h2 class="panel-title" id="flow-title">Income and spending</h2>
          <p class="panel-sub">Per <?= e($report['granularity']) ?></p>
        </div>
        <div class="legend">
          <span><span class="swatch swatch-in"></span>Income</span>
          <span><span class="swatch swatch-out"></span>Spending</span>
        </div>
      </div>
      <div class="panel-pad">
        <div class="chart chart-lg" data-chart="chart-flow">
          <canvas role="img" aria-label="Income and spending per <?= e($report['granularity']) ?> from <?= e($report['range_label']) ?>. Total income <?= e(money($report['total_income'])) ?>, total spending <?= e(money($report['total_spent'])) ?>."></canvas>
        </div>
        <?= json_script('chart-flow', [
            'type' => 'bar',
            'currency' => $symbol,
            'labels' => $labels,
            'series' => [
                ['label' => 'Income', 'data' => array_map(static fn($b) => round($b['in'], 2), $report['buckets']), 'tone' => 'in'],
                ['label' => 'Spending', 'data' => array_map(static fn($b) => round($b['out'], 2), $report['buckets']), 'tone' => 'out'],
            ],
        ]) ?>
        <details class="data-table no-print">
          <summary>Show as table</summary>
          <div class="table-wrap">
            <table class="table num">
              <thead><tr><th><?= e(ucfirst($report['granularity'])) ?></th><th class="r">Income</th><th class="r">Spending</th><th class="r">Net</th></tr></thead>
              <tbody>
              <?php foreach ($report['buckets'] as $b): ?>
                <tr><td><?= e($b['label']) ?></td><td class="r"><?= money($b['in']) ?></td><td class="r"><?= money($b['out']) ?></td><td class="r"><?= money($b['in'] - $b['out']) ?></td></tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </details>
      </div>
    </section>

    <section class="panel wide" aria-labelledby="running-title">
      <div class="panel-head flush">
        <div>
          <h2 class="panel-title" id="running-title">Running balance</h2>
          <p class="panel-sub">Income minus spending, accumulated across the range</p>
        </div>
        <p class="num small muted">Ends at <strong class="<?= $report['net'] < 0 ? 'neg' : 'pos' ?>"><?= money($report['net'], 'always') ?></strong></p>
      </div>
      <div class="panel-pad">
        <div class="chart" data-chart="chart-running">
          <canvas role="img" aria-label="Running balance ending at <?= e(money($report['net'])) ?>."></canvas>
        </div>
        <?= json_script('chart-running', [
            'type' => 'line',
            'currency' => $symbol,
            'fillTo' => 'zero',
            'stepped' => $report['granularity'] === 'day',
            'labels' => $labels,
            'series' => [['label' => 'Running balance', 'data' => $report['running_series'], 'tone' => 'in', 'fill' => true]],
        ]) ?>
      </div>
    </section>

    <section class="panel" aria-labelledby="cat-title">
      <div class="panel-head">
        <div>
          <h2 class="panel-title" id="cat-title">Spending by category</h2>
          <p class="panel-sub"><?= plural(count($report['by_category']), 'category', 'categories') ?> · change vs previous period</p>
        </div>
      </div>
      <?php if ($report['by_category']): $top = reset($report['by_category'])['total']; ?>
      <div class="table-wrap">
        <table class="table breakdown">
          <thead><tr><th>Category</th><th class="r">Spent</th><th class="r">Share</th><th class="r">Change</th></tr></thead>
          <tbody>
          <?php foreach ($report['by_category'] as $name => $row):
              $delta = percent_change($row['total'], $report['prev_by_category'][$name] ?? 0); ?>
            <tr>
              <td>
                <span class="truncate"><?= e($name) ?></span>
                <div class="bar-track"><div class="bar-fill" style="--value: <?= percent($row['total'], $top) ?>%"></div></div>
              </td>
              <td class="r num"><?= money($row['total']) ?><br><span class="xsmall faint"><?= plural($row['count'], 'entry', 'entries') ?></span></td>
              <td class="r num"><?= percent($row['total'], $report['total_spent']) ?>%</td>
              <td class="r num nowrap"><?= $delta === null ? '<span class="faint">New</span>' : ($delta === 0 ? '<span class="faint">0%</span>' : '<span class="' . ($delta > 0 ? 'neg' : 'pos') . '">' . ($delta > 0 ? '+' : '−') . abs($delta) . '%</span>') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
        <?= empty_state('expense', 'No spending', 'No expenses in this range.') ?>
      <?php endif; ?>
    </section>

    <div class="overview-col">
      <section class="panel" aria-labelledby="src-title">
        <div class="panel-head">
          <div>
            <h2 class="panel-title" id="src-title">Income by source</h2>
            <p class="panel-sub"><?= plural(count($report['by_source']), 'source') ?></p>
          </div>
        </div>
        <div class="panel-pad">
          <?php if ($report['by_source']): $top = reset($report['by_source'])['total']; ?>
          <div class="bars">
            <?php foreach ($report['by_source'] as $name => $row): ?>
            <div class="bar-row">
              <div class="spread"><span class="truncate"><?= e($name) ?></span><span class="num"><?= money($row['total']) ?> <span class="faint small"><?= percent($row['total'], $report['total_income']) ?>%</span></span></div>
              <div class="bar-track"><div class="bar-fill in" style="--value: <?= percent($row['total'], $top) ?>%"></div></div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php else: ?>
            <p class="muted small">No income in this range.</p>
          <?php endif; ?>
        </div>
      </section>

      <section class="panel" aria-labelledby="largest-title">
        <div class="panel-head">
          <h2 class="panel-title" id="largest-title">Largest expenses</h2>
        </div>
        <?php foreach ($report['largest'] as $row): ?>
        <div class="ledger-row compact">
          <span class="ledger-icon out" aria-hidden="true"><?= icon('expense', 'icon icon-sm') ?></span>
          <div class="ledger-main">
            <p class="ledger-title truncate"><?= e($row['description'] ?: ($row['name'] ?: 'Expense')) ?></p>
            <p class="ledger-meta truncate"><?= e(fmt_date($row['date'], 'M j')) ?><?= $row['name'] ? ' · ' . e($row['name']) : '' ?></p>
          </div>
          <span class="ledger-amount"><?= money($row['amount']) ?></span>
        </div>
        <?php endforeach; ?>
        <?php if (!$report['largest']): ?><p class="panel-pad muted small">No expenses in this range.</p><?php endif; ?>
      </section>
    </div>
  </div>
<?php endif; ?>

<?php app_close($report['has_data'] ? chart_scripts() : []); ?>
