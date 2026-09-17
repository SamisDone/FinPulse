<?php
defined('SIXPENCE') || exit;

redirect_if_logged_in();

if (is_post()) {
    verify_csrf();
    $identifier = input('login');
    $password = (string) ($_POST['password'] ?? '');

    $errors = [];
    if ($identifier === '') {
        $errors['login'] = 'Enter your username or email.';
    }
    if ($password === '') {
        $errors['password'] = 'Enter your password.';
    }
    if ($errors) {
        keep_form($errors, ['login' => $identifier]);
        redirect('login');
    }

    $result = attempt_login($identifier, $password);
    if ($result['status'] === 'ok') {
        log_in($result['user']);
        $intended = $_SESSION['_intended'] ?? url('dashboard');
        unset($_SESSION['_intended']);
        redirect_to_url($intended);
    }

    $message = $result['status'] === 'locked'
        ? 'Too many attempts. Try again in ' . plural($result['retry_minutes'], 'minute') . '.'
        : 'That username or password isn\'t right.';
    keep_form(['form' => $message], ['login' => $identifier]);
    redirect('login');
}

auth_open('Sign in');
?>
<h1>Welcome back</h1>
<p class="lede">Sign in to pick up where you left off.</p>

<?php if ($form_error = error_for('form')): ?>
<div class="alert alert-error auth-alert" role="alert"><?= icon('alert') ?><span><?= e($form_error) ?></span></div>
<?php endif; ?>

<form class="stack auth-fields" method="post" action="<?= e(url('login')) ?>" novalidate>
  <?= csrf_field() ?>
  <div class="field">
    <label class="label" for="login">Username or email</label>
    <input class="input" id="login" name="login" type="text" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus value="<?= e(old('login')) ?>"<?= invalid_attr('login') ?>>
    <?= field_error('login') ?>
  </div>
  <div class="field">
    <div class="spread"><label class="label" for="password">Password</label><a class="link small" href="<?= e(url('forgot-password')) ?>">Forgot password?</a></div>
    <div class="input-affix has-btn">
      <input class="input" id="password" name="password" type="password" autocomplete="current-password" required<?= invalid_attr('password') ?>>
      <button class="icon-btn affix-btn" type="button" data-password-toggle aria-label="Show password" aria-pressed="false"><?= icon('eye') ?></button>
    </div>
    <?= field_error('password') ?>
  </div>
  <button class="btn btn-primary btn-lg btn-block" type="submit">Sign in</button>
</form>

<p class="auth-switch">New to Sixpence? <a class="link" href="<?= e(url('register')) ?>">Create an account</a></p>
<?php auth_close(); ?>
