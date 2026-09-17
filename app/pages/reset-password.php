<?php
defined('SIXPENCE') || exit;

$token = is_post() ? input('token') : query('token');
$reset = find_password_reset($token);

if (is_post()) {
    verify_csrf();
    if (!$reset) {
        redirect('reset-password', ['token' => $token]);
    }
    $password = (string) ($_POST['password'] ?? '');
    $errors = [];
    if ($problem = password_problems($password)) {
        $errors['password'] = $problem;
    } elseif (!hash_equals($password, (string) ($_POST['password_confirmation'] ?? ''))) {
        $errors['password_confirmation'] = 'The passwords don\'t match.';
    }
    if ($errors) {
        keep_form($errors, []);
        redirect('reset-password', ['token' => $token]);
    }

    complete_password_reset($reset, $password);
    if (current_user()) {
        log_out();
        start_secure_session();
    }
    flash('success', 'Your password was changed. Sign in with the new one.');
    redirect('login');
}

auth_open('Choose a new password');
?>
<?php if (!$reset): ?>
<h1>This link has expired</h1>
<p class="lede">Reset links work once and expire after 60 minutes. Request a new one and use the latest email.</p>
<div class="cluster auth-fields">
  <a class="btn btn-primary btn-lg" href="<?= e(url('forgot-password')) ?>">Send a new link</a>
  <a class="link" href="<?= e(url('login')) ?>">Back to sign in</a>
</div>
<?php else: ?>
<h1>Choose a new password</h1>
<p class="lede">For <strong><?= e($reset['username']) ?></strong>. You'll be signed out everywhere else.</p>

<form class="stack auth-fields" method="post" action="<?= e(url('reset-password')) ?>" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="token" value="<?= e($token) ?>">
  <input class="sr-only" type="text" name="username" autocomplete="username" value="<?= e($reset['username']) ?>" tabindex="-1" aria-hidden="true">
  <div class="field">
    <label class="label" for="password">New password</label>
    <div class="input-affix has-btn">
      <input class="input" id="password" name="password" type="password" autocomplete="new-password" required minlength="8" autofocus<?= invalid_attr('password') ?>>
      <button class="icon-btn affix-btn" type="button" data-password-toggle aria-label="Show password" aria-pressed="false"><?= icon('eye') ?></button>
    </div>
    <?= field_error('password') ?>
    <ul class="pw-rules" data-password-rules="#password" aria-label="Password requirements">
      <li data-rule="length">8 or more characters</li>
      <li data-rule="upper">An uppercase letter</li>
      <li data-rule="number">A number</li>
      <li data-rule="symbol">A symbol</li>
    </ul>
  </div>
  <div class="field">
    <label class="label" for="password_confirmation">Confirm new password</label>
    <input class="input" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required<?= invalid_attr('password_confirmation') ?>>
    <?= field_error('password_confirmation') ?>
  </div>
  <button class="btn btn-primary btn-lg btn-block" type="submit">Save new password</button>
</form>
<?php endif; ?>
<?php auth_close(); ?>
