<?php

test('public pages load with security headers and no third-party requests', function () {
    $http = new HttpClient();
    foreach (['/', '/login', '/register', '/forgot-password'] as $path) {
        $http->get($path);
        expect_same(200, $http->status, $path);
        expect_contains("default-src 'self'", $http->headers['content-security-policy'] ?? '', "$path CSP");
        expect_same('DENY', $http->headers['x-frame-options'] ?? '', "$path frame options");
        expect_false((bool) preg_match('~(src|href)="https?://~', $http->body), "$path loads nothing from other origins");
    }
});

test('assets, legacy URLs and unknown pages', function () {
    $http = new HttpClient();
    expect_same(200, $http->get('/assets/css/app.css')->status);
    expect_same(200, $http->get('/assets/fonts/geist-latin-wght-normal.woff2')->status);
    expect_same(200, $http->get('/assets/vendor/chart-4.4.1.umd.min.js')->status);

    $http->get('/expenses.php?month=2026-09');
    expect_same(301, $http->status);
    expect_same('/expenses?month=2026-09', $http->headers['location']);

    expect_same(404, $http->get('/does-not-exist')->status);
    expect_contains('Page not found', $http->body);
});

test('signed-out visitors are sent to sign in, then back to where they were going', function () {
    $user = make_user('intended');
    $http = new HttpClient();
    $http->get('/budgets?new=1');
    expect_same(303, $http->status);
    expect_same('/login', $http->redirectPath());

    $http->get('/login')->post('/login', ['login' => $user['username'], 'password' => $user['password']]);
    expect_same('/budgets', $http->redirectPath());
    expect_contains('new=1', $http->headers['location']);
});

test('registration shows field errors, then creates the account and signs in', function () {
    $http = new HttpClient();
    $http->get('/register')->post('/register', ['username' => 'x', 'email' => 'nope', 'password' => 'a', 'password_confirmation' => 'a'])->follow();
    expect_contains('Use 3–32 letters', $http->body);
    expect_contains('value="x"', $http->body, 'input is kept');

    $name = 'newbie' . bin2hex(random_bytes(3));
    $http->post('/register', ['username' => $name, 'email' => "$name@example.test", 'password' => 'Str0ng#pass', 'password_confirmation' => 'Str0ng#pass'])->follow();
    expect_contains('Set up your ledger', $http->body);
    expect_contains($name, $http->body);
});

test('wrong passwords show one generic message and lock after five tries', function () {
    $user = make_user('lockout');
    $http = new HttpClient();
    $http->get('/login');
    for ($i = 0; $i < 5; $i++) {
        $http->post('/login', ['login' => $user['username'], 'password' => 'nope'])->follow();
    }
    expect_contains('Too many attempts', $http->body);
    $http->post('/login', ['login' => $user['username'], 'password' => $user['password']])->follow();
    expect_contains('Too many attempts', $http->body, 'still locked with the right password');
});

test('sign out requires POST with a token', function () {
    $user = make_user('logout');
    $http = (new HttpClient())->login($user['username'], $user['password']);
    $http->get('/logout');
    expect_same('/dashboard', $http->redirectPath(), 'GET does not sign out');
    $http->get('/dashboard')->post('/logout', [], false);
    $http->get('/dashboard');
    expect_same(200, $http->status, 'POST without a token does not sign out');
    $http->post('/logout')->follow();
    $http->get('/dashboard');
    expect_same('/login', $http->redirectPath());
});

test('forgot password emails a link that resets the password and signs out other sessions', function () {
    $user = make_user('forgot');
    $other_device = (new HttpClient())->login($user['username'], $user['password']);
    expect_same(200, $other_device->get('/dashboard')->status);

    $http = new HttpClient();
    $http->get('/forgot-password')->post('/forgot-password', ['email' => $user['email']])->follow();
    expect_contains('Check your inbox', $http->body);

    $email = latest_email_to($user['email']);
    expect_true($email !== null, 'reset email written to the mail log');
    expect_contains('Subject: Reset your FinPulse password', $email);
    preg_match('~(/reset-password\?token=[a-f0-9]{64})~', email_text($email), $m);
    expect_true(isset($m[1]), 'email contains the reset link');

    $http->get($m[1]);
    expect_contains('Choose a new password', $http->body);
    $http->post('/reset-password', ['token' => substr($m[1], -64), 'password' => 'short', 'password_confirmation' => 'short'])->follow();
    expect_contains('Use at least 8 characters', $http->body);

    $http->post('/reset-password', ['token' => substr($m[1], -64), 'password' => 'Brand#new1', 'password_confirmation' => 'Brand#new1'])->follow();
    expect_contains('Your password was changed', $http->body);

    $http->get($m[1]);
    expect_contains('This link has expired', $http->body, 'link is single-use');

    $other_device->get('/dashboard');
    expect_same('/login', $other_device->redirectPath(), 'other session was signed out');

    (new HttpClient())->login($user['username'], 'Brand#new1');
    expect_true(latest_email_to($user['email'], 5, 'Subject: Your FinPulse password was changed') !== null, 'security notice was emailed');
});

test('forgot password gives the same answer for unknown emails', function () {
    $http = new HttpClient();
    $http->get('/forgot-password')->post('/forgot-password', ['email' => 'ghost-' . bin2hex(random_bytes(3)) . '@example.test'])->follow();
    expect_contains('Check your inbox', $http->body);
});

test('changing the password in settings needs the current one and keeps this session', function () {
    $user = make_user('settingspw');
    $http = (new HttpClient())->login($user['username'], $user['password']);
    $other = (new HttpClient())->login($user['username'], $user['password']);

    $http->get('/settings')->post('/settings', ['action' => 'password', 'current_password' => 'wrong', 'password' => 'Next#pass1', 'password_confirmation' => 'Next#pass1'])->follow();
    expect_contains('That isn', $http->body);

    $http->post('/settings', ['action' => 'password', 'current_password' => $user['password'], 'password' => 'Next#pass1', 'password_confirmation' => 'Next#pass1'])->follow();
    expect_contains('Password changed', $http->body);
    expect_same(200, $http->get('/dashboard')->status, 'this session stays signed in');
    $other->get('/dashboard');
    expect_same('/login', $other->redirectPath(), 'the other session is signed out');
});

test('deleting the account removes the user and their data', function () {
    $user = make_user('goodbye');
    add_expense($user, 12, '2026-09-01');
    $http = (new HttpClient())->login($user['username'], $user['password']);
    $http->get('/settings')->post('/settings', ['action' => 'delete_account', 'confirm_password' => 'wrong'])->follow();
    expect_same(1, count_rows('SELECT COUNT(*) FROM users WHERE id = ?', [$user['id']]));

    $http->post('/settings', ['action' => 'delete_account', 'confirm_password' => $user['password']])->follow();
    expect_contains('were deleted', $http->body);
    expect_same(0, count_rows('SELECT COUNT(*) FROM users WHERE id = ?', [$user['id']]));
    expect_same(0, count_rows('SELECT COUNT(*) FROM expenses WHERE user_id = ?', [$user['id']]));
});
