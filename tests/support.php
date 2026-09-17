<?php
/**
 * A tiny, dependency-free test harness: test registration, assertions,
 * a throwaway PHP web server and a cookie-aware HTTP client.
 */

final class AssertionFailed extends Exception
{
}

final class TestRegistry
{
    /** @var array<array{name: string, fn: callable, suite: string}> */
    public static array $tests = [];
    public static string $suite = 'unit';
}

function test(string $name, callable $fn): void
{
    TestRegistry::$tests[] = ['name' => $name, 'fn' => $fn, 'suite' => TestRegistry::$suite];
}

function fail(string $message): never
{
    throw new AssertionFailed($message);
}

function expect_true(mixed $condition, string $message = 'Expected condition to be true'): void
{
    if ($condition !== true) {
        fail($message . ' (got ' . var_export($condition, true) . ')');
    }
}

function expect_false(mixed $condition, string $message = 'Expected condition to be false'): void
{
    if ($condition !== false) {
        fail($message . ' (got ' . var_export($condition, true) . ')');
    }
}

function expect_same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        fail(($message ? "$message: " : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function expect_contains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        fail(($message ? "$message: " : '') . 'expected to find ' . var_export($needle, true) . ' in ' . var_export(mb_substr($haystack, 0, 300), true) . (strlen($haystack) > 300 ? '…' : ''));
    }
}

function expect_not_contains(string $needle, string $haystack, string $message = ''): void
{
    if (str_contains($haystack, $needle)) {
        fail(($message ? "$message: " : '') . 'did not expect to find ' . var_export($needle, true));
    }
}

function expect_throws(callable $fn, string $contains = ''): Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($contains !== '' && !str_contains($e->getMessage(), $contains)) {
            fail('Exception message ' . var_export($e->getMessage(), true) . " doesn't contain " . var_export($contains, true));
        }
        return $e;
    }
    fail('Expected an exception');
}

/* ---------------------------------------------------------------------------
 * Data helpers
 * ------------------------------------------------------------------------ */

/** Register a user with a unique name and return the full users row. */
function make_user(string $prefix = 'user', string $password = 'Str0ng#pass'): array
{
    static $n = 0;
    $n++;
    $name = substr($prefix . $n . bin2hex(random_bytes(3)), 0, 32);
    [$id, $errors] = register_user($name, $name . '@example.test', $password, $password);
    if ($errors) {
        fail('Could not create test user: ' . implode(', ', $errors));
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    $user['id'] = (int) $user['id'];
    $user['password'] = $password;
    return $user;
}

function add_expense(array $user, float $amount, string $date, string $category = 'Groceries', string $note = ''): int
{
    db()->prepare('INSERT INTO expenses (user_id, amount, expense_date, category_id, description) VALUES (?, ?, ?, ?, ?)')
        ->execute([$user['id'], $amount, $date, lookup_id($user['id'], 'expense_categories', $category), $note]);
    return (int) db()->lastInsertId();
}

function add_income(array $user, float $amount, string $date, string $source = 'Salary'): int
{
    db()->prepare('INSERT INTO income (user_id, amount, income_date, source_id) VALUES (?, ?, ?, ?)')
        ->execute([$user['id'], $amount, $date, lookup_id($user['id'], 'income_sources', $source)]);
    return (int) db()->lastInsertId();
}

function count_rows(string $sql, array $params = []): int
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $value = (int) $stmt->fetchColumn();
    $stmt->closeCursor();
    return $value;
}

function scalar(string $sql, array $params = []): mixed
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $value = $stmt->fetchColumn();
    $stmt->closeCursor();
    return $value;
}

function free_port(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    return (int) substr(strrchr($name, ':'), 1);
}

function wait_for_port(int $port, float $seconds = 10): void
{
    $deadline = microtime(true) + $seconds;
    while (microtime(true) < $deadline) {
        $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($conn) {
            fclose($conn);
            return;
        }
        usleep(100_000);
    }
    throw new RuntimeException("Nothing is listening on port $port");
}

/** Newest .eml in the mail log addressed to $email (optionally containing $text), waiting briefly for the queue to flush. */
function latest_email_to(string $email, float $wait = 5, string $text = ''): ?string
{
    $deadline = microtime(true) + $wait;
    do {
        $files = glob(mail_log_dir() . '/*.eml') ?: [];
        rsort($files);
        foreach ($files as $file) {
            $raw = (string) file_get_contents($file);
            if (str_contains($raw, $email) && ($text === '' || str_contains($raw, $text))) {
                return $raw;
            }
        }
        usleep(150_000);
    } while (microtime(true) < $deadline);
    return null;
}

/** Decode the text/plain part of a raw MIME message built by build_mime(). */
function email_text(string $raw): string
{
    if (preg_match('~Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n(.*?)\r\n--~s', $raw, $m)) {
        return base64_decode(str_replace("\r\n", '', $m[1]));
    }
    return '';
}

/* ---------------------------------------------------------------------------
 * Web server + HTTP client for feature tests
 * ------------------------------------------------------------------------ */

final class TestServer
{
    private static ?string $url = null;
    /** @var resource|null */
    private static $process = null;
    public static string $log = '';

    public static function url(): string
    {
        if (self::$url !== null) {
            return self::$url;
        }
        $port = free_port();
        self::$url = "http://127.0.0.1:$port";
        self::$log = getenv('SIXPENCE_TEST_DIR') . '/server.log';

        $env = getenv();
        $env['APP_URL'] = self::$url;
        $command = [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', APP_ROOT . '/public', APP_ROOT . '/public/index.php'];
        self::$process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', self::$log, 'a'], 2 => ['file', self::$log, 'a']], $pipes, APP_ROOT, $env);
        if (!is_resource(self::$process)) {
            throw new RuntimeException('Could not start the PHP development server.');
        }
        register_shutdown_function(static function (): void {
            if (is_resource(self::$process)) {
                proc_terminate(self::$process);
            }
        });
        wait_for_port($port);
        return self::$url;
    }
}

final class HttpClient
{
    public int $status = 0;
    public string $body = '';
    /** @var array<string, string> lower-cased header => value */
    public array $headers = [];
    /** @var array<string, string> */
    private array $cookies = [];
    private string $last_token = '';

    public function get(string $path): self
    {
        return $this->request('GET', $path);
    }

    /** POST a form. The CSRF token from the last HTML page is added unless one is given (or $token is false). */
    public function post(string $path, array $fields = [], bool $token = true): self
    {
        if ($token && !array_key_exists('_token', $fields)) {
            $fields['_token'] = $this->token();
        }
        return $this->request('POST', $path, http_build_query($fields));
    }

    /** Follow redirects until a non-redirect response. */
    public function follow(int $max = 5): self
    {
        while ($max-- > 0 && in_array($this->status, [301, 302, 303, 307], true)) {
            $location = $this->headers['location'] ?? '';
            $this->request('GET', preg_replace('~^https?://[^/]+~', '', $location));
        }
        return $this;
    }

    /** The CSRF token from the most recent page that had one (the token lasts for the session). */
    public function token(): string
    {
        if ($this->last_token === '') {
            $this->get('/login');
        }
        return $this->last_token !== '' ? $this->last_token : fail('No CSRF token found');
    }

    public function login(string $identifier, string $password): self
    {
        return $this->get('/login')->post('/login', ['login' => $identifier, 'password' => $password])->follow();
    }

    public function redirectPath(): string
    {
        return (string) parse_url($this->headers['location'] ?? '', PHP_URL_PATH);
    }

    private function request(string $method, string $path, ?string $body = null): self
    {
        $headers = ['Accept: text/html'];
        if ($this->cookies) {
            $headers[] = 'Cookie: ' . implode('; ', array_map(static fn($k, $v) => "$k=$v", array_keys($this->cookies), $this->cookies));
        }
        if ($body !== null) {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body ?? '',
            'follow_location' => 0,
            'ignore_errors' => true,
            'timeout' => 30,
        ]]);

        $this->body = (string) @file_get_contents(TestServer::url() . $path, false, $context);
        $raw_headers = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);

        $this->headers = [];
        $this->status = 0;
        foreach ($raw_headers as $line) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m)) {
                $this->status = (int) $m[1];
                continue;
            }
            [$name, $value] = array_map('trim', explode(':', $line, 2) + [1 => '']);
            $name = strtolower($name);
            if ($name === 'set-cookie') {
                [$pair] = explode(';', $value, 2);
                [$cookie, $cookie_value] = explode('=', $pair, 2) + [1 => ''];
                if ($cookie_value === '' || str_contains(strtolower($value), 'expires=thu, 01 jan 1970') || preg_match('/max-age=0/i', $value)) {
                    unset($this->cookies[$cookie]);
                } else {
                    $this->cookies[$cookie] = $cookie_value;
                }
            }
            $this->headers[$name] = $value;
        }
        if (preg_match('/name="_token" value="([a-f0-9]{64})"/', $this->body, $m)) {
            $this->last_token = $m[1];
        }
        return $this;
    }
}
