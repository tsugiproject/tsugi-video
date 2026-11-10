<?php
// Serve one published HLS file. The filename allowlist is the access control:
// originals and in-progress work directories are never reachable here.

require_once dirname(__DIR__) . '/src/bootstrap.php';

$id = $_GET['id'] ?? '';
$file = $_GET['file'] ?? '';
$allowedFile = preg_match('/^(index\\.m3u8|segment[0-9]{5}\\.ts|captions\\.vtt)$/', $file);
if (!is_content_id($id) || !$allowedFile) {
    http_response_code(404);
    exit('Not found');
}

$path = content_dir('media', $id) . '/' . $file;
if (!is_file($path)) {
    http_response_code(404);
    exit('Not found');
}

$extension = pathinfo($path, PATHINFO_EXTENSION);
$types = [
    'm3u8' => 'application/vnd.apple.mpegurl',
    'vtt' => 'text/vtt',
    'ts' => 'video/mp2t',
];
header('Content-Type: ' . ($types[$extension] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
