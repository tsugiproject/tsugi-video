<?php
declare(strict_types=1);

// Shared helpers for the dashboard, API, player, and CLI worker.

function cfg(): array
{
    static $config = null;
    if ($config === null) {
        $path = dirname(__DIR__) . '/config.php';
        if (!is_file($path)) {
            throw new RuntimeException('Missing config.php; copy config.example.php');
        }
        $config = require $path;
    }
    return $config;
}

function pathof(string $key): string
{
    return (string) (cfg()['paths'][$key] ?? '');
}

// URL path of this install, e.g. "/tsugi-video" under MAMP or "" at the vhost root.
function base_path(): string
{
    $path = parse_url((string) cfg()['base_url'], PHP_URL_PATH);
    return rtrim(is_string($path) ? $path : '', '/');
}

function url_path(string $path): string
{
    return base_path() . '/' . ltrim($path, '/');
}

// Request path with the install prefix and an internal /public rewrite removed.
function request_path(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $base = base_path();
    if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
        $path = substr($path, strlen($base)) ?: '/';
    }
    if (str_starts_with($path, '/public/')) {
        $path = substr($path, 7) ?: '/';
    }
    return $path;
}

// Refuse to read or publish media unless storage is on a real mounted volume.
// macOS recreates a missing USB mountpoint as a normal directory on the boot disk.
function require_media_mount(): void
{
    $required = cfg()['paths']['required_mount'] ?? null;
    if ($required === null || $required === '') {
        return;
    }

    $mount = realpath((string) $required);
    if ($mount === false || !is_dir($mount)) {
        throw new RuntimeException('Required media mount is missing: ' . $required);
    }

    $parent = dirname($mount);
    $mountStat = stat($mount);
    $parentStat = stat($parent);
    if (!$mountStat || !$parentStat || $mountStat['dev'] === $parentStat['dev']) {
        throw new RuntimeException('Media mount is not mounted: ' . $required);
    }

    $storage = realpath(pathof('storage'));
    $insideMount = $storage === $mount || str_starts_with($storage, rtrim($mount, '/') . '/');
    if ($storage === false || !$insideMount) {
        throw new RuntimeException('Storage directory must be inside required mount: ' . $required);
    }
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        if (!extension_loaded('pdo_sqlite')) {
            throw new RuntimeException('PHP pdo_sqlite extension required');
        }
        $pdo = new PDO('sqlite:' . pathof('database'));
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA busy_timeout=5000; PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;');
    }
    return $pdo;
}

function init_db(): void
{
    db()->exec(file_get_contents(dirname(__DIR__) . '/sql/schema.sql'));
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_out($data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function fail(string $error, int $code = 400): never
{
    json_out(['error' => $error], $code);
}

// MAMP's mod_fastcgi does not fill PHP_AUTH_*. The header survives as
// HTTP_AUTHORIZATION or REDIRECT_HTTP_AUTHORIZATION after an internal rewrite.
function authorization_header(): string
{
    foreach ($_SERVER as $key => $value) {
        if (!is_string($value) || $value === '') {
            continue;
        }
        if ($key === 'Authorization' || preg_match('/^(?:REDIRECT_)*HTTP_AUTHORIZATION$/', $key)) {
            return $value;
        }
    }
    return '';
}

function admin_auth(): void
{
    $conf = cfg();
    $header = authorization_header();

    $bearer = '';
    if (preg_match('/^Bearer\s+(\S+)$/i', $header, $matches)) {
        $bearer = $matches[1];
    }

    $user = $_SERVER['PHP_AUTH_USER'] ?? null;
    $password = $_SERVER['PHP_AUTH_PW'] ?? null;
    if (($user === null || $password === null) && preg_match('/^Basic\s+(\S+)$/i', $header, $matches)) {
        $decoded = base64_decode($matches[1], true);
        if ($decoded !== false && str_contains($decoded, ':')) {
            [$user, $password] = explode(':', $decoded, 2);
        }
    }

    $bearerOk = $bearer !== '' && hash_equals((string) $conf['api_token'], $bearer);
    $basicOk = $user !== null && $password !== null
        && hash_equals((string) $conf['admin_user'], (string) $user)
        && hash_equals((string) $conf['admin_password'], (string) $password);
    if (!$bearerOk && !$basicOk) {
        header('WWW-Authenticate: Basic realm="Video Server Admin"');
        fail('Authentication required', 401);
    }

    $placeholderPassword = str_starts_with((string) $conf['admin_password'], 'CHANGE_ME');
    $placeholderToken = str_starts_with((string) $conf['api_token'], 'CHANGE_ME');
    if ($placeholderPassword || $placeholderToken) {
        fail('Change default credentials in config.php', 503);
    }
}

// Browser Basic credentials are sent automatically, so writes must come from this host.
// A missing Origin is allowed so curl and other API clients still work.
function check_csrf(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        $expected = cfg()['base_url'];
        $originParts = parse_url($origin);
        $expectedParts = parse_url($expected);
        $sameOrigin = ($originParts['host'] ?? '') === ($expectedParts['host'] ?? '')
            && ($originParts['scheme'] ?? '') === ($expectedParts['scheme'] ?? '')
            && ($originParts['port'] ?? null) === ($expectedParts['port'] ?? null);
        if (!$sameOrigin) {
            fail('Cross-origin write blocked', 403);
        }
    }

    $type = $_SERVER['CONTENT_TYPE'] ?? '';
    if (!str_starts_with($type, 'application/json') && !str_starts_with($type, 'multipart/form-data')) {
        fail('JSON or multipart required', 415);
    }
}

function generate_id(): string
{
    return bin2hex(random_bytes(12));
}

function job_by_id(string $id): ?array
{
    $statement = db()->prepare('SELECT * FROM jobs WHERE id=?');
    $statement->execute([$id]);
    return $statement->fetch() ?: null;
}

// Fields safe to return from the API. source_path stays private.
function public_job(array $job): array
{
    $url = rtrim(cfg()['base_url'], '/');
    return [
        'id' => $job['id'],
        'title' => $job['title'],
        'original_name' => $job['original_name'],
        'status' => $job['status'],
        'stage' => $job['stage'],
        'progress' => (float) $job['progress'],
        'duration' => $job['duration'] === null ? null : (float) $job['duration'],
        'error' => $job['error'],
        'created_at' => $job['created_at'],
        'started_at' => $job['started_at'],
        'finished_at' => $job['finished_at'],
        'embed_url' => $url . '/embed/' . $job['id'],
        'watch_url' => $url . '/watch/' . $job['id'],
        'hls_url' => $job['status'] === 'completed' ? $url . '/media/' . $job['id'] . '/index.m3u8' : null,
    ];
}

// Copy the source into managed storage, then insert the queue row.
function create_job(string $source, string $original, string $title, bool $captions): array
{
    require_media_mount();
    $id = generate_id();
    $targetDir = rtrim(pathof('storage'), '/') . '/originals/' . $id;
    if (!is_dir($targetDir) && !mkdir($targetDir, 0770, true)) {
        throw new RuntimeException('Could not create original directory');
    }

    $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if (!in_array($extension, ['mp4', 'm4v', 'mov', 'webm', 'mkv', 'avi', 'mpg', 'mpeg'], true)) {
        $extension = 'bin';
    }
    $destination = $targetDir . '/source.' . $extension;
    if (!copy($source, $destination)) {
        throw new RuntimeException('Could not import source file');
    }

    $statement = db()->prepare('INSERT INTO jobs (id,title,original_name,source_path,captions) VALUES (?,?,?,?,?)');
    try {
        $statement->execute([$id, $title, $original, $destination, $captions ? 1 : 0]);
    } catch (Throwable $error) {
        @unlink($destination);
        throw $error;
    }
    return job_by_id($id);
}

// Accept a server path only when its realpath is under a configured import root.
function safe_import(string $given): string
{
    $real = realpath($given);
    if ($real === false || !is_file($real) || !is_readable($real)) {
        throw new InvalidArgumentException('Source file missing or unreadable');
    }
    foreach (cfg()['paths']['imports'] as $root) {
        $prefix = realpath($root);
        if ($prefix !== false && str_starts_with($real, rtrim($prefix, '/') . '/')) {
            return $real;
        }
    }
    throw new InvalidArgumentException('Source must be within a configured import directory');
}

function update_job(string $id, string $status, string $stage, float $percent, ?string $error = null): void
{
    $statement = db()->prepare('UPDATE jobs SET status=?, stage=?, progress=?, error=?, updated_at=CURRENT_TIMESTAMP WHERE id=?');
    $statement->execute([$status, $stage, max(0, min(100, $percent)), $error, $id]);
}
