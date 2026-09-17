<?php
/**
 * Health check endpoint for Render, Docker, Kubernetes, and uptime monitors.
 * Accessible at /health and /healthz.
 */
defined('FINPULSE') || exit;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

http_response_code(200);
echo json_encode(['status' => 'ok']) . "\n";
exit;
