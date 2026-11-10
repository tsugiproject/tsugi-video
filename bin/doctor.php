<?php
// Print whether this machine can process a video. Warnings still exit non-zero.

require dirname(__DIR__) . '/src/bootstrap.php';

$errors = 0;

foreach (['pdo_sqlite', 'fileinfo'] as $extension) {
    $ok = extension_loaded($extension);
    echo ($ok ? 'OK' : 'FAIL') . " PHP $extension\n";
    if (!$ok) {
        $errors++;
    }
}

foreach (['storage', 'database', 'lock_file'] as $key) {
    echo $key . ': ' . pathof($key) . "\n";
}

foreach (['ffmpeg', 'ffprobe', 'whisper'] as $key) {
    $binary = pathof($key);
    $command = 'command -v ' . escapeshellarg($binary) . ' 2>/dev/null';
    $ok = is_executable($binary) || (bool) trim((string) shell_exec($command));
    echo ($ok ? 'OK' : 'WARN') . " $key: $binary\n";
    if (!$ok) {
        $errors++;
    }
}

$model = pathof('whisper_model');
$ok = is_file($model);
echo ($ok ? 'OK' : 'WARN') . " model: $model\n";

try {
    require_media_mount();
    echo "OK required media mount\n";
} catch (Throwable $error) {
    echo 'WARN ' . $error->getMessage() . "\n";
    $errors++;
}

$storage = pathof('storage');
$writable = is_dir($storage) && is_writable($storage);
echo ($writable ? 'OK' : 'WARN') . " storage writable\n";

$inbox = pathof('inbox');
if ($inbox === '') {
    echo "inbox: not configured\n";
} else {
    echo (is_dir($inbox) ? 'OK' : 'WARN') . " inbox: $inbox\n";
}

exit($errors ? 1 : 0);
