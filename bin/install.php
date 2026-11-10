<?php
// Create storage directories and the SQLite database. Run this once per machine.

require dirname(__DIR__) . '/src/bootstrap.php';

foreach (['storage', 'database', 'lock_file'] as $key) {
    if (pathof($key) === '') {
        throw new RuntimeException('Missing path ' . $key);
    }
}

require_media_mount();

foreach (['originals', 'work', 'media'] as $dir) {
    $path = rtrim(pathof('storage'), '/') . '/' . $dir;
    if (!is_dir($path) && !mkdir($path, 0770, true)) {
        throw new RuntimeException('Cannot create ' . $path);
    }
}

// var/ lives next to the app so the database and worker lock stay on local disk.
foreach ([dirname(pathof('database')), dirname(pathof('lock_file'))] as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0770, true)) {
        throw new RuntimeException('Cannot create ' . $dir);
    }
}

init_db();
echo "Database and directories initialized.\n";
