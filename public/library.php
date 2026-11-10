<?php
// Library of every video. The dashboard keeps the live queue.

require_once dirname(__DIR__) . '/src/bootstrap.php';

admin_auth();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Video Server · Library</title>
    <link rel="stylesheet" href="<?= e(url_path('/assets/style.css')) ?>?v=<?= (int) filemtime(__DIR__ . '/assets/style.css') ?>">
</head>
<body>
<main>
    <header>
        <h1>Library</h1>
        <span class="muted"><a href="<?= e(url_path('/')) ?>">Dashboard</a> · <a href="<?= e(url_path('/api-docs.php')) ?>">API</a></span>
    </header>

    <section>
        <div class="form-row library-tools">
            <label>Collection
                <select id="library-collection">
                    <option value="">All videos</option>
                </select>
            </label>
            <label>Search
                <input id="library-search" type="search" placeholder="Title, file, key, or SHA-256">
            </label>
            <label>Sort
                <select id="library-sort">
                    <option value="title:asc">Title</option>
                    <option value="title:desc">Title, reverse</option>
                    <option value="created:desc">Newest</option>
                    <option value="created:asc">Oldest</option>
                    <option value="collection:asc">Collection</option>
                </select>
            </label>
        </div>
        <table>
            <thead>
                <tr><th>Title</th><th>Collection</th><th>Status</th><th>Completed</th><th></th></tr>
            </thead>
            <tbody id="library"></tbody>
        </table>
        <p class="pager">
            <button type="button" class="texty" id="library-prev">Previous</button>
            <span id="library-page" class="muted"></span>
            <button type="button" class="texty" id="library-next">Next</button>
        </p>
    </section>
</main>
<script>window.VIDEO_BASE=<?= json_encode(base_path(), JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="<?= e(url_path('/assets/library.js')) ?>?v=<?= (int) filemtime(__DIR__ . '/assets/library.js') ?>"></script>
</body>
</html>
