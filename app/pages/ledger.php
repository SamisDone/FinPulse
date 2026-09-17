<?php
/**
 * Income and Expenses ledgers. Both pages have the same shape; the config describes what differs.
 * Routed from /income and /expenses with $route_args['kind'].
 */
defined('SIXPENCE') || exit;

const TX_PER_PAGE = 25;

function transaction_config(string $kind): array
{
    if ($kind === 'income') {
        return [
            'kind' => 'income',
            'route' => 'income',
            'title' => 'Income',
            'subtitle' => 'Everything that came in.',
            'noun' => 'income',
            'add_label' => 'Add income',
            'table' => 'income',
            'date_col' => 'income_date',
            'primary' => ['col' => 'source_id', 'table' => 'income_sources', 'label' => 'Source', 'placeholder' => 'e.g. Salary'],
            'secondary' => ['col' => 'category_id', 'table' => 'income_categories', 'label' => 'Category', 'placeholder' => 'e.g. Primary job'],
            'tone' => 'in',
            'description_placeholder' => 'e.g. September paycheck',
            'empty_title' => 'No income recorded',
            'empty_text' => 'Add your paycheck or any other money that came in to see your real balance for the month.',
        ];
    }
    return [
        'kind' => 'expense',
        'route' => 'expenses',
        'title' => 'Expenses',
        'subtitle' => 'Everything that went out.',
        'noun' => 'expense',
        'add_label' => 'Add expense',
        'table' => 'expenses',
        'date_col' => 'expense_date',
        'primary' => ['col' => 'category_id', 'table' => 'expense_categories', 'label' => 'Category', 'placeholder' => 'e.g. Groceries'],
        'secondary' => ['col' => 'payment_method_id', 'table' => 'payment_methods', 'label' => 'Paid with', 'placeholder' => 'e.g. Debit card'],
        'tone' => 'out',
        'description_placeholder' => 'e.g. Weekly shop at Aldi',
        'empty_title' => 'No expenses yet',
        'empty_text' => 'Log what you spend as it happens. Budgets and reports fill in automatically.',
    ];
}

/* ---------------------------------------------------------------------------
 * POST: save, delete, stop a schedule
 * ------------------------------------------------------------------------ */

function transaction_handle_post(array $cfg, array $user): never
{
    verify_csrf();

    $table = $cfg['table'];
    $uid = $user['id'];
    $id = (int) input('id');
    $d = $cfg['date_col'];
    // Only return to this ledger's own URL.
    $back = input('return');
    $back = str_starts_with($back, url($cfg['route'])) ? $back : url($cfg['route']);

    $owned = null;
    if ($id) {
        $stmt = db()->prepare("SELECT * FROM $table WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $uid]);
        $owned = $stmt->fetch() ?: null;
        if (!$owned) {
            flash('error', 'That entry no longer exists.');
            redirect($cfg['route']);
        }
    }

    switch (input('action')) {
        case 'delete':
            db()->prepare("DELETE FROM $table WHERE id = ? AND user_id = ?")->execute([$id, $uid]);
            flash('success', ucfirst($cfg['noun']) . ' deleted.' . ((int) $owned['is_recurring'] === 1 ? ' Its schedule stopped too.' : ''));
            redirect_to_url(preg_replace('/([?&])edit=\d+&?/', '$1', $back));

        case 'stop_recurring':
            db()->prepare("UPDATE $table SET is_recurring = 0, next_recurrence_date = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?")->execute([$id, $uid]);
            flash('success', 'Stopped repeating “' . ($owned['description'] ?: ucfirst($cfg['noun'])) . '”. Entries already added stay in your ledger.');
            redirect_to_url($back);
    }

    $amount = parse_amount(input('amount'));
    $date = input('date');
    $primary = str_limit(input('primary'), 60);
    $secondary = str_limit(input('secondary'), 60);
    $description = str_limit(input('description'), 255);
    $repeat = input('repeat');

    $errors = [];
    if ($amount === null) {
        $errors['amount'] = 'Enter an amount greater than zero, like 42.50.';
    }
    if (!is_valid_date($date)) {
        $errors['date'] = 'Pick a valid date.';
    }
    if ($primary === '') {
        $errors['primary'] = 'Choose or type a ' . strtolower($cfg['primary']['label']) . '.';
    }
    if ($repeat !== '' && !isset(recurrence_periods()[$repeat])) {
        $errors['repeat'] = 'Choose how often it repeats.';
    }
    if ($errors) {
        keep_form($errors, $_POST);
        redirect_to_url(strtok($back, '#') . '#add');
    }

    $primary_id = lookup_id($uid, $cfg['primary']['table'], $primary);
    $secondary_id = lookup_id($uid, $cfg['secondary']['table'], $secondary);
    $p = $cfg['primary']['col'];
    $s = $cfg['secondary']['col'];

    if ($id) {
        db()->prepare("UPDATE $table SET amount = ?, $d = ?, $p = ?, $s = ?, description = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?")
            ->execute([$amount, $date, $primary_id, $secondary_id, $description, $id, $uid]);
    } else {
        db()->prepare("INSERT INTO $table (user_id, amount, $d, $p, $s, description) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$uid, $amount, $date, $primary_id, $secondary_id, $description]);
        $id = (int) db()->lastInsertId();
    }

    $schedule_note = '';
    if ($repeat !== '') {
        $next = schedule_next_date($table, $id, $date, $repeat);
        db()->prepare("UPDATE $table SET is_recurring = 1, recurrence_period = ?, next_recurrence_date = ? WHERE id = ?")->execute([$repeat, $next, $id]);
        // A schedule that starts in the past catches up right away.
        $added = process_recurring($uid)[$uid]['count'] ?? 0;
        $next_stmt = db()->prepare("SELECT next_recurrence_date FROM $table WHERE id = ?");
        $next_stmt->execute([$id]);
        $schedule_note = ' Repeats ' . strtolower(recurrence_label($repeat)) . '; next on ' . fmt_date((string) $next_stmt->fetchColumn(), 'M j') . '.'
            . ($added ? ' Added ' . plural($added, 'earlier entry', 'earlier entries') . ' too.' : '');
    } elseif ($owned && (int) $owned['is_recurring'] === 1) {
        db()->prepare("UPDATE $table SET is_recurring = 0, recurrence_period = NULL, next_recurrence_date = NULL WHERE id = ?")->execute([$id]);
        $schedule_note = ' It no longer repeats.';
    }

    flash('success', ($owned ? 'Changes saved.' : money($amount) . ' ' . $cfg['noun'] . ' added to ' . fmt_date($date, 'M j') . '.') . $schedule_note);

    if ($cfg['kind'] === 'expense' && notification_prefs($user)['budget_alerts']) {
        check_budget_alerts($user, today()->format('Y-m-d'));
    }

    // Land on the month the entry belongs to, keeping the add form ready for the next one.
    redirect($cfg['route'], ['month' => substr($date, 0, 7)], $owned ? '' : 'add');
}

/* ---------------------------------------------------------------------------
 * GET: filters, data, view
 * ------------------------------------------------------------------------ */

function ledger_page(string $kind): void
{
    $cfg = transaction_config($kind);
    $user = require_login();

    if (is_post()) {
        transaction_handle_post($cfg, $user);
    }

    $uid = $user['id'];
    $table = $cfg['table'];
    $d = $cfg['date_col'];
    $p = $cfg['primary'];
    $s = $cfg['secondary'];
    $today = today();

    // Filters
    $month_param = query('month');
    $all_time = $month_param === 'all';
    $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month_param)
        ? new DateTimeImmutable($month_param . '-01')
        : $today->modify('first day of this month');
    [$start, $end] = month_bounds($month);
    $search = mb_substr(query('q'), 0, 80);
    $primary_filter = (int) query('cat');
    $page = max(1, (int) query('page', '1'));

    $where = ['t.user_id = :uid'];
    $params = [':uid' => $uid];
    if (!$all_time) {
        $where[] = "t.$d BETWEEN :start AND :end";
        $params[':start'] = $start;
        $params[':end'] = $end;
    }
    if ($search !== '') {
        // "!" as the LIKE escape character behaves the same in SQLite and MySQL; a backslash does not.
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
        $where[] = "(t.description LIKE :q1 ESCAPE '!' OR p.name LIKE :q2 ESCAPE '!' OR s.name LIKE :q3 ESCAPE '!')";
        $params += [':q1' => $like, ':q2' => $like, ':q3' => $like];
    }
    if ($primary_filter) {
        $where[] = "t.{$p['col']} = :cat";
        $params[':cat'] = $primary_filter;
    }
    $joins = "FROM $table t LEFT JOIN {$p['table']} p ON p.id = t.{$p['col']} LEFT JOIN {$s['table']} s ON s.id = t.{$s['col']}";
    $from = "$joins WHERE " . implode(' AND ', $where);

    $summary = db()->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(t.amount), 0) AS total $from");
    $summary->execute($params);
    ['n' => $count, 'total' => $total] = $summary->fetch();
    $count = (int) $count;
    $total = (float) $total;

    $pages = max(1, (int) ceil($count / TX_PER_PAGE));
    $page = min($page, $pages);
    $offset = ($page - 1) * TX_PER_PAGE;

    $rows = db()->prepare("SELECT t.id, t.amount, t.$d AS date, t.description, t.is_recurring, t.recurrence_period, t.recurring_source_id,
            p.name AS primary_name, s.name AS secondary_name
        $from ORDER BY t.$d DESC, t.id DESC LIMIT " . TX_PER_PAGE . " OFFSET $offset");
    $rows->execute($params);
    $entries = $rows->fetchAll();

    // Month-over-month comparison (plain month view only). While a month is still in progress,
    // compare against the same point last month so mid-month numbers aren't misleading.
    $comparison = null;
    $compare_to_date = false;
    if (!$all_time && $search === '' && !$primary_filter) {
        $previous = $month->modify('-1 month');
        [$ps, $pe] = month_bounds($previous);
        if ($month->format('Y-m') === $today->format('Y-m')) {
            $compare_to_date = true;
            $pe = $previous->format('Y-m-') . sprintf('%02d', min((int) $today->format('j'), (int) $previous->format('t')));
        }
        $prev = db()->prepare("SELECT COALESCE(SUM(amount), 0) FROM $table WHERE user_id = ? AND $d BETWEEN ? AND ?");
        $prev->execute([$uid, $ps, $pe]);
        $comparison = (float) $prev->fetchColumn();
    }

    $has_any = true;
    if ($count === 0) {
        $any = db()->prepare("SELECT 1 FROM $table WHERE user_id = ? LIMIT 1");
        $any->execute([$uid]);
        $has_any = (bool) $any->fetchColumn();
    }

    $primary_names = lookup_names($uid, $p['table']);
    $secondary_names = lookup_names($uid, $s['table']);
    $schedules = recurring_schedules($uid, $table);

    $editing = null;
    if ($edit_id = (int) query('edit')) {
        $stmt = db()->prepare("SELECT t.id, t.amount, t.$d AS date, t.description, t.is_recurring, t.recurrence_period, p.name AS primary_name, s.name AS secondary_name
            $joins WHERE t.user_id = ? AND t.id = ?");
        $stmt->execute([$uid, $edit_id]);
        $editing = $stmt->fetch() ?: null;
    }

    $days = [];
    foreach ($entries as $entry) {
        $days[$entry['date']]['rows'][] = $entry;
        $days[$entry['date']]['total'] = ($days[$entry['date']]['total'] ?? 0) + (float) $entry['amount'];
    }

    $return = url_with([]);
    $is_current_month = $month->format('Y-m') === $today->format('Y-m');
    $period_label = $all_time ? 'All time' : $month->format('F Y');

    app_open($cfg['title']);
    page_header(
        $cfg['title'],
        e($cfg['subtitle']),
        '<a class="btn btn-primary" href="#add">' . icon('plus') . e($cfg['add_label']) . '</a>'
    );
    ?>
<div class="tx-layout">
  <section class="panel" aria-labelledby="ledger-title">
    <h2 class="sr-only" id="ledger-title"><?= e($cfg['title']) ?> for <?= e($period_label) ?></h2>
    <div class="tx-toolbar">
      <div class="month-nav">
        <?php if (!$all_time): ?>
        <a class="icon-btn" href="<?= e(url_with(['month' => $month->modify('-1 month')->format('Y-m'), 'page' => null, 'edit' => null])) ?>" aria-label="Previous month"><?= icon('chevron-left') ?></a>
        <span class="label-month"><?= e($period_label) ?></span>
        <a class="icon-btn" href="<?= e(url_with(['month' => $month->modify('+1 month')->format('Y-m'), 'page' => null, 'edit' => null])) ?>" aria-label="Next month"><?= icon('chevron-right') ?></a>
        <?php else: ?>
        <span class="label-month">All time</span>
        <?php endif; ?>
      </div>
      <div class="seg" role="group" aria-label="Period">
        <a href="<?= e(url_with(['month' => null, 'page' => null, 'edit' => null])) ?>"<?= !$all_time && $is_current_month ? ' aria-current="true"' : '' ?>>This month</a>
        <a href="<?= e(url_with(['month' => 'all', 'page' => null, 'edit' => null])) ?>"<?= $all_time ? ' aria-current="true"' : '' ?>>All time</a>
      </div>
      <form class="tx-filters" method="get" action="<?= e(url($cfg['route'])) ?>" role="search">
        <?php if ($month_param !== ''): ?><input type="hidden" name="month" value="<?= e($month_param) ?>"><?php endif; ?>
        <div class="input-affix search">
          <span class="affix"><?= icon('search', 'icon icon-sm') ?></span>
          <input class="input" type="search" name="q" value="<?= e($search) ?>" placeholder="Search notes and names" aria-label="Search <?= e(strtolower($cfg['title'])) ?>" data-search>
        </div>
        <label class="sr-only" for="cat-filter">Filter by <?= e(strtolower($p['label'])) ?></label>
        <select class="select" id="cat-filter" name="cat" data-autosubmit>
          <option value="">All <?= $p['label'] === 'Category' ? 'categories' : 'sources' ?></option>
          <?php foreach ($primary_names as $pid => $pname): ?>
          <option value="<?= (int) $pid ?>"<?= $pid === $primary_filter ? ' selected' : '' ?>><?= e($pname) ?></option>
          <?php endforeach; ?>
        </select>
        <noscript><button class="btn btn-sm" type="submit">Apply</button></noscript>
      </form>
    </div>

    <?php if ($count > 0): ?>
    <div class="tx-summary">
      <span class="figure-md num"><?= money_figure($total) ?></span>
      <span class="muted small">
        <?= plural($count, 'entry', 'entries') ?>
        <?php if ($comparison !== null && $comparison > 0):
            $delta = $total - $comparison;
            $pct = (int) round(abs($delta) / $comparison * 100);
            ?>
          · <?= $pct === 0 ? 'same as' : $pct . '% ' . ($delta > 0 ? 'more than' : 'less than') ?> <?= $compare_to_date ? 'this point in ' : '' ?><?= e($month->modify('-1 month')->format('F')) ?>
        <?php endif; ?>
        <?php if ($search !== '' || $primary_filter): ?>
          · <a class="link" href="<?= e(url_with(['q' => null, 'cat' => null, 'page' => null])) ?>">Clear filters</a>
        <?php endif; ?>
      </span>
    </div>

    <div class="ledger">
      <?php foreach ($days as $date => $day): ?>
      <div class="ledger-day"><span><?= e(day_label($date)) ?></span><span class="num"><?= money($day['total']) ?></span></div>
        <?php foreach ($day['rows'] as $row):
            $title = $row['description'] ?: ($row['primary_name'] ?: ucfirst($cfg['noun']));
            $meta = array_filter([$row['description'] ? $row['primary_name'] : null, $row['secondary_name']]);
            $abbr = mb_strtoupper(mb_substr(preg_replace('/[^\p{L}\p{N}]/u', '', $row['primary_name'] ?? '') ?: '?', 0, 2));
            $is_editing = $editing && (int) $editing['id'] === (int) $row['id'];
            $repeat_hint = (int) $row['is_recurring'] === 1
                ? 'Repeats ' . strtolower(recurrence_label($row['recurrence_period']))
                : ($row['recurring_source_id'] ? 'Added automatically from a schedule' : null);
            ?>
      <div class="ledger-row<?= $is_editing ? ' is-editing' : '' ?>">
        <span class="ledger-icon <?= $cfg['tone'] ?>" aria-hidden="true"><?= e($abbr) ?></span>
        <div class="ledger-main">
          <p class="ledger-title truncate"><?= e($title) ?><?php if ($repeat_hint): ?> <span class="repeat-mark" title="<?= e($repeat_hint) ?>"><?= icon('repeat', 'icon icon-xs') ?><span class="sr-only"><?= e($repeat_hint) ?></span></span><?php endif; ?></p>
          <?php if ($meta): ?><p class="ledger-meta truncate"><?= e(implode(' · ', $meta)) ?></p><?php endif; ?>
        </div>
        <span class="ledger-amount <?= $cfg['tone'] === 'in' ? 'pos' : '' ?>"><?= money((float) $row['amount'] * ($cfg['tone'] === 'in' ? 1 : -1), 'always') ?></span>
        <div class="ledger-actions">
          <a class="icon-btn" href="<?= e(url_with(['edit' => $row['id']], 'add')) ?>" aria-label="Edit <?= e($title) ?>" title="Edit"><?= icon('edit', 'icon icon-sm') ?></a>
          <form method="post" action="<?= e(url($cfg['route'])) ?>" data-confirm="“<?= e($title) ?>” (<?= e(money((float) $row['amount'])) ?>) will be removed from your ledger.<?= (int) $row['is_recurring'] === 1 ? ' Its schedule will stop too.' : '' ?>" data-confirm-title="Delete this <?= e($cfg['noun']) ?>?">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
            <input type="hidden" name="return" value="<?= e($return) ?>">
            <button class="icon-btn danger" type="submit" aria-label="Delete <?= e($title) ?>" title="Delete"><?= icon('trash', 'icon icon-sm') ?></button>
          </form>
        </div>
      </div>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </div>

    <?php if ($pages > 1): ?>
    <div class="panel-foot"><?= pagination($page, $pages, $count, TX_PER_PAGE) ?></div>
    <?php endif; ?>

    <?php elseif (!$has_any): ?>
      <?= empty_state($cfg['kind'] === 'income' ? 'income' : 'expense', $cfg['empty_title'], $cfg['empty_text']) ?>
    <?php elseif ($search !== '' || $primary_filter): ?>
      <?= empty_state('search', 'No matches', 'Nothing in ' . $period_label . ' matches those filters.', '<a class="btn btn-sm" href="' . e(url_with(['q' => null, 'cat' => null, 'page' => null])) . '">Clear filters</a>') ?>
    <?php else: ?>
      <?= empty_state('calendar', 'Nothing in ' . $period_label, 'No ' . $cfg['noun'] . ' was recorded for this period.', '<a class="btn btn-sm" href="' . e(url_with(['month' => 'all', 'page' => null])) . '">View all time</a>') ?>
    <?php endif; ?>
  </section>

  <div class="tx-side">
    <?php transaction_form($cfg, $editing, array_values($primary_names), array_values($secondary_names), $return); ?>
    <?php if ($schedules) { recurring_panel($cfg, $schedules, $return); } ?>
  </div>
</div>
    <?php
    app_close();
}

function transaction_form(array $cfg, ?array $editing, array $primary_names, array $secondary_names, string $return): void
{
    $use_old = has_old();
    $value = static fn(string $field, string $fallback): string => $use_old ? old($field) : $fallback;

    $amount = $value('amount', $editing ? number_format((float) $editing['amount'], 2, '.', '') : '');
    $date = $value('date', $editing['date'] ?? today()->format('Y-m-d'));
    $primary = $value('primary', $editing['primary_name'] ?? '');
    $secondary = $value('secondary', $editing['secondary_name'] ?? '');
    $description = $value('description', $editing['description'] ?? '');
    $repeat = $value('repeat', $editing && (int) $editing['is_recurring'] === 1 ? (string) $editing['recurrence_period'] : '');
    $id = $editing['id'] ?? ($use_old ? (int) old('id') : 0);
    $symbol = currency_symbol();
    $cancel = rtrim(preg_replace('/([?&])edit=\d+(&|$)/', '$1', $return), '?&');
    ?>
<section class="panel tx-form" id="add" aria-labelledby="tx-form-title">
  <div class="panel-head">
    <div>
      <h2 class="panel-title" id="tx-form-title"><?= $id ? 'Edit ' . e($cfg['noun']) : e($cfg['add_label']) ?></h2>
      <p class="panel-sub"><?= $id ? 'Update the details and save.' : 'It lands in the month you pick.' ?></p>
    </div>
    <?php if ($id): ?><a class="btn btn-ghost btn-sm" href="<?= e($cancel) ?>">Cancel</a><?php endif; ?>
  </div>
  <form class="panel-pad stack" method="post" action="<?= e(url($cfg['route'])) ?>" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int) $id ?>">
    <input type="hidden" name="return" value="<?= e($return) ?>">

    <div class="field">
      <label class="label" for="amount">Amount</label>
      <div class="input-affix" style="--affix-w: <?= mb_strlen($symbol) ?>ch">
        <span class="affix lg"><?= e($symbol) ?></span>
        <input class="input input-amount num" id="amount" name="amount" type="text" inputmode="decimal" autocomplete="off" placeholder="0.00" required value="<?= e($amount) ?>"<?= invalid_attr('amount') ?>>
      </div>
      <?= field_error('amount') ?>
    </div>

    <div class="form-grid">
      <div class="field">
        <label class="label" for="date">Date</label>
        <input class="input" id="date" name="date" type="date" required value="<?= e($date) ?>"<?= invalid_attr('date') ?>>
        <?= field_error('date') ?>
      </div>
      <div class="field">
        <label class="label" for="primary"><?= e($cfg['primary']['label']) ?></label>
        <input class="input" id="primary" name="primary" type="text" list="primary-options" autocomplete="off" maxlength="60" required placeholder="<?= e($cfg['primary']['placeholder']) ?>" value="<?= e($primary) ?>"<?= invalid_attr('primary') ?>>
        <datalist id="primary-options"><?php foreach ($primary_names as $name): ?><option value="<?= e($name) ?>"><?php endforeach; ?></datalist>
        <?= field_error('primary') ?>
      </div>
    </div>

    <div class="field">
      <label class="label" for="secondary"><?= e($cfg['secondary']['label']) ?> <span class="optional">(optional)</span></label>
      <input class="input" id="secondary" name="secondary" type="text" list="secondary-options" autocomplete="off" maxlength="60" placeholder="<?= e($cfg['secondary']['placeholder']) ?>" value="<?= e($secondary) ?>">
      <datalist id="secondary-options"><?php foreach ($secondary_names as $name): ?><option value="<?= e($name) ?>"><?php endforeach; ?></datalist>
    </div>

    <div class="field">
      <label class="label" for="description">Note <span class="optional">(optional)</span></label>
      <input class="input" id="description" name="description" type="text" maxlength="255" placeholder="<?= e($cfg['description_placeholder']) ?>" value="<?= e($description) ?>">
    </div>

    <div class="field">
      <label class="label" for="repeat">Repeat</label>
      <select class="select" id="repeat" name="repeat"<?= invalid_attr('repeat') ?>>
        <option value="">Doesn't repeat</option>
        <?php foreach (recurrence_periods() as $key => $label): ?>
        <option value="<?= e($key) ?>"<?= $repeat === $key ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <?= field_error('repeat') ?: '<p class="hint">Future ' . ($cfg['kind'] === 'income' ? 'income is' : 'expenses are') . ' added automatically on each date.</p>' ?>
    </div>

    <button class="btn btn-primary btn-block btn-lg" type="submit"><?= $id ? 'Save changes' : e($cfg['add_label']) ?></button>
    <p class="hint">New <?= e(strtolower($cfg['primary']['label'])) ?> names are created as you type them.</p>
  </form>
</section>
    <?php
}

function recurring_panel(array $cfg, array $schedules, string $return): void
{
    ?>
<section class="panel" aria-labelledby="schedules-title">
  <div class="panel-head">
    <div>
      <h2 class="panel-title" id="schedules-title">Recurring</h2>
      <p class="panel-sub"><?= plural(count($schedules), 'schedule') ?>, soonest first</p>
    </div>
  </div>
  <?php foreach ($schedules as $item):
      $title = $item['description'] ?: ($item['label'] ?: ucfirst($cfg['noun'])); ?>
  <div class="schedule-row">
    <div class="ledger-main">
      <p class="ledger-title truncate"><?= e($title) ?></p>
      <p class="ledger-meta truncate"><?= e(recurrence_label($item['recurrence_period'])) ?> · Next: <?= e(day_label($item['next_recurrence_date'])) ?></p>
    </div>
    <span class="ledger-amount num"><?= money($item['amount']) ?></span>
    <form method="post" action="<?= e(url($cfg['route'])) ?>" data-confirm="“<?= e($title) ?>” will stop repeating. Entries already added stay in your ledger." data-confirm-title="Stop this schedule?" data-confirm-label="Stop repeating">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="stop_recurring">
      <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
      <input type="hidden" name="return" value="<?= e($return) ?>">
      <button class="btn btn-ghost btn-sm" type="submit">Stop</button>
    </form>
  </div>
  <?php endforeach; ?>
</section>
    <?php
}

ledger_page($route_args['kind']);
