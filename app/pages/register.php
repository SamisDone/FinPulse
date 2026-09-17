<?php
defined('FINPULSE') || exit;

redirect_if_logged_in();

if (is_post()) {
    verify_csrf();
    [$user_id, $errors] = register_user(
        input('username'),
        input('email'),
        (string) ($_POST['password'] ?? ''),
        (string) ($_POST['password_confirmation'] ?? '')
    );

    if ($errors) {
        keep_form($errors, $_POST);
        redirect('register');
    }

    log_in(['id' => $user_id]);
    flash('success', 'Your account is ready. Start by adding this month\'s income.');
    redirect('dashboard');
}

auth_open('Create account');
?>
<h1>Start your ledger</h1>
<p class="lede">It takes a minute. No bank details, ever.</p>

<form class="stack auth-fields" method="post" action="<?= e(url('register')) ?>" novalidate>
  <?= csrf_field() ?>
  <div class="field">
    <label class="label" for="username">Username</label>
    <input class="input" id="username" name="username" type="text" autocomplete="username" autocapitalize="none" spellcheck="false" required minlength="3" maxlength="32" pattern="[A-Za-z0-9._\-]{3,32}" autofocus value="<?= e(old('username')) ?>"<?= invalid_attr('username') ?>>
    <?= field_error('username') ?: '<p class="hint">Letters, numbers, dots, dashes or underscores.</p>' ?>
  </div>
  <div class="field">
    <label class="label" for="email">Email</label>
    <input class="input" id="email" name="email" type="email" autocomplete="email" autocapitalize="none" spellcheck="false" required value="<?= e(old('email')) ?>"<?= invalid_attr('email') ?>>
    <?= field_error('email') ?>
  </div>
  <div class="field">
    <label class="label" for="password">Password</label>
    <div class="input-affix has-btn">
      <input class="input" id="password" name="password" type="password" autocomplete="new-password" required minlength="8"<?= invalid_attr('password') ?>>
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
    <label class="label" for="password_confirmation">Confirm password</label>
    <input class="input" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required<?= invalid_attr('password_confirmation') ?>>
    <?= field_error('password_confirmation') ?>
  </div>
  <button class="btn btn-primary btn-lg btn-block" type="submit">Create account</button>
</form>

<p class="auth-switch">Already have an account? <a class="link" href="<?= e(url('login')) ?>">Sign in</a></p>
<?php auth_close(); ?>
