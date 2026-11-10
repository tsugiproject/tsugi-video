<?php
// Upload or replace WebVTT tracks for one published video.

require_once dirname(__DIR__) . '/src/bootstrap.php';

admin_auth();

$id = (string) ($_GET['id'] ?? '');
if (!is_content_id($id)) {
    http_response_code(404);
    exit('Not found');
}
$job = job_by_id($id);
if (!$job) {
    http_response_code(404);
    exit('Not found');
}

$ready = $job['status'] === 'completed';
$tracks = $ready ? caption_tracks($id) : [];
$names = caption_language_names();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Video Server · Captions</title>
    <link rel="stylesheet" href="<?= e(url_path('/assets/style.css')) ?>?v=<?= (int) filemtime(__DIR__ . '/assets/style.css') ?>">
</head>
<body>
<main>
    <header>
        <h1>Captions</h1>
        <span class="muted"><a href="<?= e(url_path('/')) ?>">Dashboard</a> · <a href="<?= e(url_path('/library.php')) ?>">Library</a><?php if ($ready): ?> · <a href="<?= e(url_path('/watch/' . $id)) ?>" target="_blank" rel="noopener">Watch</a><?php endif; ?></span>
    </header>

    <section>
        <h2><?= e(display_title($job)) ?></h2>
        <p class="muted sha"><?= e($id) ?></p>
        <?php if (!$ready): ?>
            <p>This video is still <?= e($job['status']) ?>. Captions can be added after it is published.</p>
        <?php else: ?>
            <p class="muted">Upload a WebVTT file. English replaces the track already on the video. Any other language is added as its own track, and uploading that language again replaces it. Keep the cue times from the original track.</p>
            <table>
                <thead>
                    <tr><th>Language</th><th>File</th><th></th></tr>
                </thead>
                <tbody id="tracks">
                    <?php if ($tracks === []): ?>
                        <tr><td class="muted" colspan="3">No captions yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($tracks as $track): ?>
                        <tr>
                            <td><?= e($track['label']) ?> <small><?= e($track['language']) ?></small></td>
                            <td><a href="<?= e(url_path(media_public_path($id) . '/' . $track['file'])) ?>" target="_blank" rel="noopener"><?= e($track['file']) ?></a></td>
                            <td><button type="button" data-remove="<?= e($track['language']) ?>" data-label="<?= e($track['label']) ?>">Remove</button></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <form id="caption-upload">
                <label>Language
                    <input name="language" list="languages" value="en" required pattern="[A-Za-z]{2,3}" autocomplete="off">
                </label>
                <datalist id="languages">
                    <?php foreach ($names as $code => $label): ?>
                        <option value="<?= e($code) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </datalist>
                <label class="browse">Browse
                    <input type="file" name="file" accept=".vtt,text/vtt" required>
                </label>
                <span id="caption-file" class="muted">No file chosen</span>
                <button type="submit">Upload captions</button>
                <output id="caption-status"></output>
            </form>
        <?php endif; ?>
    </section>
</main>
<script>window.VIDEO_BASE=<?= json_encode(base_path(), JSON_UNESCAPED_SLASHES) ?>;window.CAPTION_JOB=<?= json_encode($id) ?>;window.CAPTION_NAMES=<?= json_encode($names, JSON_UNESCAPED_UNICODE) ?>;</script>
<?php if ($ready): ?>
<script src="<?= e(url_path('/assets/captions.js')) ?>?v=<?= (int) filemtime(__DIR__ . '/assets/captions.js') ?>"></script>
<?php endif; ?>
</body>
</html>
