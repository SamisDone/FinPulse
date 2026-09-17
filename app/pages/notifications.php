<?php
defined('SIXPENCE') || exit;

$user = require_login();
$uid = $user['id'];

if (is_post()) {
    verify_csrf();
    $now = date('Y-m-d H:i:s');
    switch (input('action')) {
        case 'read_all':
            db()->prepare('UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL')->execute([$now, $uid]);
            flash('success', 'All caught up.');
            break;
        case 'clear_read':
            db()->prepare('DELETE FROM notifications WHERE user_id = ? AND read_at IS NOT NULL')->execute([$uid]);
            flash('success', 'Cleared read notifications.');
            break;
    }
    redirect('notifications');
}

// Opening a notification marks it read, then follows its link inside the app.
if ($open = (int) query('open')) {
    $stmt = db()->prepare('SELECT link FROM notifications WHERE id = ? AND user_id = ?');
    $stmt->execute([$open, $uid]);
    $link = $stmt->fetchColumn();
    if ($link !== false) {
        db()->prepare('UPDATE notifications SET read_at = COALESCE(read_at, ?) WHERE id = ?')->execute([date('Y-m-d H:i:s'), $open]);
        [$route, $query_string] = array_pad(explode('?', (string) $link, 2), 2, '');
        parse_str($query_string, $params);
        redirect(array_key_exists($route, routes()) ? $route : 'dashboard', $params);
    }
    redirect('notifications');
}

$stmt = db()->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 100');
$stmt->execute([$uid]);
$items = $stmt->fetchAll();
$unread = array_values(array_filter($items, static fn($n) => $n['read_at'] === null));
$read = array_values(array_filter($items, static fn($n) => $n['read_at'] !== null));
$prefs = notification_prefs($user);
$enabled = array_keys(array_filter(array_intersect_key($prefs, notification_types())));

function render_notification(array $item): void
{
    $is_unread = $item['read_at'] === null;
    ?>
<a class="notice<?= $is_unread ? ' is-unread' : '' ?>" href="<?= e(url('notifications', ['open' => $item['id']])) ?>">
  <span class="notice-icon type-<?= e($item['type']) ?>" aria-hidden="true"><?= icon(notification_icon($item['type']), 'icon icon-sm') ?></span>
  <span class="notice-main">
    <span class="notice-title"><?= e($item['title']) ?><?php if ($is_unread): ?><span class="sr-only"> (unread)</span><?php endif; ?></span>
    <?php if ($item['body']): ?><span class="notice-body"><?= e($item['body']) ?></span><?php endif; ?>
  </span>
  <time class="notice-time" datetime="<?= e(str_replace(' ', 'T', $item['created_at'])) ?>"><?= e(time_ago($item['created_at'])) ?></time>
</a>
    <?php
}

$actions = '';
if ($unread) {
    $actions .= '<form method="post" action="' . e(url('notifications')) . '">' . csrf_field() . '<input type="hidden" name="action" value="read_all"><button class="btn" type="submit">' . icon('check') . 'Mark all as read</button></form>';
}
$actions .= '<a class="btn btn-ghost" href="' . e(url('settings', [], 'notifications')) . '">' . icon('settings') . 'Settings</a>';

app_open('Notifications');
page_header('Notifications', $unread ? plural(count($unread), 'unread notification') : 'You\'re all caught up.', $actions);
?>

<?php if (!$enabled): ?>
<div class="alert alert-note form-panel"><?= icon('info') ?><span>All notification types are switched off. <a class="link" href="<?= e(url('settings', [], 'notifications')) ?>">Turn some on</a> to hear about budgets, upcoming payments and monthly summaries.</span></div>
<?php endif; ?>

<?php if (!$items): ?>
<section class="panel">
  <?= empty_state('bell', 'No notifications yet', 'You\'ll hear from Sixpence when a budget runs hot, a recurring payment is coming up, or a new month\'s summary is ready.') ?>
</section>
<?php else: ?>
  <?php if ($unread): ?>
  <section class="panel notice-list" aria-labelledby="unread-title">
    <div class="panel-head"><h2 class="panel-title" id="unread-title">New</h2></div>
    <?php foreach ($unread as $item) { render_notification($item); } ?>
  </section>
  <?php endif; ?>

  <?php if ($read): ?>
  <section class="panel notice-list<?= $unread ? ' section-gap' : '' ?>" aria-labelledby="read-title">
    <div class="panel-head">
      <h2 class="panel-title" id="read-title">Earlier</h2>
      <form method="post" action="<?= e(url('notifications')) ?>" data-confirm="Read notifications will be removed. Unread ones stay." data-confirm-title="Clear read notifications?" data-confirm-label="Clear">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="clear_read">
        <button class="btn btn-ghost btn-sm" type="submit">Clear</button>
      </form>
    </div>
    <?php foreach ($read as $item) { render_notification($item); } ?>
  </section>
  <?php endif; ?>
<?php endif; ?>

<?php app_close(); ?>
