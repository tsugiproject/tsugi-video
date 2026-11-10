<?php
// Admin dashboard. The job table is refreshed by assets/dashboard.js.

require_once dirname(__DIR__) . '/src/bootstrap.php';

admin_auth();

$recent = list_jobs(['limit' => 10, 'offset' => 0, 'sort' => 'created', 'dir' => 'desc']);
$jobs = $recent['jobs'];
$jobTotal = $recent['total'];
$counts = job_counts();
$incoming = list_import_files();
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
        <span class="muted"><?php foreach (configured_base_urls() as $baseUrl): ?><span><?= e($baseUrl) ?></span> · <?php endforeach; ?><a href="<?= e(url_path('/library.php')) ?>">Library</a> · <a href="<?= e(url_path('/api-docs.php')) ?>">API</a></span>
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
        <h2>Add a video</h2>
        <form id="upload" enctype="multipart/form-data">
            <div class="form-row">
                <label class="browse">Browse
                    <input type="file" name="file" accept="video/*">
                </label>
                <span id="file-name" class="muted">No file chosen</span>
                <label>Incoming file
                    <select id="incoming" name="incoming">
                        <option value=""><?= $incoming ? 'Choose a file already on the server' : 'No files in the incoming folder' ?></option>
                        <?php foreach ($incoming as $file): ?>
                            <option value="<?= e($file['path']) ?>"><?= e($file['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
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
                <button type="submit" id="enqueue">Upload and enqueue</button>
                <output id="upload-status"></output>
            </div>
            <p class="muted note">The incoming list is every file in the configured import folders. Enqueueing one copies it into storage and leaves the original file where it is. If you add a video with the same collection and logical key, a watch or embed address that uses that collection and key plays the new video. The original and the new video both remain playable by their SHA-256 address.</p>
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
                        <td><a href="<?= e(url_path('/job.php') . '?id=' . $job['id']) ?>"><?= e(display_title($job)) ?></a><small><?= e($job['original_name']) ?></small><small class="sha"><?= e($job['id']) ?></small><?php foreach (job_placements($job['id']) as $slot): ?><small class="nowrap"><a href="<?= e(url_path('/watch/' . rawurlencode($slot['collection']) . '/' . rawurlencode($slot['logical_key']))) ?>" target="_blank" rel="noopener"><?= e($slot['collection'] . ' / ' . $slot['logical_key']) ?></a><button type="button" class="copy" data-copy="<?= e($slot['embed_url']) ?>" title="Copy embed link" aria-label="Copy embed link"><svg class="icon-copy" viewBox="0 0 16 16" aria-hidden="true"><path fill="currentColor" d="M4 1.5A1.5 1.5 0 0 1 5.5 0h6A1.5 1.5 0 0 1 13 1.5v8A1.5 1.5 0 0 1 11.5 11h-6A1.5 1.5 0 0 1 4 9.5v-8zM5.5 1a.5.5 0 0 0-.5.5v8a.5.5 0 0 0 .5.5h6a.5.5 0 0 0 .5-.5v-8a.5.5 0 0 0-.5-.5h-6z"/><path fill="currentColor" d="M2 4.5A1.5 1.5 0 0 1 3.5 3H4v1h-.5a.5.5 0 0 0-.5.5v8a.5.5 0 0 0 .5.5h6a.5.5 0 0 0 .5-.5V12h1v.5A1.5 1.5 0 0 1 9.5 14h-6A1.5 1.5 0 0 1 2 12.5v-8z"/></svg><svg class="icon-check" viewBox="0 0 16 16" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M3 8.5 6.2 11.5 13 4.5"/></svg></button></small><?php endforeach; ?></td>
                        <td<?= $job['status'] === 'failed' ? ' class="status-failed"' : '' ?>><?= e($job['status'] . ' / ' . $job['stage']) ?><?php $line = job_log_tail($job['id']); if ($line): ?><small class="log-line"><?= e($line) ?></small><?php endif; ?></td>
                        <td><?= round((float) $job['progress']) ?>%</td>
                        <td class="dates">
                            <small>Uploaded <time data-utc="<?= e((string) $job['created_at']) ?>"><?= e((string) $job['created_at']) ?></time></small>
                            <small>Started <time data-utc="<?= e((string) ($job['started_at'] ?? '')) ?>"><?= e($job['started_at'] ?: '—') ?></time></small>
                            <small>Ended <time data-utc="<?= e((string) ($job['finished_at'] ?? '')) ?>"><?= e($job['finished_at'] ?: '—') ?></time></small>
                            <small>Encode <?= e(format_elapsed(encode_seconds($job))) ?></small>
                        </td>
                        <td>
                            <?php if ($job['status'] === 'completed'): ?>
                                <a href="<?= e(url_path('/watch/' . $job['id'])) ?>" target="_blank" rel="noopener">Watch</a>
                                ·
                                <span class="nowrap"><a href="<?= e(url_path('/embed/' . $job['id'])) ?>" target="_blank" rel="noopener">Embed</a><button type="button" class="copy" data-copy="<?= e(public_base_url() . '/embed/' . $job['id']) ?>" title="Copy embed link" aria-label="Copy embed link"><svg class="icon-copy" viewBox="0 0 16 16" aria-hidden="true"><path fill="currentColor" d="M4 1.5A1.5 1.5 0 0 1 5.5 0h6A1.5 1.5 0 0 1 13 1.5v8A1.5 1.5 0 0 1 11.5 11h-6A1.5 1.5 0 0 1 4 9.5v-8zM5.5 1a.5.5 0 0 0-.5.5v8a.5.5 0 0 0 .5.5h6a.5.5 0 0 0 .5-.5v-8a.5.5 0 0 0-.5-.5h-6z"/><path fill="currentColor" d="M2 4.5A1.5 1.5 0 0 1 3.5 3H4v1h-.5a.5.5 0 0 0-.5.5v8a.5.5 0 0 0 .5.5h6a.5.5 0 0 0 .5-.5V12h1v.5A1.5 1.5 0 0 1 9.5 14h-6A1.5 1.5 0 0 1 2 12.5v-8z"/></svg><svg class="icon-check" viewBox="0 0 16 16" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M3 8.5 6.2 11.5 13 4.5"/></svg></button></span>
                                ·
                                <a href="<?= e(url_path('/captions.php') . '?id=' . $job['id']) ?>">Captions</a>
                                ·
                            <?php endif; ?>
                            <a href="<?= e(url_path('/job.php') . '?id=' . $job['id']) ?>">Details</a>
                            ·
                            <a href="<?= e(url_path('/api/jobs/' . $job['id'] . '/log')) ?>" target="_blank" rel="noopener">Log</a>
                            <?php if ($job['status'] !== 'processing'): ?>
                                ·
                                <button type="button" data-delete="<?= e($job['id']) ?>" data-title="<?= e(display_title($job)) ?>">Delete</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="pager"><button type="button" class="texty" id="jobs-more"<?= $jobTotal > count($jobs) ? '' : ' hidden' ?>>More</button></p>
    </section>
</main>
<script>window.VIDEO_BASE=<?= json_encode(base_path(), JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="<?= e(url_path('/assets/dashboard.js')) ?>?v=<?= (int) filemtime(__DIR__ . '/assets/dashboard.js') ?>"></script>
</body>
</html>
