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
    static $schema = false;
    if (!$schema) {
        $pdo->exec(file_get_contents(dirname(__DIR__) . '/sql/schema.sql'));
        $schema = true;
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

// One line for the dashboard: CPU, operating system, and RAM. macOS and Linux.
function server_summary(): string
{
    if (PHP_OS_FAMILY === 'Darwin') {
        $parts = array_filter([
            format_cpu(sysctl_value('machdep.cpu.brand_string') ?: sysctl_value('hw.model'), (int) (sysctl_value('hw.cpufrequency_max') ?: sysctl_value('hw.cpufrequency'))),
            trim(shell_value('/usr/bin/sw_vers -productName') . ' ' . shell_value('/usr/bin/sw_vers -productVersion')),
            format_ram((int) sysctl_value('hw.memsize')),
        ]);
    } elseif (PHP_OS_FAMILY === 'Linux') {
        $cpuinfo = readable_text('/proc/cpuinfo');
        $maxKhz = (int) readable_text('/sys/devices/system/cpu/cpu0/cpufreq/cpuinfo_max_freq');
        $parts = array_filter([
            format_cpu(linux_cpu_model($cpuinfo), $maxKhz * 1000),
            linux_os_name(readable_text('/etc/os-release')),
            format_ram(linux_ram_bytes(readable_text('/proc/meminfo'))),
        ]);
    } else {
        $parts = [trim(php_uname('s') . ' ' . php_uname('r')), php_uname('m')];
    }
    return $parts === [] ? php_uname('s') : implode(' · ', $parts);
}

function format_cpu(string $brand, int $hertz = 0): string
{
    $brand = preg_replace('/\((?:R|TM)\)/i', '', $brand) ?? $brand;
    $brand = preg_replace('/\s+CPU\b/i', '', $brand) ?? $brand;
    $brand = trim(preg_replace('/\s+/', ' ', $brand) ?? '');
    if ($brand !== '' && !preg_match('/ghz/i', $brand) && $hertz >= 100_000_000) {
        $ghz = rtrim(rtrim(number_format($hertz / 1_000_000_000, 2, '.', ''), '0'), '.');
        $brand .= ' @ ' . $ghz . ' GHz';
    }
    return $brand;
}

function format_ram(int $bytes): string
{
    if ($bytes < 1) {
        return '';
    }
    $gb = $bytes / 1073741824;
    $text = $gb >= 10 ? (string) (int) round($gb) : rtrim(rtrim(number_format($gb, 1, '.', ''), '0'), '.');
    return $text . ' GB';
}

function linux_cpu_model(string $cpuinfo): string
{
    if (preg_match('/^model name\s*:\s*(.+)$/mi', $cpuinfo, $matches)) {
        return trim($matches[1]);
    }
    if (preg_match('/^Hardware\s*:\s*(.+)$/mi', $cpuinfo, $matches)) {
        return trim($matches[1]);
    }
    return '';
}

function linux_os_name(string $release): string
{
    if (preg_match('/^PRETTY_NAME="(.*)"/m', $release, $matches)) {
        return $matches[1];
    }
    return 'Linux';
}

function linux_ram_bytes(string $meminfo): int
{
    if (!preg_match('/^MemTotal:\s+(\d+)\s+kB/mi', $meminfo, $matches)) {
        return 0;
    }
    return (int) $matches[1] * 1024;
}

function sysctl_value(string $key): string
{
    return shell_value('/usr/sbin/sysctl -n ' . escapeshellarg($key));
}

function shell_value(string $command): string
{
    if (!function_exists('shell_exec')) {
        return '';
    }
    $output = shell_exec($command . ' 2>/dev/null');
    return trim((string) $output);
}

function readable_text(string $path): string
{
    if (!is_readable($path)) {
        return '';
    }
    $text = file_get_contents($path);
    return is_string($text) ? $text : '';
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
        'log_line' => job_log_tail($job['id']),
        'duration' => $job['duration'] === null ? null : (float) $job['duration'],
        'error' => $job['error'],
        'created_at' => $job['created_at'],
        'started_at' => $job['started_at'],
        'finished_at' => $job['finished_at'],
        'embed_url' => $url . '/embed/' . $job['id'],
        'watch_url' => $url . '/watch/' . $job['id'],
        'hls_url' => $job['status'] === 'completed' ? $url . media_public_path($job['id']) . '/index.m3u8' : null,
        'placements' => job_placements($job['id']),
    ];
}

// Collection and key are URL segments: PY4E and 01_02_Intro.
function placement_token(string $value): string
{
    $value = trim($value);
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $value)) {
        throw new InvalidArgumentException('Use letters, numbers, dots, underscores, or hyphens');
    }
    return $value;
}

function canonical_collection(string $name): string
{
    $name = placement_token($name);
    $statement = db()->prepare('SELECT collection FROM placements WHERE collection = ? COLLATE NOCASE LIMIT 1');
    $statement->execute([$name]);
    $found = $statement->fetchColumn();
    return is_string($found) ? $found : $name;
}

function canonical_key(string $collection, string $key): string
{
    $key = placement_token($key);
    $statement = db()->prepare('SELECT logical_key FROM placements WHERE collection = ? AND logical_key = ? COLLATE NOCASE LIMIT 1');
    $statement->execute([$collection, $key]);
    $found = $statement->fetchColumn();
    return is_string($found) ? $found : $key;
}

function require_placement_pair(string $collection, string $key): void
{
    if ($collection === '' && $key === '') {
        return;
    }
    if ($collection === '' || $key === '') {
        throw new InvalidArgumentException('Provide both a collection and a logical key');
    }
    placement_token($collection);
    placement_token($key);
}

function placed_job(array $job, string $collection, string $key): array
{
    if ($collection !== '' && $key !== '') {
        assign_placement($job['id'], $collection, $key);
    }
    $public = public_job(job_by_id($job['id']));
    // Cron remains the backstop. This only helps when nothing is already rendering.
    if ($public['status'] === 'queued') {
        start_worker();
    }
    return $public;
}

// Start one worker and return. If a worker holds the lock, the new process exits immediately.
function start_worker(): void
{
    $php = php_cli_binary();
    $worker = dirname(__DIR__) . '/bin/worker.php';
    $path = getenv('PATH') ?: '/usr/bin:/bin';
    foreach (['/opt/homebrew/bin', '/usr/local/bin'] as $dir) {
        if (is_dir($dir) && !str_contains($path, $dir . ':') && !str_ends_with($path, $dir)) {
            $path = $dir . ':' . $path;
        }
    }
    $command = 'PATH=' . escapeshellarg($path)
        . ' nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($worker)
        . ' > /dev/null 2>&1 &';
    exec($command);
}

function php_cli_binary(): string
{
    if (PHP_SAPI === 'cli' && is_executable(PHP_BINARY)) {
        return PHP_BINARY;
    }
    foreach ([dirname(PHP_BINARY) . '/php', PHP_BINDIR . '/php'] as $candidate) {
        if (is_executable($candidate)) {
            return $candidate;
        }
    }
    return 'php';
}

// Point collection/key at this job. An existing key moves to the new video.
function assign_placement(string $jobId, string $collection, string $key): array
{
    $collection = canonical_collection($collection);
    $key = canonical_key($collection, $key);
    $existing = db()->prepare('SELECT job_id FROM placements WHERE collection = ? AND logical_key = ?');
    $existing->execute([$collection, $key]);
    $previous = $existing->fetchColumn();
    $statement = db()->prepare('INSERT INTO placements (collection, logical_key, job_id) VALUES (?,?,?) ON CONFLICT(collection, logical_key) DO UPDATE SET job_id = excluded.job_id, updated_at = CURRENT_TIMESTAMP');
    $statement->execute([$collection, $key, $jobId]);
    return [
        'collection' => $collection,
        'logical_key' => $key,
        'replaced' => is_string($previous) && $previous !== $jobId,
    ];
}

function job_placements(string $id): array
{
    $statement = db()->prepare('SELECT collection, logical_key FROM placements WHERE job_id = ? ORDER BY collection, logical_key');
    $statement->execute([$id]);
    $url = rtrim(cfg()['base_url'], '/');
    $rows = [];
    foreach ($statement->fetchAll() as $row) {
        $path = '/watch/' . rawurlencode($row['collection']) . '/' . rawurlencode($row['logical_key']);
        $embed = '/embed/' . rawurlencode($row['collection']) . '/' . rawurlencode($row['logical_key']);
        $rows[] = [
            'collection' => $row['collection'],
            'logical_key' => $row['logical_key'],
            'watch_url' => $url . $path,
            'embed_url' => $url . $embed,
        ];
    }
    return $rows;
}

function list_collections(): array
{
    $statement = db()->query('SELECT collection FROM placements GROUP BY collection COLLATE NOCASE ORDER BY collection');
    return array_column($statement->fetchAll(), 'collection');
}

// Every key in one collection, and the video that key points at now.
function list_collection(string $name): ?array
{
    try {
        $name = placement_token($name);
    } catch (InvalidArgumentException) {
        return null;
    }
    $statement = db()->prepare('SELECT collection FROM placements WHERE collection = ? COLLATE NOCASE LIMIT 1');
    $statement->execute([$name]);
    $collection = $statement->fetchColumn();
    if (!is_string($collection)) {
        return null;
    }
    $statement = db()->prepare('SELECT p.logical_key, p.job_id, j.title, j.status FROM placements p JOIN jobs j ON j.id = p.job_id WHERE p.collection = ? ORDER BY p.logical_key');
    $statement->execute([$collection]);
    $url = rtrim(cfg()['base_url'], '/');
    $items = [];
    foreach ($statement->fetchAll() as $row) {
        $path = rawurlencode($collection) . '/' . rawurlencode($row['logical_key']);
        $items[] = [
            'logical_key' => $row['logical_key'],
            'job_id' => $row['job_id'],
            'title' => $row['title'],
            'status' => $row['status'],
            'watch_url' => $url . '/watch/' . $path,
            'embed_url' => $url . '/embed/' . $path,
        ];
    }
    return ['collection' => $collection, 'items' => $items];
}

function search_collections(string $query): array
{
    $prefix = placement_prefix($query);
    if ($prefix === null) {
        return [];
    }
    $statement = db()->prepare('SELECT collection FROM placements WHERE collection LIKE ? COLLATE NOCASE GROUP BY collection COLLATE NOCASE ORDER BY collection LIMIT 8');
    $statement->execute([$prefix . '%']);
    return array_column($statement->fetchAll(), 'collection');
}

function search_keys(string $collection, string $query): array
{
    if (placement_prefix($collection) === null || trim($collection) === '') {
        return [];
    }
    $prefix = placement_prefix($query);
    if ($prefix === null) {
        return [];
    }
    try {
        $collection = canonical_collection($collection);
    } catch (InvalidArgumentException) {
        return [];
    }
    $statement = db()->prepare('SELECT logical_key FROM placements WHERE collection = ? AND logical_key LIKE ? COLLATE NOCASE ORDER BY logical_key LIMIT 8');
    $statement->execute([$collection, $prefix . '%']);
    return array_column($statement->fetchAll(), 'logical_key');
}

function placement_prefix(string $query): ?string
{
    $query = trim($query);
    if ($query === '') {
        return '';
    }
    if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $query)) {
        return null;
    }
    return $query;
}

// Resolve a public /watch/{collection}/{key} URL to the video it currently names.
function placement_job(string $collection, string $key): ?array
{
    try {
        $collection = canonical_collection($collection);
        $key = canonical_key($collection, $key);
    } catch (InvalidArgumentException) {
        return null;
    }
    $statement = db()->prepare('SELECT job_id FROM placements WHERE collection = ? AND logical_key = ?');
    $statement->execute([$collection, $key]);
    $jobId = $statement->fetchColumn();
    if (!is_string($jobId)) {
        return null;
    }
    $job = job_by_id($jobId);
    if (!$job) {
        return null;
    }
    $job['collection'] = $collection;
    $job['logical_key'] = $key;
    return $job;
}

class ConflictException extends RuntimeException
{
}

function is_content_id(string $id): bool
{
    return (bool) preg_match('/^[a-f0-9]{64}$/', $id);
}

// Disk layout is ab/cd/<sha256>/, from the SHA-256 of the original file.
function content_rel(string $id): string
{
    return substr($id, 0, 2) . '/' . substr($id, 2, 2) . '/' . $id;
}

function content_dir(string $kind, string $id): string
{
    if (!is_content_id($id)) {
        throw new InvalidArgumentException('Invalid content id');
    }
    return rtrim(pathof('storage'), '/') . '/' . $kind . '/' . content_rel($id);
}

// Local disk, next to the SQLite file. Survives staging cleanup and publication.
function job_log_path(string $id): string
{
    if (!is_content_id($id)) {
        throw new InvalidArgumentException('Invalid content id');
    }
    $dir = dirname(pathof('database')) . '/logs';
    if (!is_dir($dir) && !mkdir($dir, 0770, true)) {
        throw new RuntimeException('Could not create log directory');
    }
    return $dir . '/' . $id . '.log';
}

// Last non-empty line of the worker log, for the dashboard refresh. Does not create the file.
function job_log_tail(string $id, int $max = 240): ?string
{
    if (!is_content_id($id)) {
        return null;
    }
    $path = dirname(pathof('database')) . '/logs/' . $id . '.log';
    if (!is_file($path)) {
        return null;
    }
    $handle = fopen($path, 'rb');
    if (!$handle) {
        return null;
    }
    $size = filesize($path);
    if ($size === false || $size === 0) {
        fclose($handle);
        return null;
    }
    $read = (int) min($size, 8192);
    fseek($handle, -$read, SEEK_END);
    $chunk = fread($handle, $read);
    fclose($handle);
    if (!is_string($chunk) || $chunk === '') {
        return null;
    }
    $lines = preg_split("/\r\n|\n|\r/", rtrim($chunk, "\r\n"));
    $line = '';
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        $candidate = trim($lines[$i]);
        if ($candidate !== '') {
            $line = $candidate;
            break;
        }
    }
    if ($line === '') {
        return null;
    }
    if (strlen($line) > $max) {
        $line = substr($line, 0, $max - 1) . '…';
    }
    return $line;
}

function media_public_path(string $id): string
{
    return '/media/' . content_rel($id);
}

// Keep one original per SHA-256. A finished match is returned as-is.
// A failed or changed match is queued again; the worker replaces its output from staging.
function create_job(string $source, string $original, string $title, bool $captions): array
{
    require_media_mount();
    $id = hash_file('sha256', $source);
    if ($id === false) {
        throw new RuntimeException('Could not hash source file');
    }

    $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if (!in_array($extension, ['mp4', 'm4v', 'mov', 'webm', 'mkv', 'avi', 'mpg', 'mpeg'], true)) {
        $extension = 'bin';
    }
    $targetDir = content_dir('originals', $id);
    $destination = $targetDir . '/' . $id . '.' . $extension;
    $existingFile = existing_original($targetDir, $id);
    $wroteFile = false;
    if ($existingFile !== null) {
        $destination = $existingFile;
    } else {
        if (!is_dir($targetDir) && !mkdir($targetDir, 0770, true)) {
            throw new RuntimeException('Could not create original directory');
        }
        $partial = $targetDir . '/' . $id . '.partial';
        if (!copy($source, $partial)) {
            throw new RuntimeException('Could not import source file');
        }
        if (!rename($partial, $destination)) {
            @unlink($partial);
            throw new RuntimeException('Could not store original file');
        }
        $wroteFile = true;
    }

    $existing = job_by_id($id);
    if ($existing) {
        if ($existing['status'] === 'processing') {
            throw new ConflictException('This file is already being processed');
        }
        $sameCaptions = (int) $existing['captions'] === ($captions ? 1 : 0);
        if ($existing['status'] === 'completed' && $sameCaptions) {
            return $existing;
        }
        $statement = db()->prepare("UPDATE jobs SET title=?, original_name=?, source_path=?, captions=?, status='queued', stage='queued', progress=0, error=NULL, started_at=NULL, finished_at=NULL, updated_at=CURRENT_TIMESTAMP WHERE id=?");
        $statement->execute([$title, $original, $destination, $captions ? 1 : 0, $id]);
        return job_by_id($id);
    }

    $statement = db()->prepare('INSERT INTO jobs (id,title,original_name,source_path,captions) VALUES (?,?,?,?,?)');
    try {
        $statement->execute([$id, $title, $original, $destination, $captions ? 1 : 0]);
    } catch (Throwable $error) {
        if ($wroteFile) {
            @unlink($destination);
        }
        throw $error;
    }
    return job_by_id($id);
}

function existing_original(string $directory, string $id): ?string
{
    if (!is_dir($directory)) {
        return null;
    }
    foreach (scandir($directory) as $name) {
        if (str_starts_with($name, $id . '.') && !str_ends_with($name, '.partial')) {
            return $directory . '/' . $name;
        }
    }
    return null;
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

// Remove the copied original, any in-progress work, the published HLS, and the job row.
// The caller must refuse a job that is still processing.
function delete_job(array $job): void
{
    $id = (string) $job['id'];
    if (!is_content_id($id)) {
        throw new InvalidArgumentException('Invalid id');
    }
    require_media_mount();
    $storage = realpath(pathof('storage'));
    if ($storage === false) {
        throw new RuntimeException('Storage directory is missing');
    }
    foreach (['originals', 'work', 'media'] as $kind) {
        remove_job_dir($storage, $kind, $id);
        $previous = dirname(content_dir($kind, $id)) . '/' . $id . '.previous';
        if (is_dir($previous) || is_link($previous)) {
            $real = realpath($previous);
            $prefix = $storage . '/' . $kind . '/';
            if ($real === false || !str_starts_with($real, $prefix)) {
                throw new RuntimeException('Refusing to delete outside storage');
            }
            remove_tree($real);
        }
    }
    $statement = db()->prepare('DELETE FROM placements WHERE job_id=?');
    $statement->execute([$id]);
    $statement = db()->prepare('DELETE FROM jobs WHERE id=?');
    $statement->execute([$id]);
    $log = job_log_path($id);
    if (is_file($log)) {
        unlink($log);
    }
}

function remove_job_dir(string $storage, string $kind, string $id): void
{
    $path = $storage . '/' . $kind . '/' . content_rel($id);
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    $real = realpath($path);
    $prefix = $storage . '/' . $kind . '/';
    if ($real === false || !str_starts_with($real, $prefix)) {
        throw new RuntimeException('Refusing to delete outside storage');
    }
    remove_tree($real);
}

// The worker renders into work/. This renames that finished tree onto media/.
// An older render is moved aside first, so a long transcode stays unpublished until it is complete.
function publish_staged(string $work, string $final): void
{
    $parent = dirname($final);
    if (!is_dir($parent) && !mkdir($parent, 0770, true)) {
        throw new RuntimeException('Could not create publish directory');
    }
    if (!is_dir($work)) {
        throw new RuntimeException('Staging directory is missing');
    }
    if (!file_exists($final)) {
        if (!rename($work, $final)) {
            throw new RuntimeException('Atomic publish rename failed (ensure work/media share a filesystem)');
        }
        return;
    }

    $previous = $parent . '/' . basename($final) . '.previous';
    if (file_exists($previous)) {
        $old = realpath($previous);
        if ($old !== false) {
            remove_tree($old);
        }
    }
    if (!rename($final, $previous)) {
        throw new RuntimeException('Could not move the previous render aside');
    }
    if (!rename($work, $final)) {
        @rename($previous, $final);
        throw new RuntimeException('Atomic publish rename failed (ensure work/media share a filesystem)');
    }
    $old = realpath($previous);
    if ($old !== false) {
        remove_tree($old);
    }
}

function remove_tree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        if (!unlink($path)) {
            throw new RuntimeException('Could not delete ' . $path);
        }
        return;
    }
    $items = scandir($path);
    if ($items === false) {
        throw new RuntimeException('Could not read ' . $path);
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        remove_tree($path . '/' . $item);
    }
    if (!rmdir($path)) {
        throw new RuntimeException('Could not remove ' . $path);
    }
}
