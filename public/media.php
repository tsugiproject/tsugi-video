<?php
// Serve one published HLS file. The filename allowlist is the access control:
// originals and in-progress work directories are never reachable here.

require_once dirname(__DIR__) . '/src/bootstrap.php';

$id = (string) ($_GET['id'] ?? '');
$file = str_replace('\\', '/', (string) ($_GET['file'] ?? ''));
if (!is_content_id($id) || !published_media_name($file)) {
    http_response_code(404);
    exit('Not found');
}

$root = realpath(content_dir('media', $id));
$path = $root === false ? false : realpath($root . '/' . $file);
if ($root === false || $path === false || !is_file($path) || !str_starts_with($path, $root . '/')) {
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
