<?php
declare(strict_types=1);
function cfg(): array {
    static $config = null;
    if ($config === null) {
        $path = dirname(__DIR__) . '/config.php';
        if (!is_file($path)) throw new RuntimeException('Missing config.php; copy config.example.php');
        $config = require $path;
    }
    return $config;
}
function pathof(string $key): string { return (string)(cfg()['paths'][$key] ?? ''); }
function base_path(): string {
    $path=parse_url((string)cfg()['base_url'], PHP_URL_PATH);
    return rtrim(is_string($path) ? $path : '', '/');
}
function url_path(string $path): string {
    return base_path().'/'.ltrim($path, '/');
}
function request_path(): string {
    $path=parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $base=base_path();
    if ($base !== '' && ($path === $base || str_starts_with($path, $base.'/'))) {
        $path=substr($path, strlen($base)) ?: '/';
    }
    if (str_starts_with($path, '/public/')) $path=substr($path, 7) ?: '/';
    return $path;
}
function require_media_mount(): void {
    $required=cfg()['paths']['required_mount'] ?? null;
    if ($required === null || $required === '') return;
    $mount=realpath((string)$required);
    if ($mount === false || !is_dir($mount)) throw new RuntimeException('Required media mount is missing: '.$required);
    $parent=dirname($mount); $a=stat($mount);$b=stat($parent);
    if (!$a || !$b || $a['dev'] === $b['dev']) throw new RuntimeException('Media mount is not mounted: '.$required);
    $storage=realpath(pathof('storage'));
    if ($storage === false || ($storage !== $mount && !str_starts_with($storage,rtrim($mount,'/').'/')))
        throw new RuntimeException('Storage directory must be inside required mount: '.$required);
}
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('PHP pdo_sqlite extension required');
        $pdo = new PDO('sqlite:' . pathof('database'));
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA busy_timeout=5000; PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;');
    }
    return $pdo;
}
function init_db(): void { db()->exec(file_get_contents(dirname(__DIR__) . '/sql/schema.sql')); }
function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function json_out($data, int $status=200): never {
    http_response_code($status); header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store'); echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit;
}
function fail(string $error, int $code=400): never { json_out(['error'=>$error],$code); }
function authorization_header(): string {
    foreach ($_SERVER as $key => $value) {
        if (!is_string($value) || $value === '') continue;
        if ($key === 'Authorization' || preg_match('/^(?:REDIRECT_)*HTTP_AUTHORIZATION$/', $key)) return $value;
    }
    return '';
}
function admin_auth(): void {
    $conf=cfg();
    $header=authorization_header();
    $bearer='';
    if (preg_match('/^Bearer\s+(\S+)$/i',$header,$m)) $bearer=$m[1];
    $user=$_SERVER['PHP_AUTH_USER'] ?? null;
    $password=$_SERVER['PHP_AUTH_PW'] ?? null;
    if (($user === null || $password === null) && preg_match('/^Basic\s+(\S+)$/i',$header,$m)) {
        $decoded=base64_decode($m[1], true);
        if ($decoded !== false && str_contains($decoded, ':')) {
            [$user, $password]=explode(':', $decoded, 2);
        }
    }
    $bearerOk = $bearer !== '' && hash_equals((string)$conf['api_token'], $bearer);
    $basicOk = $user !== null && $password !== null
        && hash_equals((string)$conf['admin_user'], (string)$user)
        && hash_equals((string)$conf['admin_password'], (string)$password);
    if (!$bearerOk && !$basicOk) {
        header('WWW-Authenticate: Basic realm="Video Server Admin"');
        fail('Authentication required',401);
    }
    if (str_starts_with((string)$conf['admin_password'],'CHANGE_ME') || str_starts_with((string)$conf['api_token'],'CHANGE_ME')) fail('Change default credentials in config.php',503);
}
function check_csrf(): void {
    // Basic credentials are ambient in the browser; enforce same-origin for browser writes.
    $origin=$_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        $expected=cfg()['base_url'];
        $a=parse_url($origin); $b=parse_url($expected);
        if (($a['host'] ?? '') !== ($b['host'] ?? '') || ($a['scheme'] ?? '') !== ($b['scheme'] ?? '') || ($a['port'] ?? null) !== ($b['port'] ?? null)) fail('Cross-origin write blocked',403);
    }
    $type=$_SERVER['CONTENT_TYPE'] ?? '';
    if (!str_starts_with($type,'application/json') && !str_starts_with($type,'multipart/form-data')) fail('JSON or multipart required',415);
}
function generate_id(): string { return bin2hex(random_bytes(12)); }
function job_by_id(string $id): ?array {
    $s=db()->prepare('SELECT * FROM jobs WHERE id=?'); $s->execute([$id]); return $s->fetch() ?: null;
}
function public_job(array $j): array {
    $url=rtrim(cfg()['base_url'],'/');
    return ['id'=>$j['id'],'title'=>$j['title'],'original_name'=>$j['original_name'],
        'status'=>$j['status'],'stage'=>$j['stage'],'progress'=>(float)$j['progress'],
        'duration'=>$j['duration']===null?null:(float)$j['duration'], 'error'=>$j['error'],
        'created_at'=>$j['created_at'],'started_at'=>$j['started_at'],'finished_at'=>$j['finished_at'],
        'embed_url'=>$url.'/embed/'.$j['id'],'watch_url'=>$url.'/watch/'.$j['id'],
        'hls_url'=>$j['status']==='completed'?$url.'/media/'.$j['id'].'/index.m3u8':null];
}
function create_job(string $source, string $original, string $title, bool $captions): array {
    require_media_mount();
    $id=generate_id();
    $targetDir=rtrim(pathof('storage'),'/').'/originals/'.$id;
    if (!is_dir($targetDir) && !mkdir($targetDir,0770,true)) throw new RuntimeException('Could not create original directory');
    $ext=strtolower(pathinfo($original,PATHINFO_EXTENSION));
    if (!in_array($ext,['mp4','m4v','mov','webm','mkv','avi','mpg','mpeg'],true)) $ext='bin';
    $dest=$targetDir.'/source.'.$ext;
    if (!copy($source,$dest)) throw new RuntimeException('Could not import source file');
    $q=db()->prepare('INSERT INTO jobs (id,title,original_name,source_path,captions) VALUES (?,?,?,?,?)');
    try { $q->execute([$id,$title,$original,$dest,$captions?1:0]); }
    catch (Throwable $t) { @unlink($dest); throw $t; }
    return job_by_id($id);
}
function safe_import(string $given): string {
    $real=realpath($given);
    if ($real === false || !is_file($real) || !is_readable($real)) throw new InvalidArgumentException('Source file missing or unreadable');
    foreach (cfg()['paths']['imports'] as $root) {
        $prefix=realpath($root);
        if ($prefix !== false && str_starts_with($real, rtrim($prefix,'/').'/')) return $real;
    }
    throw new InvalidArgumentException('Source must be within a configured import directory');
}
function update_job(string $id, string $status, string $stage, float $percent, ?string $error=null): void {
    $s=db()->prepare('UPDATE jobs SET status=?, stage=?, progress=?, error=?, updated_at=CURRENT_TIMESTAMP WHERE id=?');
    $s->execute([$status,$stage,max(0,min(100,$percent)),$error,$id]);
}
