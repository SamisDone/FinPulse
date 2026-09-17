<?php
/**
 * Routing and URL generation. public/index.php is the only web entry point;
 * every page lives in app/pages and is reached through a clean URL like /expenses.
 */
defined('FINPULSE') || exit;

/** URL path => [page file in app/pages, arguments available to the page as $route_args]. */
function routes(): array
{
    return [
        '' => ['home'],
        'login' => ['login'],
        'register' => ['register'],
        'logout' => ['logout'],
        'forgot-password' => ['forgot-password'],
        'reset-password' => ['reset-password'],
        'dashboard' => ['dashboard'],
        'income' => ['ledger', ['kind' => 'income']],
        'expenses' => ['ledger', ['kind' => 'expense']],
        'budgets' => ['budgets'],
        'savings' => ['savings'],
        'reports' => ['reports'],
        'notifications' => ['notifications'],
        'settings' => ['settings'],
        'health' => ['health'],
        'healthz' => ['health'],
    ];
}

/** Old 1.x/2.0 file URLs that should keep working. */
function legacy_routes(): array
{
    return ['index' => '', 'profile' => 'settings', '404' => ''] + array_combine(array_filter(array_keys(routes())), array_filter(array_keys(routes())));
}

/**
 * The URL prefix the app is served from: '' at a domain root, '/finpulse' in a subfolder.
 * Handles both a document root pointing at public/ and the root .htaccess fallback.
 */
function base_path(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    if ($configured = env('APP_URL')) {
        return $base = rtrim((string) parse_url($configured, PHP_URL_PATH), '/');
    }
    if (PHP_SAPI === 'cli') {
        return $base = '';
    }

    $uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $script_dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/');
    foreach ([$script_dir, rtrim(str_replace('\\', '/', dirname($script_dir)), '/')] as $candidate) {
        if ($candidate !== '' && ($uri === $candidate || str_starts_with($uri, $candidate . '/'))) {
            return $base = $candidate;
        }
    }
    return $base = '';
}

function request_path(): string
{
    $uri = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
    $path = substr($uri, strlen(base_path()));
    return trim((string) $path, '/');
}

function current_route(): string
{
    return $GLOBALS['finpulse_route'] ?? '';
}

function url(string $route = '', array $query = [], string $fragment = ''): string
{
    $query = array_filter($query, static fn($v) => $v !== null && $v !== '');
    return base_path() . '/' . ltrim($route, '/')
        . ($query ? '?' . http_build_query($query) : '')
        . ($fragment !== '' ? '#' . $fragment : '');
}

/** Absolute URL for links that leave the browser, such as emails. */
function absolute_url(string $route = '', array $query = [], string $fragment = ''): string
{
    return app_url() . substr(url($route, $query, $fragment), strlen(base_path()));
}

function app_url(): string
{
    if ($configured = env('APP_URL')) {
        return rtrim($configured, '/');
    }
    if (PHP_SAPI === 'cli' || empty($_SERVER['HTTP_HOST'])) {
        return 'http://localhost:8000';
    }
    return (is_https() ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . base_path();
}

function asset(string $path): string
{
    $file = APP_ROOT . '/public/assets/' . $path;
    return base_path() . '/assets/' . $path . (is_file($file) ? '?v=' . filemtime($file) : '');
}

/** Current page URL with some query parameters replaced (null removes a key). */
function url_with(array $changes, string $fragment = ''): string
{
    return url(current_route(), array_merge($_GET, $changes), $fragment);
}

function dispatch(): void
{
    $path = request_path();

    if (preg_match('~^([a-z0-9-]+)\.php$~', $path, $m)) {
        $legacy = legacy_routes();
        if (array_key_exists($m[1], $legacy)) {
            header('Location: ' . url($legacy[$m[1]], $_GET), true, 301);
            return;
        }
    }

    $routes = routes();
    if (!array_key_exists($path, $routes)) {
        render_error_page(404, 'Page not found', 'The page you\'re looking for doesn\'t exist or has moved.');
        return;
    }

    [$page, $route_args] = $routes[$path] + [1 => []];
    $GLOBALS['finpulse_route'] = $path;
    require APP_ROOT . '/app/pages/' . $page . '.php';
}
