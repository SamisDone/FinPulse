<?php
/**
 * Authentication: CSRF protection, registration, throttled login, sessions and password resets.
 */
defined('SIXPENCE') || exit;

const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_LOCKOUT_SECONDS = 900;
const PASSWORD_RESET_TTL = 3600;

/* ---------------------------------------------------------------------------
 * CSRF: one token per session, rotated on sign-in and sign-out.
 * ------------------------------------------------------------------------ */

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
}

/** Stop a POST whose token is missing or wrong and send the visitor back to the page. */
function verify_csrf(): void
{
    $token = $_POST['_token'] ?? '';
    if (!is_string($token) || empty($_SESSION['_csrf']) || !hash_equals($_SESSION['_csrf'], $token)) {
        flash('error', 'Your session expired before the form was sent. Please try again.');
        redirect(current_route());
    }
}

/* ---------------------------------------------------------------------------
 * Current user & sessions
 * ------------------------------------------------------------------------ */

function current_user(bool $refresh = false): ?array
{
    static $user = false;
    if ($user !== false && !$refresh) {
        return $user;
    }
    $user = null;
    if (!empty($_SESSION['user_id'])) {
        $stmt = db()->prepare('SELECT id, username, email, currency, notification_preferences, session_epoch, created_at FROM users WHERE id = ?');
        $stmt->execute([(int) $_SESSION['user_id']]);
        $row = $stmt->fetch() ?: null;
        // A password change elsewhere bumps session_epoch, which signs out every other session.
        if ($row && (int) $row['session_epoch'] === (int) ($_SESSION['epoch'] ?? 0)) {
            $row['id'] = (int) $row['id'];
            $user = $row;
        } else {
            unset($_SESSION['user_id'], $_SESSION['epoch']);
        }
    }
    return $user;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            $_SESSION['_intended'] = $_SERVER['REQUEST_URI'] ?? url('dashboard');
        }
        flash('info', 'Sign in to continue.');
        redirect('login');
    }
    run_user_maintenance($user);
    return $user;
}

function redirect_if_logged_in(): void
{
    if (current_user()) {
        redirect('dashboard');
    }
}

function log_in(array $user): void
{
    session_regenerate_id(true);
    $epoch = db()->prepare('SELECT session_epoch FROM users WHERE id = ?');
    $epoch->execute([(int) $user['id']]);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['epoch'] = (int) $epoch->fetchColumn();
    unset($_SESSION['_csrf'], $_SESSION['_jobs_at']);
    current_user(true);
}

function log_out(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'domain' => $p['domain'], 'secure' => $p['secure'], 'httponly' => $p['httponly'], 'samesite' => $p['samesite'] ?? 'Lax']);
    }
    session_destroy();
}

/** Invalidate every existing session for a user; the current one stays signed in when asked. */
function bump_session_epoch(int $user_id, bool $keep_current = false): void
{
    db()->prepare('UPDATE users SET session_epoch = session_epoch + 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$user_id]);
    if ($keep_current && (int) ($_SESSION['user_id'] ?? 0) === $user_id) {
        $_SESSION['epoch'] = (int) ($_SESSION['epoch'] ?? 0) + 1;
        session_regenerate_id(true);
    }
}

function set_password(int $user_id, string $password): void
{
    db()->prepare('UPDATE users SET password_hash = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
        ->execute([password_hash($password, PASSWORD_DEFAULT), $user_id]);
}

function password_matches(int $user_id, string $password): bool
{
    $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$user_id]);
    return password_verify($password, (string) $stmt->fetchColumn());
}

/* ---------------------------------------------------------------------------
 * Registration
 * ------------------------------------------------------------------------ */

/** Returns a message describing what the password is missing, or null when it is acceptable. */
function password_problems(string $password): ?string
{
    if (strlen($password) < 8) {
        return 'Use at least 8 characters.';
    }
    if (!preg_match('/[A-Z]/', $password) || !preg_match('/\d/', $password) || !preg_match('/[^A-Za-z0-9]/', $password)) {
        return 'Include an uppercase letter, a number and a symbol.';
    }
    return null;
}

function validate_username(string $username): ?string
{
    return preg_match('/^[A-Za-z0-9._-]{3,32}$/', $username) ? null : 'Use 3–32 letters, numbers, dots, dashes or underscores.';
}

/**
 * @return array{0: ?int, 1: array<string,string>} the new user id, or field errors
 */
function register_user(string $username, string $email, string $password, string $confirm): array
{
    $errors = [];
    if ($problem = validate_username($username)) {
        $errors['username'] = $problem;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 191) {
        $errors['email'] = 'Enter a valid email address.';
    }
    if ($problem = password_problems($password)) {
        $errors['password'] = $problem;
    } elseif (!hash_equals($password, $confirm)) {
        $errors['password_confirmation'] = 'The passwords don\'t match.';
    }
    if ($errors) {
        return [null, $errors];
    }

    $exists = db()->prepare('SELECT username, email FROM users WHERE LOWER(username) = LOWER(?) OR LOWER(email) = LOWER(?)');
    $exists->execute([$username, $email]);
    foreach ($exists->fetchAll() as $row) {
        if (strcasecmp($row['username'], $username) === 0) {
            $errors['username'] = 'That username is taken.';
        }
        if (strcasecmp($row['email'], $email) === 0) {
            $errors['email'] = 'An account with this email already exists.';
        }
    }
    if ($errors) {
        return [null, $errors];
    }

    db()->prepare('INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)')
        ->execute([$username, strtolower($email), password_hash($password, PASSWORD_DEFAULT)]);
    $id = (int) db()->lastInsertId();
    seed_user_defaults($id);
    return [$id, []];
}

/* ---------------------------------------------------------------------------
 * Rate limiting (stored in the database, so clearing cookies doesn't reset it)
 * ------------------------------------------------------------------------ */

/** Count a hit in a fixed window; returns true once the bucket has gone over $max. */
function rate_limit_hit(string $bucket, int $max, int $window): bool
{
    $key = hash('sha256', 'rate|' . $bucket);
    $now = time();
    $stmt = db()->prepare('SELECT attempts, last_attempt_at FROM login_attempts WHERE throttle_key = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();

    if (!$row) {
        db()->prepare('INSERT INTO login_attempts (throttle_key, attempts, locked_until, last_attempt_at) VALUES (?, 1, 0, ?)')->execute([$key, $now]);
        return false;
    }
    // last_attempt_at holds the start of the current window.
    if ($now - (int) $row['last_attempt_at'] >= $window) {
        db()->prepare('UPDATE login_attempts SET attempts = 1, last_attempt_at = ? WHERE throttle_key = ?')->execute([$now, $key]);
        return false;
    }
    db()->prepare('UPDATE login_attempts SET attempts = attempts + 1 WHERE throttle_key = ?')->execute([$key]);
    return (int) $row['attempts'] + 1 > $max;
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? 'cli';
}

/**
 * @return array{status: 'ok'|'invalid'|'locked', user?: array, retry_minutes?: int}
 */
function attempt_login(string $identifier, string $password): array
{
    $key = hash('sha256', client_ip() . '|' . strtolower(trim($identifier)));
    $now = time();

    $stmt = db()->prepare('SELECT attempts, locked_until, last_attempt_at FROM login_attempts WHERE throttle_key = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch() ?: null;

    if ($row && (int) $row['locked_until'] > $now) {
        return ['status' => 'locked', 'retry_minutes' => (int) ceil(((int) $row['locked_until'] - $now) / 60)];
    }

    $find = db()->prepare('SELECT id, username, password_hash FROM users WHERE LOWER(username) = LOWER(?) OR LOWER(email) = LOWER(?) LIMIT 1');
    $find->execute([$identifier, $identifier]);
    $user = $find->fetch();

    // Verify against a dummy hash when the user doesn't exist, so both cases take the same time.
    $hash = $user['password_hash'] ?? '$2y$10$uZkHKK20NIFRZ0VHg/IVE.hd46M20W9oLMRiPWJXOp6Yw74ax6bc.';
    $valid = password_verify($password, $hash) && $user;

    if ($valid) {
        db()->prepare('DELETE FROM login_attempts WHERE throttle_key = ?')->execute([$key]);
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            set_password((int) $user['id'], $password);
        }
        return ['status' => 'ok', 'user' => $user];
    }

    // Attempts older than the lockout window don't count.
    $attempts = ($row && $now - (int) $row['last_attempt_at'] < LOGIN_LOCKOUT_SECONDS) ? (int) $row['attempts'] + 1 : 1;
    $locked_until = $attempts >= LOGIN_MAX_ATTEMPTS ? $now + LOGIN_LOCKOUT_SECONDS : 0;
    if ($locked_until) {
        $attempts = 0;
    }

    if ($row) {
        db()->prepare('UPDATE login_attempts SET attempts = ?, locked_until = ?, last_attempt_at = ? WHERE throttle_key = ?')
            ->execute([$attempts, $locked_until, $now, $key]);
    } else {
        db()->prepare('INSERT INTO login_attempts (throttle_key, attempts, locked_until, last_attempt_at) VALUES (?, ?, ?, ?)')
            ->execute([$key, $attempts, $locked_until, $now]);
    }

    return $locked_until
        ? ['status' => 'locked', 'retry_minutes' => (int) ceil(LOGIN_LOCKOUT_SECONDS / 60)]
        : ['status' => 'invalid'];
}

/* ---------------------------------------------------------------------------
 * Password resets
 * ------------------------------------------------------------------------ */

/**
 * Start a reset for an email address. Always behaves the same whether or not the account
 * exists, so the form can't be used to discover who has an account.
 */
function request_password_reset(string $email): void
{
    $email = strtolower(trim($email));
    if (rate_limit_hit('reset-ip|' . client_ip(), 10, 3600) || rate_limit_hit('reset-email|' . $email, 3, 3600)) {
        return;
    }

    $stmt = db()->prepare('SELECT id, username, email FROM users WHERE LOWER(email) = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user) {
        return;
    }

    $token = bin2hex(random_bytes(32));
    $now = time();
    db()->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$user['id']]);
    db()->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?)')
        ->execute([$user['id'], hash('sha256', $token), $now + PASSWORD_RESET_TTL, $now]);

    $link = absolute_url('reset-password', ['token' => $token]);
    queue_mail(
        $user['email'],
        $user['username'],
        'Reset your Sixpence password',
        email_template(
            'Reset your password',
            ['Someone (hopefully you) asked to reset the password for the Sixpence account ' . $user['username'] . '.', 'This link works once and expires in 60 minutes. If you didn\'t ask for this, you can ignore this email and your password won\'t change.'],
            ['Choose a new password', $link]
        )
    );
}

/** Look up an unused, unexpired reset by its raw token. */
function find_password_reset(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $stmt = db()->prepare('SELECT r.id, r.user_id, r.expires_at, u.username, u.email FROM password_resets r JOIN users u ON u.id = r.user_id WHERE r.token_hash = ? AND r.used_at IS NULL');
    $stmt->execute([hash('sha256', $token)]);
    $reset = $stmt->fetch();
    return $reset && (int) $reset['expires_at'] > time() ? $reset : null;
}

/** Finish a reset: set the password, burn the token, and sign out every other session. */
function complete_password_reset(array $reset, string $password): void
{
    $user_id = (int) $reset['user_id'];
    set_password($user_id, $password);
    db()->prepare('UPDATE password_resets SET used_at = ? WHERE id = ?')->execute([time(), $reset['id']]);
    db()->prepare('DELETE FROM password_resets WHERE user_id = ? AND id <> ?')->execute([$user_id, $reset['id']]);
    bump_session_epoch($user_id);
    notify_password_changed($user_id, $reset['email'], $reset['username']);
}
