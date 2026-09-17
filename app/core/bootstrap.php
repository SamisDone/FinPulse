<?php
/**
 * Sixpence bootstrap: loaded first by the web front controller, CLI scripts and tests.
 * Loads configuration, connects the helper libraries, and hardens the HTTP session.
 */
declare(strict_types=1);

define('SIXPENCE', true);
define('APP_ROOT', dirname(__DIR__, 2));
define('APP_VERSION', '2.1.0');

require __DIR__ . '/config.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/icons.php';
require __DIR__ . '/db.php';
require __DIR__ . '/router.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/layout.php';

require APP_ROOT . '/app/services/finance.php';
require APP_ROOT . '/app/services/mail.php';
require APP_ROOT . '/app/services/recurring.php';
require APP_ROOT . '/app/services/notifications.php';
require APP_ROOT . '/app/services/reports.php';
require APP_ROOT . '/app/services/pdf.php';
require APP_ROOT . '/app/services/report_pdf.php';

load_env(APP_ROOT . '/.env');

$debug = env_bool('APP_DEBUG', false);
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');

date_default_timezone_set(env('APP_TIMEZONE') ?: (ini_get('date.timezone') ?: 'UTC'));

if (PHP_SAPI !== 'cli') {
    start_secure_session();
    send_security_headers();

    set_exception_handler(static function (Throwable $e) use ($debug): void {
        error_log('[Sixpence] ' . $e);
        render_error_page(
            500,
            'Something went wrong',
            $debug ? $e->getMessage() : 'We couldn\'t finish that request. Please try again in a moment.'
        );
    });
}
