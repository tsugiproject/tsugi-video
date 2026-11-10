<?php
// Admin dashboard. The job table is refreshed by assets/dashboard.js.

require_once dirname(__DIR__) . '/src/bootstrap.php';

admin_auth();

$jobs = db()->query('SELECT * FROM jobs ORDER BY created_at DESC LIMIT 200')->fetchAll();
$counts = ['queued' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0];
foreach ($counts as $name => $_) {
    $statement = db()->prepare('SELECT COUNT(*) FROM jobs WHERE status=?');
    $statement->execute([$name]);
    $counts[$name] = (int) $statement->fetchColumn();
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Video Server · Dashboard</title>
    <link rel="stylesheet" href="<?= e(url_path('/assets/style.css')) ?>?v=<?= (int) filemtime(__DIR__ . '/assets/style.css') ?>">
</head>
<body>
<main>
    <header>
        <h1>Video Server</h1>
        <span class="muted"><?= e(rtrim(cfg()['base_url'], '/')) ?> · <a href="<?= e(url_path('/api-docs.php')) ?>">API</a></span>
        <p class="muted sysinfo"><?= e(server_summary()) ?></p>
    </header>

    <section class="stats">
        <?php foreach ($counts as $name => $count): ?>
            <div class="stat">
                <small><?= e(ucfirst($name)) ?></small>
                <strong id="count-<?= e($name) ?>"><?= $count ?></strong>
            </div>
        <?php endforeach; ?>
    </section>

    <section>
        <h2>Upload a video</h2>
        <form id="upload" enctype="multipart/form-data">
            <div class="form-row">
                <label class="browse">Browse
                    <input type="file" name="file" accept="video/*" required>
                </label>
                <span id="file-name" class="muted">No file chosen</span>
                <label class="check"><input type="checkbox" name="captions" value="1" checked> Generate Whisper captions</label>
            </div>
            <div class="form-row">
                <label>Optional title <input name="title" placeholder="Optional title"></label>
                <label class="suggest">Collection
                    <input name="collection" autocomplete="off" placeholder="PY4E" aria-autocomplete="list">
                    <ul class="suggestions" hidden></ul>
                </label>
                <label class="suggest">Logical key
                    <input name="logical_key" autocomplete="off" placeholder="01_02_Intro" aria-autocomplete="list">
                    <ul class="suggestions" hidden></ul>
                </label>
            </div>
            <div class="form-row">
                <button type="submit">Upload and enqueue</button>
                <output id="upload-status"></output>
            </div>
            <p class="muted note">If you upload a video with the same collection and logical key, a watch or embed address that uses that collection and key plays the new video. The original and the new video both remain playable by their SHA-256 address.</p>
        </form>
    </section>

    <section>
        <h2>Jobs <small class="muted">refreshes every 5 seconds</small></h2>
        <table>
            <thead>
                <tr><th>Title</th><th>Stage</th><th>Progress</th><th>When</th><th>Actions</th></tr>
            </thead>
            <tbody id="jobs">
                <?php foreach ($jobs as $job): ?>
                    <tr>
                        <td><?= e($job['title']) ?><small><?= e($job['original_name']) ?></small><small class="sha"><?= e($job['id']) ?></small><?php foreach (job_placements($job['id']) as $slot): ?><small><a href="<?= e(url_path('/watch/' . rawurlencode($slot['collection']) . '/' . rawurlencode($slot['logical_key']))) ?>" target="_blank" rel="noopener"><?= e($slot['collection'] . ' / ' . $slot['logical_key']) ?></a></small><?php endforeach; ?></td>
                        <td><?= e($job['status'] . ' / ' . $job['stage']) ?><?php $line = job_log_tail($job['id']); if ($line): ?><small class="log-line"><?= e($line) ?></small><?php endif; ?></td>
                        <td><?= round((float) $job['progress']) ?>%</td>
                        <td class="dates">
                            <small>Uploaded <time data-utc="<?= e((string) $job['created_at']) ?>"><?= e((string) $job['created_at']) ?></time></small>
                            <small>Started <time data-utc="<?= e((string) ($job['started_at'] ?? '')) ?>"><?= e($job['started_at'] ?: '—') ?></time></small>
                            <small>Ended <time data-utc="<?= e((string) ($job['finished_at'] ?? '')) ?>"><?= e($job['finished_at'] ?: '—') ?></time></small>
                        </td>
                        <td>
                            <?php if ($job['status'] === 'completed'): ?>
                                <a href="<?= e(url_path('/watch/' . $job['id'])) ?>" target="_blank" rel="noopener">Watch</a>
                                ·
                                <a href="<?= e(url_path('/embed/' . $job['id'])) ?>" target="_blank" rel="noopener">Embed</a>
                                ·
                            <?php endif; ?>
                            <a href="<?= e(url_path('/api/jobs/' . $job['id'] . '/log')) ?>" target="_blank" rel="noopener">Log</a>
                            <?php if ($job['status'] !== 'processing'): ?>
                                ·
                                <button type="button" data-delete="<?= e($job['id']) ?>" data-title="<?= e($job['title']) ?>">Delete</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</main>
<script>window.VIDEO_BASE=<?= json_encode(base_path(), JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="<?= e(url_path('/assets/dashboard.js')) ?>?v=<?= (int) filemtime(__DIR__ . '/assets/dashboard.js') ?>"></script>
</body>
</html>
