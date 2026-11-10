<?php
// Public watch page and iframe embed. Playback is not authenticated.

require_once dirname(__DIR__) . '/src/bootstrap.php';

$id = (string) ($_GET['id'] ?? '');
$keyedCollection = '';
$keyedKey = '';
if ($id === '' && isset($_GET['collection'], $_GET['key'])) {
    $job = placement_job((string) $_GET['collection'], (string) $_GET['key']);
    if (!$job) {
        http_response_code(404);
        exit('Not found');
    }
    $id = $job['id'];
    $keyedCollection = $job['collection'];
    $keyedKey = $job['logical_key'];
} else {
    $job = null;
}
if (!is_content_id($id)) {
    http_response_code(404);
    exit('Not found');
}

$job = $job ?? job_by_id($id);
if (!$job || $job['status'] !== 'completed') {
    http_response_code(404);
    exit('Video not ready');
}

$sharePath = $keyedCollection !== ''
    ? '/embed/' . rawurlencode($keyedCollection) . '/' . rawurlencode($keyedKey)
    : '/embed/' . $id;

$embed = ($_GET['embed'] ?? '') === '1';
$hls = url_path(media_public_path($id) . '/index.m3u8');
$tracks = caption_tracks($id);
$defaultLanguage = null;
foreach ($tracks as $track) {
    if ($track['language'] === 'en') {
        $defaultLanguage = 'en';
        break;
    }
}
if ($defaultLanguage === null && $tracks !== []) {
    $defaultLanguage = $tracks[0]['language'];
}

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
<body class="player-page<?= $embed ? ' embed-page' : '' ?>">
<div class="player-wrap">
    <div class="video-stage">
    <video id="video" controls playsinline preload="metadata" data-hls="<?= e($hls) ?>" crossorigin="anonymous">
        <?php foreach ($tracks as $track): ?>
            <track kind="captions" src="<?= e(url_path(media_public_path($id) . '/' . $track['file'])) ?>" srclang="<?= e($track['language']) ?>" label="<?= e($track['label']) ?>"<?= $track['language'] === $defaultLanguage ? ' default' : '' ?>>
        <?php endforeach; ?>
    </video>
    </div>
    <?php if (!$embed): ?>
        <div class="player-info">
            <h1><?= e($job['title']) ?></h1>
            <p>Embed this video:</p>
            <textarea id="embed-code" readonly rows="3">&lt;iframe src="<?= e(public_base_url() . $sharePath) ?>" width="800" height="450" allow="fullscreen" allowfullscreen&gt;&lt;/iframe&gt;</textarea>
            <p class="muted">HLS playback requires Safari or bundled hls.js for other browsers.</p>
            <?php if ($tracks !== []): ?>
                <p>Captions:
                    <?php foreach ($tracks as $track): ?>
                        <a href="<?= e(url_path(media_public_path($id) . '/' . $track['file'])) ?>" download="<?= e($track['file']) ?>"><?= e($track['label']) ?></a>
                    <?php endforeach; ?>
                </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <div id="play-error" class="muted"></div>
</div>
<script src="<?= e(url_path('/assets/hls.min.js')) ?>"></script>
<script src="<?= e(url_path('/assets/player.js') . '?v=' . filemtime(__DIR__ . '/assets/player.js')) ?>"></script>
</body>
</html>
