<?php
// Count one play of a finished video. The player calls this when playback starts.

require_once dirname(__DIR__) . '/src/bootstrap.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Max-Age: 86400');
    header('Cache-Control: no-store');
    http_response_code(204);
    exit;
}
if ($method !== 'POST') {
    http_response_code(405);
    header('Allow: POST, OPTIONS');
    header('Cache-Control: no-store');
    exit;
}

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
$id = (string) ($_GET['id'] ?? '');
if (!record_view($id)) {
    http_response_code(404);
    exit;
}
http_response_code(204);
