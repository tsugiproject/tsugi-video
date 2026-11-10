<?php
require dirname(__DIR__).'/src/bootstrap.php';
$errors=0;
foreach (['pdo_sqlite','fileinfo'] as $ext) { $ok=extension_loaded($ext);echo ($ok?'OK':'FAIL')." PHP $ext\n";if(!$ok)$errors++; }
foreach (['storage','database','lock_file'] as $key) { $val=pathof($key);echo "$key: $val\n"; }
foreach (['ffmpeg','ffprobe','whisper'] as $key) {
 $bin=pathof($key);$command='command -v '.escapeshellarg($bin).' 2>/dev/null';
 $ok=is_executable($bin) || (bool)trim((string)shell_exec($command));
 echo ($ok?'OK':'WARN')." $key: $bin\n"; if(!$ok)$errors++;
}
$model=pathof('whisper_model');$ok=is_file($model);echo ($ok?'OK':'WARN')." model: $model\n";
try {require_media_mount(); echo "OK required media mount\n";}catch(Throwable $e){echo "WARN ".$e->getMessage()."\n"; $errors++;}
$storage=pathof('storage');echo (is_dir($storage)&&is_writable($storage)?'OK':'WARN')." storage writable\n";
exit($errors?1:0);
