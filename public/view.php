<?php
// Count one play of a finished video. The player calls this when playback starts.

require_once dirname(__DIR__) . '/src/bootstrap.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
    header('Access-Control-Max-Age: 86400');
    header('Cache-Control: no-store');
    http_response_code(204);
    exit;
}
if (!in_array($method, ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD, OPTIONS');
    header('Cache-Control: no-store');
    exit;
}

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
$id = (string) ($_GET['id'] ?? '');
if ($method === 'GET' && !record_view($id)) {
    http_response_code(404);
    exit;
}
http_response_code(204);
