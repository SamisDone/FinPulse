<?php
/**
 * Sixpence front controller: the only PHP file inside the web root.
 */

// With PHP's built-in server, let it serve real files (CSS, JS, fonts) directly.
// Resolve the path first and keep it inside the web root: without that, a
// request for "/../app/core/db.php" hands application code straight back to the
// server to execute. Apache in production has its own document root, so this
// only ever runs under `php -S`.
if (PHP_SAPI === 'cli-server') {
    $file = realpath(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
    if ($file !== false && $file !== __FILE__ && is_file($file)
        && str_starts_with($file, __DIR__ . DIRECTORY_SEPARATOR)) {
        return false;
    }
}

require dirname(__DIR__) . '/app/core/bootstrap.php';

dispatch();
