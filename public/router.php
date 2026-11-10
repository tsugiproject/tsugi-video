<?php
// Development-only router. Production Apache should Alias /media to storage/media.
require_once dirname(__DIR__).'/src/bootstrap.php';
$uri=request_path();
if (preg_match('~^/media/([a-f0-9]{24})/(index\.m3u8|segment[0-9]{5}\.ts|captions\.vtt)$~',$uri,$m)) {
    $_GET['id']=$m[1]; $_GET['file']=$m[2]; require __DIR__.'/media.php'; return true;
}
if ($uri==='/') { require __DIR__.'/index.php'; return true; }
if ($uri==='/api' || str_starts_with($uri,'/api/')) { require __DIR__.'/api.php'; return true; }
if (preg_match('~^/(watch|embed)/([a-f0-9]{24})$~',$uri,$m)) {
    $_GET['id']=$m[2];$_GET['embed']=$m[1]==='embed'?'1':'0';require __DIR__.'/player.php';return true;
}
$file=realpath(__DIR__.$uri);
if ($file && str_starts_with($file,__DIR__.'/') && is_file($file)) return false;
http_response_code(404);echo 'Not found';return true;
