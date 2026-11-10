<?php
// Router for `php -S`. Apache uses .htaccess instead, and should Alias /media
// at the published storage directory rather than proxying it through PHP.

require_once dirname(__DIR__) . '/src/bootstrap.php';

$uri = request_path();

if (preg_match('~^/media/[a-f0-9]{2}/[a-f0-9]{2}/([a-f0-9]{64})/((?:[0-9]{2,4}/)?(?:index\.m3u8|segment[0-9]{5}\.ts)|captions\.vtt|captions-[a-z]{2,3}\.vtt)$~', $uri, $matches)) {
    $_GET['id'] = $matches[1];
    $_GET['file'] = $matches[2];
    require __DIR__ . '/media.php';
    return true;
}

if ($uri === '/') {
    require __DIR__ . '/index.php';
    return true;
}

if ($uri === '/api' || str_starts_with($uri, '/api/')) {
    require __DIR__ . '/api.php';
    return true;
}

if (preg_match('~^/(watch|embed)/([a-f0-9]{64})$~', $uri, $matches)) {
    $_GET['id'] = $matches[2];
    $_GET['embed'] = $matches[1] === 'embed' ? '1' : '0';
    require __DIR__ . '/player.php';
    return true;
}

if (preg_match('~^/watch/([A-Za-z0-9][A-Za-z0-9._-]*)\.json$~', $uri, $matches)) {
    $_GET['collection'] = $matches[1];
    $_GET['format'] = 'json';
    require __DIR__ . '/playlist.php';
    return true;
}

if (preg_match('~^/watch/([A-Za-z0-9][A-Za-z0-9._-]*)$~', $uri, $matches)) {
    $_GET['collection'] = $matches[1];
    require __DIR__ . '/playlist.php';
    return true;
}

if (preg_match('~^/(watch|embed)/([A-Za-z0-9][A-Za-z0-9._-]*)/([A-Za-z0-9][A-Za-z0-9._-]*)$~', $uri, $matches)) {
    $_GET['collection'] = $matches[2];
    $_GET['key'] = $matches[3];
    $_GET['embed'] = $matches[1] === 'embed' ? '1' : '0';
    require __DIR__ . '/player.php';
    return true;
}

// Let the built-in server send real files under public/, such as assets.
$file = realpath(__DIR__ . $uri);
if ($file && str_starts_with($file, __DIR__ . '/') && is_file($file)) {
    return false;
}

http_response_code(404);
echo 'Not found';
return true;
