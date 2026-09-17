<?php
defined('FINPULSE') || exit;

$user = require_login();
$uid = $user['id'];

if (is_post()) {
    verify_csrf();

    switch (input('action')) {
        case 'profile':
            $username = input('username');
            $email = strtolower(input('email'));
            $errors = [];
            if ($problem = validate_username($username)) {
                $errors['username'] = $problem;
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Enter a valid email address.';
            }
            if (!$errors) {
                $taken = db()->prepare('SELECT username, email FROM users WHERE (LOWER(username) = LOWER(?) OR LOWER(email) = LOWER(?)) AND id <> ?');
                $taken->execute([$username, $email, $uid]);
                foreach ($taken->fetchAll() as $row) {
                    if (strcasecmp($row['username'], $username) === 0) {
                        $errors['username'] = 'That username is taken.';
                    }
                    if (strcasecmp($row['email'], $email) === 0) {
                        $errors['email'] = 'Another account uses this email.';
                    }
                }
            }
            if ($errors) {
                keep_form($errors, $_POST + ['form' => 'profile']);
                redirect('settings', [], 'profile');
            }
            db()->prepare('UPDATE users SET username = ?, email = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$username, $email, $uid]);
            flash('success', 'Profile saved.');
            redirect('settings');

        case 'preferences':
            $currency = input('currency');
            if (!isset(currencies()[$currency])) {
                flash('error', 'Choose a currency from the list.');
                redirect('settings', [], 'preferences');
            }
            db()->prepare('UPDATE users SET currency = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$currency, $uid]);
            flash('success', 'Currency set to ' . currencies()[$currency][1] . '.');
            redirect('settings');

        case 'notifications':
            $prefs = [];
            foreach (array_keys(notification_types()) as $key) {
                $prefs[$key] = isset($_POST[$key]);
            }
            $prefs['email'] = isset($_POST['email_notifications']);
            save_notification_prefs($uid, $prefs);
            flash('success', 'Notification settings saved.');
            redirect('settings', [], 'notifications');

        case 'test_email':
            try {
                $file = send_mail($user['email'], $user['username'], 'FinPulse test email', email_template(
                    'Email is working',
                    ['This is a test from your FinPulse at ' . app_url() . '. Budget alerts, reminders, summaries and password resets will arrive the same way.']
                ));
                flash('success', $file
                    ? 'Email is in log mode, so the test was saved to ' . str_replace(APP_ROOT . DIRECTORY_SEPARATOR, '', str_replace('/', DIRECTORY_SEPARATOR, $file)) . ' instead of being sent.'
                    : 'Test email sent to ' . $user['email'] . '.');
            } catch (Throwable $e) {
                flash('error', 'The test email failed: ' . $e->getMessage());
            }
            redirect('settings', [], 'notifications');

        case 'password':
            $current = (string) ($_POST['current_password'] ?? '');
            $new = (string) ($_POST['password'] ?? '');
            $confirm = (string) ($_POST['password_confirmation'] ?? '');
            $errors = [];
            if (!password_matches($uid, $current)) {
                $errors['current_password'] = 'That isn\'t your current password.';
            }
            if ($problem = password_problems($new)) {
                $errors['password'] = $problem;
            } elseif (!hash_equals($new, $confirm)) {
                $errors['password_confirmation'] = 'The passwords don\'t match.';
            }
            if ($errors) {
                keep_form($errors, ['form' => 'password']);
                redirect('settings', [], 'password');
            }
            set_password($uid, $new);
            bump_session_epoch($uid, keep_current: true);
            notify_password_changed($uid, $user['email'], $user['username']);
            flash('success', 'Password changed. Every other device was signed out.');
            redirect('settings');

        case 'delete_account':
            if (!password_matches($uid, (string) ($_POST['confirm_password'] ?? ''))) {
                keep_form(['confirm_password' => 'Enter your password to confirm.'], ['form' => 'delete']);
                redirect('settings', [], 'danger');
            }
            db()->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
            log_out();
            start_secure_session();
            flash('info', 'Your account and all of its data were deleted.');
            redirect('login');
    }
    redirect('settings');
}

$range = db()->prepare('SELECT MIN(d) FROM (SELECT MIN(income_date) AS d FROM income WHERE user_id = ? UNION ALL SELECT MIN(expense_date) FROM expenses WHERE user_id = ?) x');
$range->execute([$uid, $uid]);
$first_entry = $range->fetchColumn() ?: null;

$counts = db()->prepare('SELECT (SELECT COUNT(*) FROM income WHERE user_id = ?) + (SELECT COUNT(*) FROM expenses WHERE user_id = ?)');
$counts->execute([$uid, $uid]);
$entry_count = (int) $counts->fetchColumn();

$prefs = notification_prefs($user);
$driver = mail_driver();
$old_profile = has_old() && old('form') === 'profile';

app_open('Settings');
page_header('Settings', 'Your account, preferences, notifications and data.');
?>
<div class="settings">
  <section class="settings-section" id="profile" aria-labelledby="profile-title">
    <div>
      <h2 id="profile-title">Profile</h2>
      <p class="desc">You can sign in with either your username or email. Password reset links and notifications go to this email.</p>
    </div>
    <form class="stack" method="post" action="<?= e(url('settings')) ?>" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="profile">
      <div class="form-grid">
        <div class="field">
          <label class="label" for="username">Username</label>
          <input class="input" id="username" name="username" autocomplete="username" required maxlength="32" value="<?= e($old_profile ? old('username') : $user['username']) ?>"<?= invalid_attr('username') ?>>
          <?= field_error('username') ?>
        </div>
        <div class="field">
          <label class="label" for="email">Email</label>
          <input class="input" id="email" name="email" type="email" autocomplete="email" required value="<?= e($old_profile ? old('email') : $user['email']) ?>"<?= invalid_attr('email') ?>>
          <?= field_error('email') ?>
        </div>
      </div>
      <div><button class="btn btn-primary" type="submit">Save profile</button></div>
    </form>
  </section>

  <section class="settings-section" id="preferences" aria-labelledby="prefs-title">
    <div>
      <h2 id="prefs-title">Preferences</h2>
      <p class="desc">Currency changes how amounts are displayed. Nothing is converted.</p>
    </div>
    <div class="stack stack-lg">
      <form class="stack" method="post" action="<?= e(url('settings')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="preferences">
        <div class="field">
          <label class="label" for="currency">Currency</label>
          <select class="select" id="currency" name="currency">
            <?php foreach (currencies() as $code => [$symbol, $name]): ?>
            <option value="<?= e($code) ?>"<?= $code === currency_code() ? ' selected' : '' ?>><?= e($name) ?> (<?= e($symbol) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div><button class="btn btn-primary" type="submit">Save preference</button></div>
      </form>
      <div class="field">
        <span class="label" id="theme-label">Appearance</span>
        <div class="seg" role="group" aria-labelledby="theme-label">
          <button type="button" data-theme-set="system"><?= icon('monitor', 'icon icon-sm') ?>System</button>
          <button type="button" data-theme-set="light"><?= icon('sun', 'icon icon-sm') ?>Light</button>
          <button type="button" data-theme-set="dark"><?= icon('moon', 'icon icon-sm') ?>Dark</button>
        </div>
        <p class="hint">Saved on this device.</p>
      </div>
    </div>
  </section>

  <section class="settings-section" id="notifications" aria-labelledby="notifications-title">
    <div>
      <h2 id="notifications-title">Notifications</h2>
      <p class="desc">Choose what FinPulse tells you about. Notifications always appear under <a class="link" href="<?= e(url('notifications')) ?>">Notifications</a>; email is optional.</p>
    </div>
    <div class="stack stack-lg">
      <form class="stack" method="post" action="<?= e(url('settings')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="notifications">
        <fieldset class="fieldset">
          <legend class="label">Tell me about</legend>
          <?php foreach (notification_types() as $key => [$label, $description]): ?>
          <label class="checkbox">
            <input type="checkbox" name="<?= e($key) ?>" value="1"<?= $prefs[$key] ? ' checked' : '' ?>>
            <span><strong><?= e($label) ?></strong><span class="hint"><?= e($description) ?></span></span>
          </label>
          <?php endforeach; ?>
        </fieldset>
        <fieldset class="fieldset">
          <legend class="label">Delivery</legend>
          <label class="checkbox">
            <input type="checkbox" name="email_notifications" value="1"<?= $prefs['email'] ? ' checked' : '' ?><?= $driver === 'none' ? ' disabled' : '' ?>>
            <span><strong>Also email me</strong><span class="hint">Sent to <?= e($user['email']) ?>. Security notices are always emailed.</span></span>
          </label>
        </fieldset>
        <div><button class="btn btn-primary" type="submit">Save notifications</button></div>
      </form>

      <div class="mail-status">
        <?php if ($driver === 'none'): ?>
        <div class="alert alert-note"><?= icon('info') ?><span>Email is turned off on this server (<code>MAIL_DRIVER=none</code>). You'll still see notifications in the app.</span></div>
        <?php elseif ($driver === 'log'): ?>
        <div class="alert alert-note"><?= icon('info') ?><span>Email is in development mode (<code>MAIL_DRIVER=log</code>): messages are saved to <code><?= e(str_replace(APP_ROOT . '/', '', mail_log_dir())) ?></code> instead of being sent. Set up SMTP in <code>.env</code> to deliver them.</span></div>
        <?php endif; ?>
        <?php if ($driver !== 'none'): ?>
        <form method="post" action="<?= e(url('settings')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="test_email">
          <button class="btn btn-sm" type="submit"><?= icon('mail', 'icon icon-sm') ?>Send a test email</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <section class="settings-section" id="password" aria-labelledby="password-title">
    <div>
      <h2 id="password-title">Password</h2>
      <p class="desc">Use at least 8 characters with an uppercase letter, a number and a symbol. Changing it signs out every other device.</p>
    </div>
    <form class="stack" method="post" action="<?= e(url('settings')) ?>" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="password">
      <input class="sr-only" type="text" name="username" autocomplete="username" value="<?= e($user['username']) ?>" tabindex="-1" aria-hidden="true">
      <div class="field">
        <label class="label" for="current_password">Current password</label>
        <input class="input" id="current_password" name="current_password" type="password" autocomplete="current-password" required<?= invalid_attr('current_password') ?>>
        <?= field_error('current_password') ?>
      </div>
      <div class="form-grid">
        <div class="field">
          <label class="label" for="new_password">New password</label>
          <input class="input" id="new_password" name="password" type="password" autocomplete="new-password" required<?= invalid_attr('password') ?>>
          <?= field_error('password') ?>
        </div>
        <div class="field">
          <label class="label" for="password_confirmation">Confirm new password</label>
          <input class="input" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required<?= invalid_attr('password_confirmation') ?>>
          <?= field_error('password_confirmation') ?>
        </div>
      </div>
      <div><button class="btn btn-primary" type="submit">Change password</button></div>
    </form>
  </section>

  <section class="settings-section" id="data" aria-labelledby="data-title">
    <div>
      <h2 id="data-title">Your data</h2>
      <p class="desc">Download every income and expense entry as a spreadsheet.</p>
    </div>
    <div class="stack">
      <p class="muted"><?= plural($entry_count, 'entry', 'entries') ?><?= $first_entry ? ' since ' . e(fmt_date($first_entry, 'F j, Y')) : '' ?>.</p>
      <div>
        <?php if ($first_entry): ?>
        <a class="btn" href="<?= e(url('reports', ['range' => 'custom', 'start' => $first_entry, 'end' => today()->format('Y-m-d'), 'export' => 'csv'])) ?>"><?= icon('download') ?>Export all entries (CSV)</a>
        <?php else: ?>
        <span class="btn" aria-disabled="true"><?= icon('download') ?>Nothing to export yet</span>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <section class="settings-section" id="danger" aria-labelledby="danger-title">
    <div>
      <h2 id="danger-title">Delete account</h2>
      <p class="desc">Permanently removes your account and every entry, budget, account, goal and notification. This can't be undone.</p>
    </div>
    <form class="stack" method="post" action="<?= e(url('settings')) ?>" data-confirm="Your account and all <?= plural($entry_count, 'entry', 'entries') ?> will be permanently deleted." data-confirm-title="Delete your account?" data-confirm-label="Delete everything" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete_account">
      <div class="field">
        <label class="label" for="confirm_password">Confirm with your password</label>
        <input class="input" id="confirm_password" name="confirm_password" type="password" autocomplete="current-password" required<?= invalid_attr('confirm_password') ?>>
        <?= field_error('confirm_password') ?>
      </div>
      <div><button class="btn btn-danger" type="submit"><?= icon('trash') ?>Delete my account</button></div>
    </form>
  </section>
</div>
<?php app_close(); ?>
