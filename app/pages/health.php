<?php
/**
 * Health check endpoint for Render, Docker, Kubernetes, and uptime monitors.
 * Accessible at /health and /healthz.
 */
defined('FINPULSE') || exit;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$dbStatus = 'unconfigured';
$dbConnected = false;
$dbError = null;

try {
    $pdo = db();
    $pdo->query('SELECT 1');
    $dbStatus = 'connected';
    $dbConnected = true;
} catch (Throwable $e) {
    $dbStatus = 'disconnected';
    if (env_bool('APP_DEBUG', false)) {
        $dbError = $e->getMessage();
    }
}

$response = [
    'status' => $dbConnected ? 'healthy' : 'degraded',
    'app' => 'FinPulse',
    'version' => defined('APP_VERSION') ? APP_VERSION : '2.1.0',
    'timestamp' => gmdate('c'),
    'database' => $dbStatus,
];

if ($dbError !== null) {
    $response['database_error'] = $dbError;
}

// By default, return HTTP 200 so orchestrators know the web server is alive.
// If ?strict=1 is requested, return HTTP 503 when the database is disconnected.
if (isset($_GET['strict']) && !$dbConnected) {
    $response['status'] = 'unhealthy';
    http_response_code(503);
} else {
    http_response_code(200);
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit;
