<?php
require_once dirname(__DIR__).'/src/bootstrap.php';
$id=$_GET['id'] ?? '';
$file=$_GET['file'] ?? '';
if (!preg_match('/^[a-f0-9]{24}$/', $id) || !preg_match('/^(index\\.m3u8|segment[0-9]{5}\\.ts|captions\\.vtt)$/', $file)) {
    http_response_code(404); exit('Not found');
}
$path=rtrim(pathof('storage'), '/').'/media/'.$id.'/'.$file;
if (!is_file($path)) { http_response_code(404); exit('Not found'); }
$ext=pathinfo($path, PATHINFO_EXTENSION);
$types=['m3u8'=>'application/vnd.apple.mpegurl','vtt'=>'text/vtt','ts'=>'video/mp2t'];
header('Content-Type: '.($types[$ext] ?? 'application/octet-stream'));
header('Content-Length: '.filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
