<?php
/**
 * Sixpence test runner.
 *
 *   php tests/run.php                 # everything
 *   php tests/run.php unit            # unit tests only
 *   php tests/run.php feature         # feature (HTTP) tests only
 *   php tests/run.php --filter=reset  # tests whose name contains "reset"
 *
 * Needs a PostgreSQL database whose name contains "test": the public schema is
 * dropped and recreated before the run. Point DATABASE_URL or the DB_* variables
 * at it.
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
$dir = sys_get_temp_dir() . '/sixpence-tests-' . getmypid() . '-' . bin2hex(random_bytes(3));
mkdir($dir . '/mail', 0775, true);
foreach ([
    'APP_DEBUG' => 'true',
    'APP_TIMEZONE' => 'UTC',
    // Blank unless explicitly exported, so a DATABASE_URL sitting in .env can
    // never point the suite at a real database (the schema gets dropped).
    'DATABASE_URL' => getenv('DATABASE_URL') ?: '',
    'MAIL_DRIVER' => 'log',
    'MAIL_LOG_PATH' => $dir . '/mail',
    'SIXPENCE_TEST_DIR' => $dir,
] as $key => $value) {
    putenv("$key=$value");
}

require dirname(__DIR__) . '/app/core/bootstrap.php';
ini_set('error_log', $dir . '/php-errors.log');
require __DIR__ . '/support.php';

$name = (string) env('DB_NAME', '');
if (getenv('DATABASE_URL')) {
    $name = (string) (parse_url(getenv('DATABASE_URL'), PHP_URL_PATH) ?: '');
    $name = ltrim($name, '/');
}
if (!str_contains($name, 'test')) {
    fwrite(STDERR, "Refusing to run against database \"$name\": its name must contain \"test\" because the schema is dropped.\n");
    exit(2);
}
{
    [$host, $port, $dbname, $user, $pass, $query] = db_settings();
    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $dbname);
    $sslmode = $query['sslmode'] ?? env('DB_SSLMODE', '');
    if ($sslmode !== '') {
        $dsn .= ';sslmode=' . $sslmode;
    }
    $reset = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    // One statement, so the drop order never has to respect foreign keys.
    $reset->exec('DROP SCHEMA public CASCADE; CREATE SCHEMA public;');
    $reset = null;
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
printf("Sixpence %s · PHP %s · %s\n", APP_VERSION, PHP_VERSION, 'PostgreSQL ' . db()->getAttribute(PDO::ATTR_SERVER_VERSION));

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
