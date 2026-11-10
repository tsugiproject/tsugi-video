<?php
// One video: status, placements, captions, renditions, and the recent log.

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
$active = $job['status'] === 'queued' || $job['status'] === 'processing';
$placements = job_placements($id);
$tracks = $ready ? caption_tracks($id) : [];
$renditions = [];
if ($ready) {
    $media = content_dir('media', $id);
    if (is_dir($media)) {
        foreach (scandir($media) ?: [] as $name) {
            if (preg_match('/^[0-9]{2,4}$/', $name) && is_file($media . '/' . $name . '/index.m3u8')) {
                $renditions[] = $name;
            }
        }
        rsort($renditions, SORT_NUMERIC);
    }
}
$logPath = dirname(pathof('database')) . '/logs/' . $id . '.log';
$log = '';
if (is_file($logPath)) {
    $text = file_get_contents($logPath);
    if (is_string($text) && $text !== '') {
        $lines = preg_split("/\r\n|\n|\r/", rtrim($text, "\r\n")) ?: [];
        if (count($lines) > 80) {
            $lines = array_slice($lines, -80);
        }
        $log = implode("\n", $lines);
    }
}
$seconds = $job['duration'] === null ? null : (float) $job['duration'];
$clock = '—';
if ($seconds !== null && $seconds > 0) {
    $total = (int) round($seconds);
    $hours = intdiv($total, 3600);
    $minutes = intdiv($total % 3600, 60);
    $clock = $hours > 0
        ? sprintf('%d:%02d:%02d', $hours, $minutes, $total % 60)
        : sprintf('%d:%02d', $minutes, $total % 60);
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php if ($active): ?><meta http-equiv="refresh" content="5"><?php endif; ?>
    <title>Video Server · <?= e(display_title($job)) ?></title>
    <link rel="stylesheet" href="<?= e(url_path('/assets/style.css')) ?>?v=<?= (int) filemtime(__DIR__ . '/assets/style.css') ?>">
</head>
<body>
<main>
    <header>
        <h1>Video</h1>
        <span class="muted"><a href="<?= e(url_path('/')) ?>">Dashboard</a> · <a href="<?= e(url_path('/library.php')) ?>">Library</a></span>
    </header>

    <section>
        <h2><?= e(display_title($job)) ?></h2>
        <p class="muted sha"><?= e($id) ?></p>
        <table class="facts">
            <tr><th>File</th><td><?= e((string) $job['original_name']) ?></td></tr>
            <tr><th>Status</th><td<?= $job['status'] === 'failed' ? ' class="status-failed"' : '' ?>><?= e((string) $job['status']) ?></td></tr>
            <tr><th>Stage</th><td><?= e((string) $job['stage']) ?></td></tr>
            <tr><th>Progress</th><td><?= round((float) $job['progress']) ?>%<div class="progress"><span style="width:<?= e((string) max(0, min(100, (float) $job['progress']))) ?>%"></span></div></td></tr>
            <tr><th>Duration</th><td><?= e($clock) ?></td></tr>
            <?php if ((string) ($job['error'] ?? '') !== ''): ?>
                <tr><th>Error</th><td class="status-failed"><?= e((string) $job['error']) ?></td></tr>
            <?php endif; ?>
            <tr><th>Uploaded</th><td><time data-utc="<?= e((string) $job['created_at']) ?>"><?= e((string) $job['created_at']) ?></time></td></tr>
            <tr><th>Started</th><td><time data-utc="<?= e((string) ($job['started_at'] ?? '')) ?>"><?= e($job['started_at'] ?: '—') ?></time></td></tr>
            <tr><th>Ended</th><td><time data-utc="<?= e((string) ($job['finished_at'] ?? '')) ?>"><?= e($job['finished_at'] ?: '—') ?></time></td></tr>
            <tr><th>Encode time</th><td><?= e(format_elapsed(encode_seconds($job))) ?></td></tr>
            <tr>
                <th>Logical key link</th>
                <td>
                    <?php if ($placements === []): ?>
                        —
                    <?php else: ?>
                        <?php foreach ($placements as $slot): ?>
                            <div class="nowrap"><?= e('(' . $slot['collection'] . ', ' . $slot['logical_key'] . ')') ?><?php if ($ready): ?> <a href="<?= e($slot['watch_url']) ?>" target="_blank" rel="noopener">Watch</a> · <a href="<?= e($slot['embed_url']) ?>" target="_blank" rel="noopener">Embed</a> / <button type="button" class="texty" data-copy="<?= e($slot['embed_url']) ?>">Copy embed</button><?php endif; ?></div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th>SHA-256 link</th>
                <td>
                    <?php if ($ready): ?>
                        <span class="nowrap"><a href="<?= e(public_url('/watch/' . $id)) ?>" target="_blank" rel="noopener">Watch</a> · <a href="<?= e(public_url('/embed/' . $id)) ?>" target="_blank" rel="noopener">Embed</a> / <button type="button" class="texty" data-copy="<?= e(public_url('/embed/' . $id)) ?>">Copy embed</button></span>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </td>
            </tr>
            <?php if ($ready): ?>
                <tr>
                    <th>Playlist</th>
                    <td><a href="<?= e(url_path(media_public_path($id) . '/index.m3u8')) ?>" target="_blank" rel="noopener">index.m3u8</a></td>
                </tr>
                <tr>
                    <th>Sizes</th>
                    <td><?= $renditions === [] ? '—' : e(implode(', ', array_map(static fn (string $height): string => $height . 'p', $renditions))) ?></td>
                </tr>
                <tr>
                    <th>Captions</th>
                    <td>
                        <?php if ($tracks === []): ?>
                            None
                        <?php else: ?>
                            <?php foreach ($tracks as $track): ?>
                                <small class="nowrap"><?= e($track['label']) ?> <a href="<?= e(url_path(media_public_path($id) . '/' . $track['file'])) ?>" download="<?= e($track['file']) ?>">Download</a></small>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endif; ?>
        </table>
        <p class="actions">
            <?php if ($ready): ?>
                <a href="<?= e(url_path('/captions.php') . '?id=' . $id) ?>">Manage Captions</a>
                ·
            <?php endif; ?>
            <?php if ($job['status'] === 'failed'): ?>
                <button type="button" id="retry">Retry</button>
                ·
            <?php endif; ?>
            <a href="<?= e(url_path('/api/jobs/' . $id . '/log')) ?>" target="_blank" rel="noopener">Log</a>
            <?php if ($job['status'] !== 'processing'): ?>
                ·
                <button type="button" id="delete" data-title="<?= e(display_title($job)) ?>">Delete</button>
            <?php endif; ?>
        </p>
        <?php if ($active): ?><p class="muted">This page refreshes every 5 seconds while the job is queued or processing.</p><?php endif; ?>
    </section>

    <section>
        <h2>Recent log</h2>
        <?php if ($log === ''): ?>
            <p class="muted">No log yet.</p>
        <?php else: ?>
            <pre class="logbox"><?= e($log) ?></pre>
        <?php endif; ?>
    </section>
</main>
<script>window.VIDEO_BASE=<?= json_encode(base_path(), JSON_UNESCAPED_SLASHES) ?>;window.JOB_ID=<?= json_encode($id) ?>;</script>
<script src="<?= e(url_path('/assets/job.js')) ?>?v=<?= (int) filemtime(__DIR__ . '/assets/job.js') ?>"></script>
</body>
</html>
