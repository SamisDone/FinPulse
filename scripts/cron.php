<?php
/**
 * Scheduled upkeep. Run it every 15 minutes or so:
 *
 *   php scripts/cron.php
 *
 * It adds recurring entries that have come due, runs notification checks for every user
 * (budget alerts, upcoming payments, monthly summaries) and sends queued email.
 * Sixpence also does all of this while people use it; cron keeps things moving when nobody is signed in.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/core/bootstrap.php';

$lock = fopen(APP_ROOT . '/storage/cron.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Another cron run is still in progress.\n");
    exit(0);
}

$started = microtime(true);
$created = process_recurring();
$entries = array_sum(array_column($created, 'count'));

$users = db()->query('SELECT id, username, email, currency, notification_preferences, session_epoch, created_at FROM users')->fetchAll();
foreach ($users as $user) {
    $user['id'] = (int) $user['id'];
    try {
        run_notification_checks($user);
    } catch (Throwable $e) {
        fwrite(STDERR, "Notification checks failed for user {$user['id']}: {$e->getMessage()}\n");
    }
}
use_currency(null);

$mail = flush_mail_queue(200);
purge_expired_remember_tokens();

printf(
    "[%s] %s added, %s checked, %d email%s sent, %d failed (%.2fs)\n",
    date('Y-m-d H:i:s'),
    plural($entries, 'recurring entry', 'recurring entries'),
    plural(count($users), 'user'),
    $mail['sent'],
    $mail['sent'] === 1 ? '' : 's',
    $mail['failed'],
    microtime(true) - $started
);
foreach (array_unique($mail['errors']) as $error) {
    fwrite(STDERR, "  Email error: $error\n");
}

flock($lock, LOCK_UN);
exit($mail['failed'] > 0 ? 1 : 0);
