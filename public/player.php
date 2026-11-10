<?php
// Public watch page and iframe embed. Playback is not authenticated.

require_once dirname(__DIR__) . '/src/bootstrap.php';

$id = $_GET['id'] ?? '';
if (!preg_match('/^[a-f0-9]{24}$/', $id)) {
    http_response_code(404);
    exit('Not found');
}

$job = job_by_id($id);
if (!$job || $job['status'] !== 'completed') {
    http_response_code(404);
    exit('Video not ready');
}

$embed = ($_GET['embed'] ?? '') === '1';
$hls = url_path('/media/' . $id . '/index.m3u8');
$captions = is_file(rtrim(pathof('storage'), '/') . '/media/' . $id . '/captions.vtt');

header('Referrer-Policy: no-referrer');
// frame-ancestors * lets other sites embed /embed/{id}. Scripts and media stay on this host.
// worker-src blob: is the hls.js demuxer worker. Inline handlers are not allowed.
header("Content-Security-Policy: default-src 'self'; script-src 'self'; worker-src 'self' blob:; style-src 'self'; media-src 'self' blob:; connect-src 'self'; img-src 'self' data:; frame-ancestors *;");
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($job['title']) ?></title>
    <link rel="stylesheet" href="<?= e(url_path('/assets/style.css')) ?>">
</head>
<body class="player-page">
<div class="player-wrap">
    <div class="video-stage">
    <video id="video" controls playsinline preload="metadata" data-hls="<?= e($hls) ?>" crossorigin="anonymous">
        <?php if ($captions): ?>
            <track kind="captions" src="<?= e(url_path('/media/' . $id . '/captions.vtt')) ?>" srclang="en" label="English" default>
        <?php endif; ?>
    </video>
    </div>
    <?php if (!$embed): ?>
        <div class="player-info">
            <h1><?= e($job['title']) ?></h1>
            <p>Embed this video:</p>
            <textarea id="embed-code" readonly rows="3">&lt;iframe src="<?= e(rtrim(cfg()['base_url'], '/') . '/embed/' . $id) ?>" width="800" height="450" allow="fullscreen" allowfullscreen&gt;&lt;/iframe&gt;</textarea>
            <p class="muted">HLS playback requires Safari or bundled hls.js for other browsers.</p>
        </div>
    <?php endif; ?>
    <div id="play-error" class="muted"></div>
</div>
<script src="<?= e(url_path('/assets/hls.min.js')) ?>"></script>
<script src="<?= e(url_path('/assets/player.js') . '?v=' . filemtime(__DIR__ . '/assets/player.js')) ?>"></script>
</body>
</html>
