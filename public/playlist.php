<?php
// Public collection playlist. /watch/{collection} plays a chosen video in the page.
// /watch/{collection}.json is the same list for a course picker.

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
    exit('Method not allowed');
}

$wantsJson = ($_GET['format'] ?? '') === 'json';
$playlist = collection_playlist((string) ($_GET['collection'] ?? ''));
if (!$playlist) {
    if ($wantsJson) {
        header('Access-Control-Allow-Origin: *');
        fail('Not found', 404);
    }
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    exit('Not found');
}

if ($wantsJson) {
    $videos = [];
    foreach ($playlist['videos'] as $video) {
        unset($video['embed_path'], $video['duration_label']);
        $videos[] = $video;
    }
    header('Access-Control-Allow-Origin: *');
    json_out([
        'collection' => $playlist['collection'],
        'videos' => $videos,
    ]);
}

$videos = $playlist['videos'];
$selected = 0;
$requested = trim((string) ($_GET['v'] ?? ''));
if ($requested !== '') {
    foreach ($videos as $index => $video) {
        if (strcasecmp($video['logical_key'], $requested) === 0) {
            $selected = $index;
            break;
        }
    }
}
$current = $videos[$selected] ?? null;
$count = count($videos);
$jsonPath = url_path('/watch/' . rawurlencode($playlist['collection']) . '.json');
$client = [
    'collection' => $playlist['collection'],
    'videos' => array_map(static function (array $video): array {
        return [
            'title' => $video['display_title'],
            'description' => $video['description'] ?? '',
            'logical_key' => $video['logical_key'],
            'embed_path' => $video['embed_path'],
        ];
    }, $videos),
];

header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; frame-src 'self'; media-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors *;");
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($current['display_title'] ?? $playlist['collection']) ?></title>
    <link rel="stylesheet" href="<?= e(url_path('/assets/style.css') . '?v=' . (int) filemtime(__DIR__ . '/assets/style.css')) ?>">
</head>
<body class="playlist-page">
<div class="playlist-layout">
    <div class="playlist-main">
        <?php if ($current): ?>
            <div class="playlist-frame">
                <iframe id="playlist-player" src="<?= e($current['embed_path']) ?>" title="<?= e($current['display_title']) ?>" allow="fullscreen" allowfullscreen></iframe>
            </div>
            <div class="playlist-now">
                <h1 id="playlist-title"><?= e($current['display_title']) ?></h1>
                <p class="muted" id="playlist-key"><?= e($current['logical_key']) ?></p>
                <p class="playlist-description" id="playlist-description"><?= e((string) ($current['description'] ?? '')) ?></p>
            </div>
        <?php else: ?>
            <p class="playlist-empty">No videos in this collection are ready to play.</p>
        <?php endif; ?>
    </div>
    <aside class="playlist-panel" aria-label="<?= e('Videos in ' . $playlist['collection']) ?>">
        <header>
            <h2><?= e($playlist['collection']) ?></h2>
            <p class="muted"><?= e($count === 1 ? '1 video' : $count . ' videos') ?> · <a href="<?= e($jsonPath) ?>">JSON</a></p>
        </header>
        <?php if ($videos !== []): ?>
            <ol id="playlist">
                <?php foreach ($videos as $index => $video): ?>
                    <li>
                        <button type="button" data-index="<?= e((string) $index) ?>"<?= $index === $selected ? ' aria-current="true"' : '' ?>>
                            <span class="playlist-index"><?= e((string) ($index + 1)) ?></span>
                            <span class="playlist-meta">
                                <span class="playlist-name"><?= e($video['display_title']) ?></span>
                                <span class="playlist-sub"><?= e($video['logical_key'] . ($video['duration_label'] !== '' ? ' · ' . $video['duration_label'] : '')) ?></span>
                            </span>
                        </button>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </aside>
</div>
<?php if ($videos !== []): ?>
    <script id="playlist-data" type="application/json"><?= json_encode($client, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
    <script src="<?= e(url_path('/assets/playlist.js') . '?v=' . (int) filemtime(__DIR__ . '/assets/playlist.js')) ?>"></script>
<?php endif; ?>
</body>
</html>
