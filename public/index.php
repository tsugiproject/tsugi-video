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
    <link rel="stylesheet" href="<?= e(url_path('/assets/style.css')) ?>">
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
                <tr><th>Title</th><th>Stage</th><th>Progress</th><th>Actions</th></tr>
            </thead>
            <tbody id="jobs">
                <?php foreach ($jobs as $job): ?>
                    <tr>
                        <td><?= e($job['title']) ?><small><?= e($job['original_name']) ?></small></td>
                        <td><?= e($job['status'] . ' / ' . $job['stage']) ?></td>
                        <td><?= round((float) $job['progress']) ?>%</td>
                        <td>
                            <?php if ($job['status'] === 'completed'): ?>
                                <a href="<?= e(url_path('/watch/' . $job['id'])) ?>">Watch</a>
                                ·
                                <a href="<?= e(url_path('/embed/' . $job['id'])) ?>">Embed</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</main>
<script>window.VIDEO_BASE=<?= json_encode(base_path(), JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="<?= e(url_path('/assets/dashboard.js')) ?>"></script>
</body>
</html>
