<?php
/**
 * FinPulse test runner.
 *
 *   php tests/run.php                 # everything
 *   php tests/run.php unit            # unit tests only
 *   php tests/run.php feature         # feature (HTTP) tests only
 *   php tests/run.php --filter=reset  # tests whose name contains "reset"
 *
 * Uses a temporary SQLite database by default. For MySQL set DB_TYPE=mysql and point DB_NAME
 * at a database whose name contains "test"; every table in it is dropped first.
 */
if (PHP_SAPI !== 'cli') {
    exit;
}

$suite = null;
$filter = null;
foreach (array_slice($argv, 1) as $arg) {
    if (in_array($arg, ['unit', 'feature'], true)) {
        $suite = $arg;
    } elseif (str_starts_with($arg, '--filter=')) {
        $filter = substr($arg, 9);
    }
}

// Isolated environment, set before the app boots so nothing touches real data.
$dir = sys_get_temp_dir() . '/finpulse-tests-' . getmypid() . '-' . bin2hex(random_bytes(3));
mkdir($dir . '/mail', 0775, true);
foreach ([
    'APP_DEBUG' => 'true',
    'APP_TIMEZONE' => 'UTC',
    'DB_TYPE' => getenv('DB_TYPE') ?: 'sqlite',
    'DB_PATH' => $dir . '/test.db',
    'MAIL_DRIVER' => 'log',
    'MAIL_LOG_PATH' => $dir . '/mail',
    'FINPULSE_TEST_DIR' => $dir,
] as $key => $value) {
    putenv("$key=$value");
}

require dirname(__DIR__) . '/app/core/bootstrap.php';
ini_set('error_log', $dir . '/php-errors.log');
require __DIR__ . '/support.php';

if (db_is_mysql()) {
    $name = (string) env('DB_NAME', '');
    if (!str_contains($name, 'test')) {
        fwrite(STDERR, "Refusing to run against MySQL database \"$name\": its name must contain \"test\" because every table is dropped.\n");
        exit(2);
    }
    $pdo = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', env('DB_HOST', '127.0.0.1'), env('DB_PORT', '3306'), $name), env('DB_USER', 'root'), env('DB_PASS', ''));
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $pdo->exec("DROP TABLE `$table`");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}
db();

foreach (['Unit' => 'unit', 'Feature' => 'feature'] as $folder => $name) {
    if ($suite !== null && $suite !== $name) {
        continue;
    }
    TestRegistry::$suite = $name;
    foreach (glob(__DIR__ . "/$folder/*Test.php") as $file) {
        require $file;
    }
}

$passed = 0;
$failures = [];
$started = microtime(true);
$current_suite = null;
printf("FinPulse %s · PHP %s · %s\n", APP_VERSION, PHP_VERSION, db_is_mysql() ? 'MySQL' : 'SQLite');

foreach (TestRegistry::$tests as $test) {
    if ($filter !== null && stripos($test['name'], $filter) === false) {
        continue;
    }
    if ($test['suite'] !== $current_suite) {
        $current_suite = $test['suite'];
        echo "\n" . ucfirst($current_suite) . "\n";
    }
    $_SESSION = [];
    use_currency(null);
    try {
        ($test['fn'])();
        $passed++;
        echo "  PASS  {$test['name']}\n";
    } catch (Throwable $e) {
        $where = ['file' => $e->getFile(), 'line' => $e->getLine()];
        foreach ([['file' => $e->getFile(), 'line' => $e->getLine()], ...$e->getTrace()] as $frame) {
            if (isset($frame['file']) && str_contains($frame['file'], DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR) && !str_ends_with($frame['file'], 'support.php')) {
                $where = $frame;
                break;
            }
        }
        $location = isset($where['file']) ? str_replace(APP_ROOT . DIRECTORY_SEPARATOR, '', $where['file']) . ':' . ($where['line'] ?? '?') : '';
        $failures[] = [$test['name'], ($e instanceof AssertionFailed ? '' : get_class($e) . ': ') . $e->getMessage(), $location];
        echo "  FAIL  {$test['name']}\n";
    }
}

printf("\n%d passed, %d failed in %.1fs\n", $passed, count($failures), microtime(true) - $started);
foreach ($failures as [$name, $message, $location]) {
    echo "\n  x $name\n    $message\n" . ($location ? "    at $location\n" : '');
}
if ($failures && is_file(TestServer::$log) && filesize(TestServer::$log) > 0) {
    echo "\nServer log (last lines):\n" . implode('', array_slice(file(TestServer::$log), -15));
}

exit($failures ? 1 : 0);
