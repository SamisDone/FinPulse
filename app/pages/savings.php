<?php
defined('SIXPENCE') || exit;

$user = require_login();
$uid = $user['id'];

/* ---- Actions ---------------------------------------------------------- */
if (is_post()) {
    verify_csrf();
    $action = input('action');
    $id = (int) input('id');

    $owns = static function (string $table) use ($id, $uid): void {
        $stmt = db()->prepare("SELECT 1 FROM $table WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $uid]);
        if (!$stmt->fetchColumn()) {
            flash('error', 'That item no longer exists.');
            redirect('savings');
        }
    };

    switch ($action) {
        case 'account_save':
            $name = str_limit(input('account_name'), 80);
            $balance = parse_amount(input('current_balance'), allow_zero: true);
            $errors = [];
            if ($name === '') {
                $errors['account_name'] = 'Name the account, like "High-yield savings".';
            }
            if ($balance === null) {
                $errors['current_balance'] = 'Enter the current balance, like 2500.00.';
            }
            if ($id) {
                $owns('savings_accounts');
            }
            if ($errors) {
                keep_form($errors, $_POST + ['form' => 'account']);
                redirect('savings', $id ? ['edit_account' => $id] : ['new' => 'account'], 'account-form');
            }
            if ($id) {
                db()->prepare('UPDATE savings_accounts SET account_name = ?, current_balance = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?')
                    ->execute([$name, $balance, $id, $uid]);
                flash('success', $name . ' updated.');
            } else {
                db()->prepare('INSERT INTO savings_accounts (user_id, account_name, current_balance) VALUES (?, ?, ?)')
                    ->execute([$uid, $name, $balance]);
                flash('success', $name . ' added.');
            }
            redirect('savings');

        case 'account_delete':
            $owns('savings_accounts');
            db()->prepare('DELETE FROM savings_accounts WHERE id = ? AND user_id = ?')->execute([$id, $uid]);
            flash('success', 'Account removed.');
            redirect('savings');

        case 'goal_save':
            $name = str_limit(input('goal_name'), 80);
            $target = parse_amount(input('target_amount'));
            $current = parse_amount(input('current_amount') ?: '0', allow_zero: true);
            $date = input('target_date');
            $description = str_limit(input('description'), 255);
            $errors = [];
            if ($name === '') {
                $errors['goal_name'] = 'What are you saving for?';
            }
            if ($target === null) {
                $errors['target_amount'] = 'Enter a target greater than zero.';
            }
            if ($current === null) {
                $errors['current_amount'] = 'Enter how much you have so far, or 0.';
            }
            if ($date !== '' && !is_valid_date($date)) {
                $errors['target_date'] = 'Pick a valid date or leave it empty.';
            }
            if ($id) {
                $owns('financial_goals');
            }
            if ($errors) {
                keep_form($errors, $_POST + ['form' => 'goal']);
                redirect('savings', $id ? ['edit_goal' => $id] : ['new' => 'goal'], 'goal-form');
            }
            $status = $current >= $target ? 'completed' : 'active';
            $params = [$name, $target, $current, $date ?: null, $description, $status];
            if ($id) {
                db()->prepare('UPDATE financial_goals SET goal_name = ?, target_amount = ?, current_amount = ?, target_date = ?, description = ?, status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?')
                    ->execute([...$params, $id, $uid]);
                flash('success', 'Goal updated.');
            } else {
                db()->prepare('INSERT INTO financial_goals (goal_name, target_amount, current_amount, target_date, description, status, user_id) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([...$params, $uid]);
                flash('success', 'Goal added. ' . goal_status(['target_amount' => $target, 'current_amount' => $current, 'target_date' => $date ?: null, 'status' => $status])['note'] . '.');
            }
            redirect('savings');

        case 'goal_contribute':
            $owns('financial_goals');
            $amount = parse_amount(input('amount'));
            if ($amount === null) {
                flash('error', 'Enter an amount greater than zero to add to the goal.');
                redirect('savings', [], 'goal-' . $id);
            }
            $stmt = db()->prepare('SELECT * FROM financial_goals WHERE id = ? AND user_id = ?');
            $stmt->execute([$id, $uid]);
            $goal = $stmt->fetch();
            $goal['current_amount'] = round((float) $goal['current_amount'] + $amount, 2);
            $goal['status'] = $goal['current_amount'] >= (float) $goal['target_amount'] ? 'completed' : 'active';
            db()->prepare('UPDATE financial_goals SET current_amount = ?, status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
                ->execute([$goal['current_amount'], $goal['status'], $id]);
            $status = goal_status($goal);
            flash('success', $status['done']
                ? 'You reached “' . $goal['goal_name'] . '”. Nicely done.'
                : 'Added ' . money($amount) . ' to “' . $goal['goal_name'] . '”. ' . $status['pct'] . '% there.');
            redirect('savings', [], 'goal-' . $id);

        case 'goal_delete':
            $owns('financial_goals');
            db()->prepare('DELETE FROM financial_goals WHERE id = ? AND user_id = ?')->execute([$id, $uid]);
            flash('success', 'Goal deleted.');
            redirect('savings');
    }
    redirect('savings');
}

/* ---- Data ------------------------------------------------------------- */
$stmt = db()->prepare('SELECT * FROM savings_accounts WHERE user_id = ? ORDER BY current_balance DESC, id');
$stmt->execute([$uid]);
$accounts = $stmt->fetchAll();
$total_saved = array_sum(array_map('floatval', array_column($accounts, 'current_balance')));

$stmt = db()->prepare("SELECT * FROM financial_goals WHERE user_id = ? ORDER BY CASE WHEN target_date IS NULL THEN 1 ELSE 0 END, target_date, id");
$stmt->execute([$uid]);
$active_goals = $done_goals = [];
foreach ($stmt->fetchAll() as $goal) {
    $goal['calc'] = goal_status($goal);
    if ($goal['calc']['done']) {
        $done_goals[] = $goal;
    } else {
        $active_goals[] = $goal;
    }
}

$find = static function (array $items, int $id): ?array {
    foreach ($items as $item) {
        if ((int) $item['id'] === $id) {
            return $item;
        }
    }
    return null;
};
$edit_account = ($eid = (int) query('edit_account')) ? $find($accounts, $eid) : null;
$edit_goal = ($gid = (int) query('edit_goal')) ? $find([...$active_goals, ...$done_goals], $gid) : null;

$reopen = has_old() ? old('form') : '';
$show_account_form = $edit_account || query('new') === 'account' || $reopen === 'account';
$show_goal_form = $edit_goal || query('new') === 'goal' || $reopen === 'goal';

$val = static function (string $field, ?array $record, string $column, string $fallback = '', bool $money = false) use ($reopen): string {
    if ($reopen !== '') {
        return old($field);
    }
    if ($record && isset($record[$column])) {
        return $money ? number_format((float) $record[$column], 2, '.', '') : (string) $record[$column];
    }
    return $fallback;
};
$symbol = currency_symbol();

function render_goal_card(array $goal): void
{
    $c = $goal['calc'];
    ?>
<article class="panel card" id="goal-<?= (int) $goal['id'] ?>">
  <div class="card-head">
    <div class="truncate">
      <h3 class="card-title truncate"><?= e($goal['goal_name']) ?></h3>
      <p class="card-meta truncate"><?= e($goal['description'] ?: ($goal['target_date'] ? 'By ' . fmt_date($goal['target_date'], 'F Y') : 'No target date')) ?></p>
    </div>
    <?php if ($c['done']): ?><span class="tag tag-good"><?= icon('check', 'icon icon-sm') ?>Reached</span>
    <?php elseif ($c['overdue']): ?><span class="tag tag-warn">Past target date</span>
    <?php else: ?><span class="tag num"><?= $c['pct'] ?>%</span><?php endif; ?>
  </div>

  <div>
    <p><span class="figure-sm"><?= money($goal['current_amount']) ?></span> <span class="faint small">of <?= money($goal['target_amount']) ?></span></p>
    <div class="progress" role="img" aria-label="<?= $c['pct'] ?>% saved"><div class="progress-bar" style="--value: <?= $c['pct'] ?>%"></div></div>
    <p class="small muted card-detail"><?= e($c['note']) ?></p>
  </div>

  <?php if (!$c['done']): ?>
  <form class="contribute" method="post" action="<?= e(url('savings')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="goal_contribute">
    <input type="hidden" name="id" value="<?= (int) $goal['id'] ?>">
    <div class="input-affix" style="--affix-w: <?= mb_strlen(currency_symbol()) ?>ch">
      <span class="affix"><?= e(currency_symbol()) ?></span>
      <label class="sr-only" for="contribute-<?= (int) $goal['id'] ?>">Amount to add to <?= e($goal['goal_name']) ?></label>
      <input class="input num" id="contribute-<?= (int) $goal['id'] ?>" name="amount" inputmode="decimal" placeholder="<?= $c['per_month'] ? e(number_format(ceil($c['per_month']), 0, '.', '')) : '100' ?>" required>
    </div>
    <button class="btn btn-sm" type="submit"><?= icon('plus', 'icon icon-sm') ?>Add money</button>
  </form>
  <?php endif; ?>

  <div class="card-foot">
    <span><?= $c['done'] ? 'Saved ' . money($goal['target_amount']) : money($c['remaining']) . ' to go' ?></span>
    <div class="cluster">
      <a class="btn btn-ghost btn-sm" href="<?= e(url('savings', ['edit_goal' => (int) $goal['id']], 'goal-form')) ?>"><?= icon('edit', 'icon icon-sm') ?>Edit</a>
      <form method="post" action="<?= e(url('savings')) ?>" data-confirm="“<?= e($goal['goal_name']) ?>” will be deleted. Your account balances aren't affected." data-confirm-title="Delete this goal?">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="goal_delete">
        <input type="hidden" name="id" value="<?= (int) $goal['id'] ?>">
        <button class="btn btn-ghost btn-sm btn-danger-quiet" type="submit"><?= icon('trash', 'icon icon-sm') ?>Delete</button>
      </form>
    </div>
  </div>
</article>
    <?php
}

/* ---- View ------------------------------------------------------------- */
app_open('Savings');
page_header(
    'Savings',
    'Balances you\'re holding onto, and what you\'re saving toward.',
    '<a class="btn" href="' . e(url('savings', ['new' => 'account'], 'account-form')) . '" data-toggle="#account-form">' . icon('wallet') . 'Add account</a>'
    . '<a class="btn btn-primary" href="' . e(url('savings', ['new' => 'goal'], 'goal-form')) . '" data-toggle="#goal-form">' . icon('plus') . 'New goal</a>'
);
?>

<section class="panel form-panel" id="account-form" data-collapsible<?= $show_account_form ? '' : ' hidden' ?> aria-labelledby="account-form-title">
  <div class="panel-head">
    <div>
      <h2 class="panel-title" id="account-form-title"><?= $edit_account ? 'Update account' : 'Add a savings account' ?></h2>
      <p class="panel-sub">Update the balance whenever you check your bank.</p>
    </div>
    <a class="btn btn-ghost btn-sm" href="<?= e(url('savings')) ?>"<?= $edit_account ? '' : ' data-toggle="#account-form"' ?>>Cancel</a>
  </div>
  <form class="panel-pad stack" method="post" action="<?= e(url('savings')) ?>" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="account_save">
    <input type="hidden" name="id" value="<?= (int) ($edit_account['id'] ?? ($reopen === 'account' ? old('id') : 0)) ?>">
    <div class="form-grid">
      <div class="field">
        <label class="label" for="account_name">Account name</label>
        <input class="input" id="account_name" name="account_name" maxlength="80" required placeholder="e.g. High-yield savings" value="<?= e($val('account_name', $edit_account, 'account_name')) ?>"<?= invalid_attr('account_name') ?>>
        <?= field_error('account_name') ?>
      </div>
      <div class="field">
        <label class="label" for="current_balance">Current balance</label>
        <div class="input-affix" style="--affix-w: <?= mb_strlen($symbol) ?>ch">
          <span class="affix"><?= e($symbol) ?></span>
          <input class="input num" id="current_balance" name="current_balance" inputmode="decimal" required placeholder="0.00" value="<?= e($val('current_balance', $edit_account, 'current_balance', '', true)) ?>"<?= invalid_attr('current_balance') ?>>
        </div>
        <?= field_error('current_balance') ?>
      </div>
    </div>
    <div class="cluster"><button class="btn btn-primary" type="submit"><?= $edit_account ? 'Save changes' : 'Add account' ?></button></div>
  </form>
</section>

<section class="panel form-panel" id="goal-form" data-collapsible<?= $show_goal_form ? '' : ' hidden' ?> aria-labelledby="goal-form-title">
  <div class="panel-head">
    <div>
      <h2 class="panel-title" id="goal-form-title"><?= $edit_goal ? 'Edit goal' : 'New savings goal' ?></h2>
      <p class="panel-sub">Add a target date and you'll get a monthly amount to aim for.</p>
    </div>
    <a class="btn btn-ghost btn-sm" href="<?= e(url('savings')) ?>"<?= $edit_goal ? '' : ' data-toggle="#goal-form"' ?>>Cancel</a>
  </div>
  <form class="panel-pad stack" method="post" action="<?= e(url('savings')) ?>" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="goal_save">
    <input type="hidden" name="id" value="<?= (int) ($edit_goal['id'] ?? ($reopen === 'goal' ? old('id') : 0)) ?>">
    <div class="form-grid">
      <div class="field span-2">
        <label class="label" for="goal_name">What are you saving for?</label>
        <input class="input" id="goal_name" name="goal_name" maxlength="80" required placeholder="e.g. Two weeks in Japan" value="<?= e($val('goal_name', $edit_goal, 'goal_name')) ?>"<?= invalid_attr('goal_name') ?>>
        <?= field_error('goal_name') ?>
      </div>
      <div class="field">
        <label class="label" for="target_amount">Target</label>
        <div class="input-affix" style="--affix-w: <?= mb_strlen($symbol) ?>ch">
          <span class="affix"><?= e($symbol) ?></span>
          <input class="input num" id="target_amount" name="target_amount" inputmode="decimal" required placeholder="0.00" value="<?= e($val('target_amount', $edit_goal, 'target_amount', '', true)) ?>"<?= invalid_attr('target_amount') ?>>
        </div>
        <?= field_error('target_amount') ?>
      </div>
      <div class="field">
        <label class="label" for="current_amount">Saved so far</label>
        <div class="input-affix" style="--affix-w: <?= mb_strlen($symbol) ?>ch">
          <span class="affix"><?= e($symbol) ?></span>
          <input class="input num" id="current_amount" name="current_amount" inputmode="decimal" placeholder="0.00" value="<?= e($val('current_amount', $edit_goal, 'current_amount', '', true)) ?>"<?= invalid_attr('current_amount') ?>>
        </div>
        <?= field_error('current_amount') ?>
      </div>
      <div class="field">
        <label class="label" for="target_date">Target date <span class="optional">(optional)</span></label>
        <input class="input" id="target_date" name="target_date" type="date" value="<?= e($val('target_date', $edit_goal, 'target_date')) ?>"<?= invalid_attr('target_date') ?>>
        <?= field_error('target_date') ?>
      </div>
      <div class="field">
        <label class="label" for="goal_description">Note <span class="optional">(optional)</span></label>
        <input class="input" id="goal_description" name="description" maxlength="255" placeholder="e.g. Tokyo and Kyoto in spring" value="<?= e($val('description', $edit_goal, 'description')) ?>">
      </div>
    </div>
    <div class="cluster"><button class="btn btn-primary" type="submit"><?= $edit_goal ? 'Save changes' : 'Add goal' ?></button></div>
  </form>
</section>

<section class="panel" aria-labelledby="accounts-title">
  <div class="panel-head">
    <div>
      <h2 class="panel-title" id="accounts-title">Accounts</h2>
      <p class="panel-sub"><?= plural(count($accounts), 'account') ?></p>
    </div>
    <p class="figure-md num" aria-label="Total saved"><?= money_figure($total_saved) ?></p>
  </div>
  <?php if ($accounts): ?>
    <?php foreach ($accounts as $account): ?>
    <div class="account-row">
      <span class="ledger-icon in" aria-hidden="true"><?= icon('savings', 'icon icon-sm') ?></span>
      <div class="ledger-main">
        <p class="ledger-title truncate"><?= e($account['account_name']) ?></p>
        <p class="ledger-meta"><?= percent((float) $account['current_balance'], $total_saved) ?>% of savings · updated <?= e(fmt_date(substr((string) ($account['updated_at'] ?? $account['created_at']), 0, 10), 'M j, Y')) ?></p>
      </div>
      <span class="ledger-amount"><?= money($account['current_balance']) ?></span>
      <div class="ledger-actions">
        <a class="icon-btn" href="<?= e(url('savings', ['edit_account' => (int) $account['id']], 'account-form')) ?>" aria-label="Update <?= e($account['account_name']) ?>" title="Update balance"><?= icon('edit', 'icon icon-sm') ?></a>
        <form method="post" action="<?= e(url('savings')) ?>" data-confirm="“<?= e($account['account_name']) ?>” will be removed from your savings." data-confirm-title="Remove this account?" data-confirm-label="Remove">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="account_delete">
          <input type="hidden" name="id" value="<?= (int) $account['id'] ?>">
          <button class="icon-btn danger" type="submit" aria-label="Remove <?= e($account['account_name']) ?>" title="Remove"><?= icon('trash', 'icon icon-sm') ?></button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  <?php else: ?>
    <?= empty_state('savings', 'No accounts yet', 'Add the accounts where you keep savings, like a high-yield account or an emergency fund.', '<a class="btn btn-sm" href="' . e(url('savings', ['new' => 'account'], 'account-form')) . '" data-toggle="#account-form">' . icon('plus', 'icon icon-sm') . 'Add an account</a>') ?>
  <?php endif; ?>
</section>

<section class="section-gap" aria-labelledby="goals-title">
  <h2 class="section-title" id="goals-title">Goals <span class="faint">· <?= count($active_goals) ?> in progress</span></h2>
  <?php if ($active_goals): ?>
  <div class="card-grid">
    <?php foreach ($active_goals as $goal) { render_goal_card($goal); } ?>
  </div>
  <?php else: ?>
  <div class="panel">
    <?= empty_state('target', 'No goals in progress', 'A goal turns “I should save more” into a number you can hit each month.', '<a class="btn btn-primary btn-sm" href="' . e(url('savings', ['new' => 'goal'], 'goal-form')) . '" data-toggle="#goal-form">' . icon('plus', 'icon icon-sm') . 'Create a goal</a>') ?>
  </div>
  <?php endif; ?>
</section>

<?php if ($done_goals): ?>
<section class="section-gap" aria-labelledby="done-title">
  <h2 class="section-title" id="done-title">Reached <span class="faint">· <?= count($done_goals) ?></span></h2>
  <div class="card-grid">
    <?php foreach ($done_goals as $goal) { render_goal_card($goal); } ?>
  </div>
</section>
<?php endif; ?>

<?php app_close(); ?>
