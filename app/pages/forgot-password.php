<?php
defined('FINPULSE') || exit;

redirect_if_logged_in();

if (is_post()) {
    verify_csrf();
    $email = input('email');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        keep_form(['email' => 'Enter the email address you signed up with.'], ['email' => $email]);
        redirect('forgot-password');
    }
    request_password_reset($email);
    $_SESSION['_reset_sent_to'] = $email;
    redirect('forgot-password', ['sent' => 1]);
}

$sent_to = query('sent') ? ($_SESSION['_reset_sent_to'] ?? null) : null;

auth_open('Reset password');
?>
<?php if ($sent_to): ?>
<h1>Check your inbox</h1>
<p class="lede">If an account uses <strong><?= e($sent_to) ?></strong>, we've sent it a link to choose a new password. The link expires in 60 minutes.</p>

<div class="alert alert-note auth-alert"><?= icon('mail') ?><span>Nothing after a few minutes? Check your spam folder, or make sure you used the email on your account.</span></div>
<?php if (mail_driver() === 'log' && env_bool('APP_DEBUG', false)): ?>
<div class="alert alert-note auth-alert"><?= icon('info') ?><span>Development mode: email is set to <code>MAIL_DRIVER=log</code>, so the message was saved in <code><?= e(str_replace(APP_ROOT . '/', '', mail_log_dir())) ?></code> instead of being sent.</span></div>
<?php endif; ?>

<p class="auth-switch"><a class="link" href="<?= e(url('login')) ?>">Back to sign in</a> · <a class="link" href="<?= e(url('forgot-password')) ?>">Try another email</a></p>
<?php else: ?>
<h1>Forgot your password?</h1>
<p class="lede">Enter the email on your account and we'll send you a link to choose a new one.</p>

<?php if (!mail_enabled()): ?>
<div class="alert alert-error auth-alert" role="alert"><?= icon('alert') ?><span>Email is turned off on this server, so reset links can't be sent. Ask whoever runs this FinPulse to reset it for you.</span></div>
<?php endif; ?>

<form class="stack auth-fields" method="post" action="<?= e(url('forgot-password')) ?>" novalidate>
  <?= csrf_field() ?>
  <div class="field">
    <label class="label" for="email">Email</label>
    <input class="input" id="email" name="email" type="email" autocomplete="email" autocapitalize="none" spellcheck="false" required autofocus value="<?= e(old('email')) ?>"<?= invalid_attr('email') ?>>
    <?= field_error('email') ?>
  </div>
  <button class="btn btn-primary btn-lg btn-block" type="submit"<?= mail_enabled() ? '' : ' disabled' ?>>Send reset link</button>
</form>

<p class="auth-switch">Remembered it? <a class="link" href="<?= e(url('login')) ?>">Sign in</a></p>
<?php endif; ?>
<?php auth_close(); ?>
