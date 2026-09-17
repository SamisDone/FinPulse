<?php
/**
 * FinPulse front controller: the only PHP file inside the web root.
 */

// With PHP's built-in server, let it serve real files (CSS, JS, fonts) directly.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($file !== __FILE__ && is_file($file)) {
        return false;
    }
}

require dirname(__DIR__) . '/app/core/bootstrap.php';

dispatch();
