<?php

test('register_user validates and rejects duplicates case-insensitively', function () {
    [$id, $errors] = register_user('ab', 'not-an-email', 'weak', 'weak');
    expect_same(null, $id);
    expect_same(['username', 'email', 'password'], array_keys($errors));

    $user = make_user('dupe');
    [, $errors] = register_user(strtoupper($user['username']), strtoupper($user['email']), 'Str0ng#pass', 'Str0ng#pass');
    expect_same(['username', 'email'], array_keys($errors));

    [, $errors] = register_user('fresh_name', 'fresh@example.test', 'Str0ng#pass', 'Str0ng#pasS');
    expect_same(['password_confirmation'], array_keys($errors));
});

test('new users get default categories and a hashed password', function () {
    $user = make_user('defaults');
    expect_true(count_rows('SELECT COUNT(*) FROM expense_categories WHERE user_id = ?', [$user['id']]) >= 5);
    expect_true(count_rows('SELECT COUNT(*) FROM income_sources WHERE user_id = ?', [$user['id']]) >= 3);
    expect_true(password_verify('Str0ng#pass', $user['password_hash']));
});

test('attempt_login accepts username or email and locks after five failures', function () {
    $_SERVER['REMOTE_ADDR'] = '203.0.113.' . random_int(1, 250);
    $user = make_user('login');
    expect_same('ok', attempt_login($user['username'], 'Str0ng#pass')['status']);
    expect_same('ok', attempt_login(strtoupper($user['email']), 'Str0ng#pass')['status']);

    for ($i = 0; $i < 4; $i++) {
        expect_same('invalid', attempt_login($user['username'], 'wrong')['status']);
    }
    expect_same('locked', attempt_login($user['username'], 'wrong')['status']);
    expect_same('locked', attempt_login($user['username'], 'Str0ng#pass')['status'], 'even the right password is refused while locked');

    $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
    expect_same('ok', attempt_login($user['username'], 'Str0ng#pass')['status'], 'lockout is per address');
    unset($_SERVER['REMOTE_ADDR']);
});

test('unknown users are rejected without revealing that they do not exist', function () {
    expect_same(['status' => 'invalid'], attempt_login('nobody-' . bin2hex(random_bytes(4)), 'Whatever#1'));
});

test('rate_limit_hit allows up to the maximum within a window', function () {
    $bucket = 'test-' . bin2hex(random_bytes(4));
    expect_false(rate_limit_hit($bucket, 2, 3600));
    expect_false(rate_limit_hit($bucket, 2, 3600));
    expect_true(rate_limit_hit($bucket, 2, 3600));
    expect_false(rate_limit_hit($bucket, 2, 0), 'a new window starts fresh');
});

test('password reset tokens are single-use, expire, and are stored hashed', function () {
    $_SERVER['REMOTE_ADDR'] = '192.0.2.' . random_int(1, 250);
    $user = make_user('reset');
    request_password_reset(strtoupper($user['email']));

    $row = db()->prepare('SELECT * FROM password_resets WHERE user_id = ?');
    $row->execute([$user['id']]);
    $reset = $row->fetch();
    expect_true((bool) $reset, 'a reset row exists');
    expect_same(64, strlen($reset['token_hash']));

    flush_mail_queue();
    $email = latest_email_to($user['email'], 1);
    expect_true($email !== null, 'reset email was written');
    preg_match('~reset-password\?token=([a-f0-9]{64})~', email_text($email), $m);
    $token = $m[1] ?? '';
    expect_same(hash('sha256', $token), $reset['token_hash'], 'only the hash is stored');

    $found = find_password_reset($token);
    expect_same($user['id'], (int) $found['user_id']);
    expect_same(null, find_password_reset(str_repeat('a', 64)));
    expect_same(null, find_password_reset('not-a-token'));

    complete_password_reset($found, 'N3w#password');
    expect_true(password_matches($user['id'], 'N3w#password'));
    expect_same(null, find_password_reset($token), 'used tokens stop working');
    expect_same(1, count_rows('SELECT session_epoch FROM users WHERE id = ?', [$user['id']]), 'other sessions are invalidated');

    db()->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?)')
        ->execute([$user['id'], hash('sha256', str_repeat('b', 64)), time() - 1, time() - 3700]);
    expect_same(null, find_password_reset(str_repeat('b', 64)), 'expired tokens stop working');
    unset($_SERVER['REMOTE_ADDR']);
});

test('reset requests for unknown emails do nothing and are rate limited', function () {
    $_SERVER['REMOTE_ADDR'] = '192.0.2.251';
    $before = count_rows('SELECT COUNT(*) FROM mail_queue');
    request_password_reset('nobody@example.test');
    expect_same($before, count_rows('SELECT COUNT(*) FROM mail_queue'));

    $user = make_user('flood');
    for ($i = 0; $i < 5; $i++) {
        request_password_reset($user['email']);
    }
    expect_same(3, count_rows('SELECT COUNT(*) FROM mail_queue WHERE to_email = ?', [$user['email']]), 'three per email per hour');
    unset($_SERVER['REMOTE_ADDR']);
});
