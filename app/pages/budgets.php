<?php
defined('SIXPENCE') || exit;

$user = require_login();
$uid = $user['id'];
$periods = ['monthly' => 'Monthly', 'weekly' => 'Weekly', 'yearly' => 'Yearly', 'custom' => 'Custom dates'];
$categories = lookup_names($uid, 'expense_categories');

/* ---- Actions ---------------------------------------------------------- */
if (is_post()) {
    verify_csrf();
    $id = (int) input('id');

    if ($id) {
        $owned = db()->prepare('SELECT 1 FROM budgets WHERE id = ? AND user_id = ?');
        $owned->execute([$id, $uid]);
        if (!$owned->fetchColumn()) {
            flash('error', 'That budget no longer exists.');
            redirect('budgets');
        }
    }

    if (input('action') === 'delete') {
        db()->prepare('DELETE FROM budgets WHERE id = ? AND user_id = ?')->execute([$id, $uid]);
        flash('success', 'Budget deleted.');
        redirect('budgets');
    }

    $name = str_limit(input('name'), 80);
    $period = input('period_type');
    $start = input('start_date');
    $end = input('end_date');
    $limit = parse_amount(input('total_limit'));
    $raw_limits = is_array($_POST['limits'] ?? null) ? $_POST['limits'] : [];

    $errors = [];
    if ($name === '') {
        $errors['name'] = 'Give the budget a name.';
    }
    if (!isset($periods[$period])) {
        $errors['period_type'] = 'Choose a period.';
    }
    if (!is_valid_date($start)) {
        $errors['start_date'] = 'Pick a start date.';
    }
    if (!is_valid_date($end)) {
        $errors['end_date'] = 'Pick an end date.';
    } elseif (is_valid_date($start) && $end < $start) {
        $errors['end_date'] = 'The end date must be on or after the start date.';
    }
    if ($limit === null) {
        $errors['total_limit'] = 'Enter a spending limit greater than zero.';
    }

    $limits = [];
    foreach ($raw_limits as $category_id => $raw) {
        if (!is_string($raw) || trim($raw) === '' || !isset($categories[(int) $category_id])) {
            continue;
        }
        $amount = parse_amount($raw);
        if ($amount === null) {
            $errors['limits'] = 'Category limits must be amounts greater than zero.';
            continue;
        }
        $limits[(int) $category_id] = $amount;
    }

    if ($errors) {
        keep_form($errors, $_POST);
        redirect('budgets', $id ? ['edit' => $id] : ['new' => 1], 'budget-form');
    }

    $pdo = db();
    $pdo->beginTransaction();
    if ($id) {
        $pdo->prepare('UPDATE budgets SET name = ?, period_type = ?, start_date = ?, end_date = ?, total_limit = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?')
            ->execute([$name, $period, $start, $end, $limit, $id, $uid]);
        $pdo->prepare('DELETE FROM budget_categories WHERE budget_id = ?')->execute([$id]);
    } else {
        $pdo->prepare('INSERT INTO budgets (user_id, name, period_type, start_date, end_date, total_limit) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$uid, $name, $period, $start, $end, $limit]);
        $id = (int) $pdo->lastInsertId();
    }
    $insert = $pdo->prepare('INSERT INTO budget_categories (budget_id, expense_category_id, limit_amount) VALUES (?, ?, ?)');
    foreach ($limits as $category_id => $amount) {
        $insert->execute([$id, $category_id, $amount]);
    }
    $pdo->commit();

    flash('success', input('id') ? 'Budget updated.' : 'Budget created.');
    if (notification_prefs($user)['budget_alerts']) {
        check_budget_alerts($user, today()->format('Y-m-d'));
    }
    redirect('budgets');
}

/* ---- Data ------------------------------------------------------------- */
$stmt = db()->prepare('SELECT * FROM budgets WHERE user_id = ? ORDER BY start_date DESC, id DESC');
$stmt->execute([$uid]);
$budgets = $stmt->fetchAll();

$limits_by_budget = [];
if ($budgets) {
    $ids = array_column($budgets, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("SELECT budget_id, expense_category_id, limit_amount FROM budget_categories WHERE budget_id IN ($in)");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $row) {
        $limits_by_budget[(int) $row['budget_id']][(int) $row['expense_category_id']] = (float) $row['limit_amount'];
    }
}

$groups = ['active' => [], 'upcoming' => [], 'past' => []];
foreach ($budgets as $budget) {
    $budget['status'] = budget_status($budget, $uid);
    $groups[$budget['status']['state']][] = $budget;
}

$editing = null;
if ($edit_id = (int) query('edit')) {
    foreach ($budgets as $budget) {
        if ((int) $budget['id'] === $edit_id) {
            $editing = $budget;
        }
    }
}
$show_form = $editing || query('new') !== '' || has_old();

// Form values: previous submission > budget being edited > sensible defaults (this month)
[$default_start, $default_end] = month_bounds(today());
$form = [
    'id' => $editing['id'] ?? 0,
    'name' => has_old() ? old('name') : ($editing['name'] ?? ''),
    'period_type' => has_old() ? old('period_type') : ($editing['period_type'] ?? 'monthly'),
    'start_date' => has_old() ? old('start_date') : ($editing['start_date'] ?? $default_start),
    'end_date' => has_old() ? old('end_date') : ($editing['end_date'] ?? $default_end),
    'total_limit' => has_old() ? old('total_limit') : (isset($editing['total_limit']) ? number_format((float) $editing['total_limit'], 2, '.', '') : ''),
];
$old_limits = has_old()
    ? array_filter((array) (form_state()['old']['limits'] ?? []), 'is_string')
    : array_map(static fn(float $v) => number_format($v, 2, '.', ''), $editing ? ($limits_by_budget[(int) $editing['id']] ?? []) : []);

function render_budget_card(array $budget, array $limits, array $categories, array $periods): void
{
    $s = $budget['status'];
    ?>
<article class="panel card">
  <div class="card-head">
    <div class="truncate">
      <h3 class="card-title truncate"><?= e($budget['name']) ?></h3>
      <p class="card-meta"><?= e($periods[$budget['period_type']] ?? ucfirst($budget['period_type'])) ?> · <?= e(fmt_date($budget['start_date'], 'M j')) ?> – <?= e(fmt_date($budget['end_date'], 'M j, Y')) ?></p>
    </div>
    <span class="tag <?= tone_class($s['tone']) ?>"><?= e($s['label']) ?></span>
  </div>

  <div>
    <p><span class="figure-sm"><?= money($s['spent']) ?></span> <span class="faint small">of <?= money($s['limit']) ?></span></p>
    <div class="progress" role="img" aria-label="<?= $s['pct'] ?>% of the limit used<?= $s['state'] === 'active' ? ', ' . $s['elapsed_pct'] . '% of the period elapsed' : '' ?>">
      <div class="progress-bar <?= bar_class($s['tone']) ?>" style="--value: <?= min(100, $s['pct']) ?>%"></div>
      <?php if ($s['state'] === 'active'): ?><span class="progress-marker" style="--at: <?= $s['elapsed_pct'] ?>%" title="Even pace for today"></span><?php endif; ?>
    </div>
    <p class="small muted card-detail"><?= e($s['detail']) ?></p>
  </div>

  <?php if ($limits): ?>
  <details class="more">
    <summary><?= icon('chevron-right', 'icon icon-sm') ?><?= plural(count($limits), 'category limit') ?></summary>
    <div class="cat-limits">
      <?php foreach ($limits as $category_id => $cap):
          $used = $s['by_category'][$category_id] ?? 0.0;
          $over = $used > $cap; ?>
      <div>
        <div class="spread"><span class="truncate"><?= e($categories[$category_id] ?? 'Deleted category') ?></span><span class="num <?= $over ? 'neg' : 'faint' ?>"><?= money($used) ?> / <?= money($cap) ?></span></div>
        <div class="progress progress-sm"><div class="progress-bar <?= $over ? 'bad' : 'muted' ?>" style="--value: <?= min(100, percent($used, $cap)) ?>%"></div></div>
      </div>
      <?php endforeach; ?>
    </div>
  </details>
  <?php endif; ?>

  <div class="card-foot">
    <span class="num"><?= $s['remaining'] >= 0 ? money($s['remaining']) . ' left' : money(-$s['remaining']) . ' over' ?></span>
    <div class="cluster">
      <a class="btn btn-ghost btn-sm" href="<?= e(url('budgets', ['edit' => (int) $budget['id']], 'budget-form')) ?>"><?= icon('edit', 'icon icon-sm') ?>Edit</a>
      <form method="post" action="<?= e(url('budgets')) ?>" data-confirm="“<?= e($budget['name']) ?>” and its category limits will be deleted. Your expenses stay." data-confirm-title="Delete this budget?">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= (int) $budget['id'] ?>">
        <button class="btn btn-ghost btn-sm btn-danger-quiet" type="submit"><?= icon('trash', 'icon icon-sm') ?>Delete</button>
      </form>
    </div>
  </div>
</article>
    <?php
}

/* ---- View ------------------------------------------------------------- */
app_open('Budgets');
page_header(
    'Budgets',
    'Set a limit for a period and see whether you\'re on pace. The marker on each bar shows where an even pace would put you today.',
    '<a class="btn btn-primary" href="' . e(url('budgets', ['new' => 1], 'budget-form')) . '" data-toggle="#budget-form" aria-expanded="' . ($show_form ? 'true' : 'false') . '">' . icon('plus') . 'New budget</a>'
);
?>

<section class="panel form-panel" id="budget-form" data-collapsible<?= $show_form ? '' : ' hidden' ?> aria-labelledby="budget-form-title">
  <div class="panel-head">
    <div>
      <h2 class="panel-title" id="budget-form-title"><?= $form['id'] ? 'Edit budget' : 'New budget' ?></h2>
      <p class="panel-sub">Pick a period and the end date fills in for you.</p>
    </div>
    <a class="btn btn-ghost btn-sm" href="<?= e(url('budgets')) ?>"<?= $form['id'] ? '' : ' data-toggle="#budget-form"' ?>>Cancel</a>
  </div>
  <form class="panel-pad stack stack-lg" method="post" action="<?= e(url('budgets')) ?>" data-period-form novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">

    <div class="form-grid">
      <div class="field">
        <label class="label" for="name">Name</label>
        <input class="input" id="name" name="name" maxlength="80" required placeholder="e.g. Monthly spending" value="<?= e($form['name']) ?>"<?= invalid_attr('name') ?>>
        <?= field_error('name') ?>
      </div>
      <div class="field">
        <label class="label" for="total_limit">Spending limit</label>
        <div class="input-affix" style="--affix-w: <?= mb_strlen(currency_symbol()) ?>ch">
          <span class="affix"><?= e(currency_symbol()) ?></span>
          <input class="input num" id="total_limit" name="total_limit" inputmode="decimal" required placeholder="0.00" value="<?= e($form['total_limit']) ?>"<?= invalid_attr('total_limit') ?>>
        </div>
        <?= field_error('total_limit') ?>
      </div>
      <div class="field">
        <label class="label" for="period_type">Period</label>
        <select class="select" id="period_type" name="period_type"<?= invalid_attr('period_type') ?>>
          <?php foreach ($periods as $value => $label): ?>
          <option value="<?= e($value) ?>"<?= $form['period_type'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <?= field_error('period_type') ?>
      </div>
      <div class="form-grid">
        <div class="field">
          <label class="label" for="start_date">Starts</label>
          <input class="input" id="start_date" name="start_date" type="date" required value="<?= e($form['start_date']) ?>"<?= invalid_attr('start_date') ?>>
          <?= field_error('start_date') ?>
        </div>
        <div class="field">
          <label class="label" for="end_date">Ends</label>
          <input class="input" id="end_date" name="end_date" type="date" required value="<?= e($form['end_date']) ?>"<?= invalid_attr('end_date') ?>>
          <?= field_error('end_date') ?>
        </div>
      </div>
    </div>

    <fieldset class="fieldset">
      <legend class="label">Category limits <span class="optional">(optional)</span></legend>
      <p class="hint">Cap individual categories inside this budget. Leave blank for no cap.</p>
      <?= field_error('limits') ?>
      <div class="limit-list">
        <?php foreach ($categories as $category_id => $category_name): ?>
        <label class="limit-item">
          <span class="truncate"><?= e($category_name) ?></span>
          <input class="input num" name="limits[<?= (int) $category_id ?>]" inputmode="decimal" placeholder="No cap" value="<?= e($old_limits[$category_id] ?? '') ?>">
        </label>
        <?php endforeach; ?>
      </div>
    </fieldset>

    <div class="cluster">
      <button class="btn btn-primary" type="submit"><?= $form['id'] ? 'Save changes' : 'Create budget' ?></button>
    </div>
  </form>
</section>

<?php if (!$budgets): ?>
<section class="panel">
  <?= empty_state('budget', 'No budgets yet', 'A budget is a spending limit for a period. Start with one monthly budget for everything, then add category caps where you tend to overspend.', '<a class="btn btn-primary btn-sm" href="' . e(url('budgets', ['new' => 1], 'budget-form')) . '" data-toggle="#budget-form">' . icon('plus', 'icon icon-sm') . 'Create your first budget</a>') ?>
</section>
<?php endif; ?>

<?php foreach (['active' => 'Active now', 'upcoming' => 'Upcoming', 'past' => 'Past'] as $state => $heading):
    if (!$groups[$state]) {
        continue;
    } ?>
<section class="budget-group" aria-labelledby="group-<?= $state ?>">
  <h2 class="section-title" id="group-<?= $state ?>"><?= e($heading) ?> <span class="faint">· <?= count($groups[$state]) ?></span></h2>
  <div class="card-grid">
    <?php foreach ($groups[$state] as $budget) {
        render_budget_card($budget, $limits_by_budget[(int) $budget['id']] ?? [], $categories, $periods);
    } ?>
  </div>
</section>
<?php endforeach; ?>

<?php app_close(); ?>
