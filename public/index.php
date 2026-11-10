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
        <span class="muted"><?= e(rtrim(cfg()['base_url'], '/')) ?></span>
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
            <label>Video file <input type="file" name="file" accept="video/*" required></label>
            <label>Title <input name="title" placeholder="Optional title"></label>
            <label><input type="checkbox" name="captions" value="1" checked> Generate Whisper captions</label>
            <button type="submit">Upload and enqueue</button>
            <output id="upload-status"></output>
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
                        <td><?= e($job['title']) ?><small><?= e($job['original_name']) ?></small></td>
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
