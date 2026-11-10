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

// Public playback host, then the optional management host. Both serve this app.
function configured_base_urls(): array
{
    $urls = [];
    foreach (['base_url', 'manage_url'] as $key) {
        $value = cfg()[$key] ?? '';
        if (!is_string($value) || trim($value) === '') {
            continue;
        }
        $urls[] = rtrim($value, '/');
    }
    return array_values(array_unique($urls));
}

function public_base_url(): string
{
    return rtrim((string) cfg()['base_url'], '/');
}

// Host, with a non-default port, for comparing a request to a configured base URL.
function url_host(string $url): string
{
    $parts = parse_url($url);
    if (!is_array($parts) || !isset($parts['host'])) {
        return '';
    }
    $host = strtolower((string) $parts['host']);
    $port = $parts['port'] ?? null;
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $default = $scheme === 'https' ? 443 : ($scheme === 'http' ? 80 : null);
    if ($port !== null && $port !== $default) {
        $host .= ':' . $port;
    }
    return $host;
}

function request_host(): string
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return '';
    }
    $scheme = strtolower((string) ($_SERVER['REQUEST_SCHEME'] ?? ''));
    if ($scheme === '' && (($_SERVER['HTTPS'] ?? '') === 'on' || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443')) {
        $scheme = 'https';
    }
    $default = $scheme === 'https' ? ':443' : ($scheme === 'http' ? ':80' : '');
    if ($default !== '' && str_ends_with($host, $default)) {
        $host = substr($host, 0, -strlen($default));
    }
    return $host;
}

// The configured base that matches this request, or the public one from CLI.
function request_base_url(): string
{
    $host = request_host();
    if ($host !== '') {
        foreach (configured_base_urls() as $url) {
            if ($host === url_host($url)) {
                return $url;
            }
        }
    }
    return public_base_url();
}

// The management host when it is configured and not the same as the public host.
function separate_manage_url(): ?string
{
    $manage = cfg()['manage_url'] ?? '';
    if (!is_string($manage) || trim($manage) === '') {
        return null;
    }
    $manageUrl = rtrim($manage, '/');
    $publicUrl = public_base_url();
    if (strcasecmp($manageUrl, $publicUrl) === 0 || url_host($manageUrl) === url_host($publicUrl)) {
        return null;
    }
    return $manageUrl;
}

function api_base_url(): string
{
    return separate_manage_url() ?? public_base_url();
}

// True when this request is the cached public host and manage_url is a different host.
function public_host_is_limited(): bool
{
    if (separate_manage_url() === null) {
        return false;
    }
    $host = request_host();
    return $host !== '' && $host === url_host(public_base_url());
}

// URL path of this install, e.g. "/tsugi-video" under MAMP or "" at the vhost root.
function base_path(): string
{
    $path = parse_url(request_base_url(), PHP_URL_PATH);
    return rtrim(is_string($path) ? $path : '', '/');
}

function url_path(string $path): string
{
    return base_path() . '/' . ltrim($path, '/');
}

function public_url(string $path): string
{
    return public_base_url() . '/' . ltrim($path, '/');
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
        ensure_job_columns($pdo);
        $schema = true;
    }
    return $pdo;
}

function init_db(): void
{
    db()->exec(file_get_contents(dirname(__DIR__) . '/sql/schema.sql'));
}

// CREATE TABLE IF NOT EXISTS does not add columns to a database that already exists.
function ensure_job_columns(PDO $pdo): void
{
    $names = [];
    foreach ($pdo->query('PRAGMA table_info(jobs)') as $row) {
        $names[$row['name']] = true;
    }
    if (!isset($names['caption_prompt'])) {
        $pdo->exec('ALTER TABLE jobs ADD COLUMN caption_prompt TEXT');
    }
    if (!isset($names['caption_replacements'])) {
        $pdo->exec('ALTER TABLE jobs ADD COLUMN caption_replacements TEXT');
    }
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

function redirect_to_url(): ?string
{
    $value = cfg()['redirect_to'] ?? null;
    if (!is_string($value)) {
        return null;
    }
    $value = trim($value);
    if ($value === '' || preg_match('/[\r\n]/', $value) || preg_match('#\Ahttps?://#i', $value) !== 1) {
        return null;
    }
    return $value;
}

function public_request_is_playback(): bool
{
    $path = request_path();
    if (preg_match('~^/(?:watch|embed|media)(?:/|$)~', $path) === 1) {
        return true;
    }
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    return $script === 'player.php' || str_ends_with($script, '/player.php')
        || $script === 'media.php' || str_ends_with($script, '/media.php');
}

// A disallowed public-host URL redirects when redirect_to is set. Otherwise it says the server is read-only.
function refuse_public_host(): never
{
    $target = redirect_to_url();
    if ($target !== null) {
        header('Cache-Control: no-store');
        header('Location: ' . $target, true, 302);
        exit;
    }
    fail_page('This is a read-only video server.', 403);
}

function fail_page(string $error, int $code): never
{
    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    if (str_contains($accept, 'text/html')) {
        http_response_code($code);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Video Server</title><p>' . e($error) . '</p></html>';
        exit;
    }
    fail($error, $code);
}

// Playback is cached on the public host. The player page and its media stay on that host.
function redirect_playback_to_public_host(): void
{
    $manage = separate_manage_url();
    if ($manage === null || request_host() !== url_host($manage)) {
        return;
    }
    $path = request_path();
    if (preg_match('~^/(?:watch|embed)(?:/|$)~', $path) !== 1) {
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($script !== 'player.php' && !str_ends_with($script, '/player.php')) {
            return;
        }
        $prefix = ($_GET['embed'] ?? '') === '1' ? '/embed/' : '/watch/';
        $collection = (string) ($_GET['collection'] ?? '');
        $key = (string) ($_GET['key'] ?? '');
        $id = (string) ($_GET['id'] ?? '');
        if ($collection !== '' && $key !== '') {
            $path = $prefix . rawurlencode($collection) . '/' . rawurlencode($key);
        } elseif (is_content_id($id)) {
            $path = $prefix . $id;
        } else {
            return;
        }
    }
    $params = $_GET;
    unset($params['id'], $params['embed'], $params['collection'], $params['key']);
    $query = http_build_query($params);
    header('Cache-Control: no-store');
    header('Location: ' . public_base_url() . $path . ($query !== '' ? '?' . $query : ''), true, 302);
    exit;
}

// The cached host serves watch and embed pages. Other URLs are refused without naming the management host.
function enforce_public_host_limits(): void
{
    if (separate_manage_url() === null || !public_host_is_limited()) {
        return;
    }
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (public_request_is_playback() && in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        return;
    }
    refuse_public_host();
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

    $limited = public_host_is_limited();
    $user = null;
    $password = null;
    if (!$limited) {
        $user = $_SERVER['PHP_AUTH_USER'] ?? null;
        $password = $_SERVER['PHP_AUTH_PW'] ?? null;
        if (($user === null || $password === null) && preg_match('/^Basic\s+(\S+)$/i', $header, $matches)) {
            $decoded = base64_decode($matches[1], true);
            if ($decoded !== false && str_contains($decoded, ':')) {
                [$user, $password] = explode(':', $decoded, 2);
            }
        }
    }

    $bearerOk = $bearer !== '' && hash_equals((string) $conf['api_token'], $bearer);
    $basicOk = !$limited && $user !== null && $password !== null
        && hash_equals((string) $conf['admin_user'], (string) $user)
        && hash_equals((string) $conf['admin_password'], (string) $password);
    if (!$bearerOk && !$basicOk) {
        if ($limited) {
            refuse_public_host();
        }
        header('WWW-Authenticate: Basic realm="Video Server Admin"');
        fail('Authentication required', 401);
    }

    $placeholderPassword = str_starts_with((string) $conf['admin_password'], 'CHANGE_ME');
    $placeholderToken = str_starts_with((string) $conf['api_token'], 'CHANGE_ME');
    if ($placeholderPassword || $placeholderToken) {
        fail('Change default credentials in config.php', 503);
    }
}

function same_origin(string $origin, string $expected): bool
{
    $originParts = parse_url($origin);
    $expectedParts = parse_url($expected);
    if (!is_array($originParts) || !is_array($expectedParts)) {
        return false;
    }
    return strcasecmp((string) ($originParts['host'] ?? ''), (string) ($expectedParts['host'] ?? '')) === 0
        && strcasecmp((string) ($originParts['scheme'] ?? ''), (string) ($expectedParts['scheme'] ?? '')) === 0
        && ($originParts['port'] ?? null) === ($expectedParts['port'] ?? null);
}

// Browser Basic credentials are sent automatically, so writes must come from a configured host.
// A missing Origin is allowed so curl and other API clients still work.
function check_csrf(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        $allowed = false;
        foreach (configured_base_urls() as $expected) {
            if (same_origin($origin, $expected)) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
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
    $url = public_base_url();
    return [
        'id' => $job['id'],
        'title' => $job['title'],
        'display_title' => display_title($job),
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
    $url = public_base_url();
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

function job_counts(): array
{
    $counts = ['queued' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0];
    foreach ($counts as $name => $_) {
        $statement = db()->prepare('SELECT COUNT(*) FROM jobs WHERE status=?');
        $statement->execute([$name]);
        $counts[$name] = (int) $statement->fetchColumn();
    }
    return $counts;
}

// Title for people. A blank title falls back to the original filename.
function display_title(array $job): string
{
    $title = trim((string) ($job['title'] ?? ''));
    return $title !== '' ? $title : (string) $job['original_name'];
}

// Seconds from when the worker claimed the job until it finished. A running job
// counts through now. Null until the job has started.
function encode_seconds(array $job): ?int
{
    $start = trim((string) ($job['started_at'] ?? ''));
    if ($start === '') {
        return null;
    }
    $startAt = strtotime($start . ' UTC');
    if ($startAt === false) {
        return null;
    }
    $end = trim((string) ($job['finished_at'] ?? ''));
    $endAt = $end === '' ? time() : strtotime($end . ' UTC');
    if ($endAt === false) {
        return null;
    }
    return max(0, $endAt - $startAt);
}

function format_elapsed(?int $seconds): string
{
    if ($seconds === null) {
        return '—';
    }
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $rest = $seconds % 60;
    if ($hours > 0) {
        return sprintf('%d:%02d:%02d', $hours, $minutes, $rest);
    }
    return sprintf('%d:%02d', $minutes, $rest);
}

// Newest-first queue, or a searchable library page. Sort and direction are a fixed list.
function list_jobs(array $options): array
{
    $limit = max(1, min(200, (int) ($options['limit'] ?? 200)));
    $offset = max(0, (int) ($options['offset'] ?? 0));
    $sort = (string) ($options['sort'] ?? 'created');
    $direction = strtolower((string) ($options['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
    $order = [
        'title' => "CASE WHEN TRIM(j.title) = '' THEN j.original_name ELSE j.title END COLLATE NOCASE",
        'status' => 'j.status',
        'collection' => "(SELECT MIN(collection) FROM placements WHERE job_id = j.id) COLLATE NOCASE",
        'created' => 'j.created_at',
    ][$sort] ?? 'j.created_at';

    $where = [];
    $params = [];
    $query = trim((string) ($options['q'] ?? ''));
    if ($query !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query) . '%';
        $where[] = "(j.id LIKE ? ESCAPE '\\' COLLATE NOCASE OR j.title LIKE ? ESCAPE '\\' COLLATE NOCASE OR j.original_name LIKE ? ESCAPE '\\' COLLATE NOCASE OR EXISTS (SELECT 1 FROM placements s WHERE s.job_id = j.id AND (s.collection LIKE ? ESCAPE '\\' COLLATE NOCASE OR s.logical_key LIKE ? ESCAPE '\\' COLLATE NOCASE)))";
        array_push($params, $like, $like, $like, $like, $like);
    }
    $collection = trim((string) ($options['collection'] ?? ''));
    if ($collection !== '') {
        $where[] = 'EXISTS (SELECT 1 FROM placements c WHERE c.job_id = j.id AND c.collection = ? COLLATE NOCASE)';
        $params[] = $collection;
    }
    $sqlWhere = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

    $count = db()->prepare("SELECT COUNT(*) FROM jobs j $sqlWhere");
    $count->execute($params);
    $total = (int) $count->fetchColumn();

    $statement = db()->prepare("SELECT j.* FROM jobs j $sqlWhere ORDER BY $order $direction, j.created_at DESC, j.id LIMIT $limit OFFSET $offset");
    $statement->execute($params);
    return [
        'jobs' => $statement->fetchAll(),
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset,
    ];
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
    $url = public_base_url();
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

// Heights to encode, tallest first. A source shorter than a rung skips that rung.
// When every configured height is taller than the source, the source height is the only rung.
function video_ladder_heights(array $video, int $sourceHeight): array
{
    if ($sourceHeight < 2) {
        throw new InvalidArgumentException('Video is too small');
    }
    $configured = [];
    if (isset($video['heights']) && is_array($video['heights']) && $video['heights'] !== []) {
        foreach ($video['heights'] as $height) {
            $configured[] = (int) $height;
        }
    } else {
        $max = (int) ($video['height'] ?? 720);
        if ($max < 2) {
            $max = 720;
        }
        if ($max % 2 !== 0) {
            $max--;
        }
        $configured = [$max];
        if (360 < $max) {
            $configured[] = 360;
        }
    }
    $valid = [];
    foreach ($configured as $height) {
        if ($height >= 2 && $height % 2 === 0) {
            $valid[$height] = $height;
        }
    }
    if ($valid === []) {
        throw new InvalidArgumentException('video.heights needs even heights');
    }
    $ceiling = max($valid);
    $evenSource = $sourceHeight - ($sourceHeight % 2);
    $kept = [];
    foreach ($valid as $height) {
        if ($height <= $sourceHeight) {
            $kept[$height] = $height;
        }
    }
    // Keep a source that falls between two rungs, and never above the configured ceiling.
    if ($evenSource >= 2 && $evenSource < $ceiling && ($kept === [] || max($kept) < $evenSource)) {
        $kept[$evenSource] = $evenSource;
    }
    if ($kept === []) {
        throw new InvalidArgumentException('No video sizes fit this source');
    }
    rsort($kept, SORT_NUMERIC);
    return array_values($kept);
}

function published_media_name(string $file): bool
{
    return (bool) preg_match(
        '#^(?:[0-9]{2,4}/)?(?:index\.m3u8|segment[0-9]{5}\.ts)$|^captions\.vtt$|^captions-[a-z]{2,3}\.vtt$#',
        $file
    );
}

// English stays captions.vtt. Another language is captions-es.vtt beside it.
function caption_language(string $code): string
{
    $code = strtolower(trim($code));
    if (!preg_match('/^[a-z]{2,3}$/', $code)) {
        throw new InvalidArgumentException('Use a 2 or 3 letter language code, such as en or es');
    }
    return $code;
}

function caption_language_names(): array
{
    return [
        'en' => 'English',
        'es' => 'Spanish',
        'pt' => 'Portuguese',
        'fr' => 'French',
        'de' => 'German',
        'it' => 'Italian',
        'nl' => 'Dutch',
        'pl' => 'Polish',
        'ru' => 'Russian',
        'uk' => 'Ukrainian',
        'ar' => 'Arabic',
        'hi' => 'Hindi',
        'zh' => 'Chinese',
        'ja' => 'Japanese',
        'ko' => 'Korean',
        'vi' => 'Vietnamese',
        'tr' => 'Turkish',
        'id' => 'Indonesian',
        'sv' => 'Swedish',
    ];
}

function caption_language_label(string $code): string
{
    $names = caption_language_names();
    return $names[$code] ?? strtoupper($code);
}

function caption_track_file(string $language): string
{
    $language = caption_language($language);
    return $language === 'en' ? 'captions.vtt' : 'captions-' . $language . '.vtt';
}

function caption_tracks(string $id): array
{
    if (!is_content_id($id)) {
        return [];
    }
    $dir = content_dir('media', $id);
    if (!is_dir($dir)) {
        return [];
    }
    $tracks = [];
    if (is_file($dir . '/captions.vtt')) {
        $tracks[] = caption_track_info('en');
    }
    $paths = glob($dir . '/captions-*.vtt') ?: [];
    sort($paths, SORT_STRING);
    foreach ($paths as $path) {
        if (!preg_match('/captions-([a-z]{2,3})\.vtt$/', basename($path), $matches)) {
            continue;
        }
        if ($matches[1] === 'en') {
            continue;
        }
        $tracks[] = caption_track_info($matches[1]);
    }
    return $tracks;
}

function caption_track_info(string $language): array
{
    $language = caption_language($language);
    return [
        'language' => $language,
        'label' => caption_language_label($language),
        'file' => caption_track_file($language),
    ];
}

function caption_vtt_text(string $contents): string
{
    if (strlen($contents) > 2 * 1024 * 1024) {
        throw new InvalidArgumentException('Caption file is larger than 2 MB');
    }
    if (str_contains($contents, "\0")) {
        throw new InvalidArgumentException('Caption file must be WebVTT text');
    }
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
    if (!preg_match('/^\s*WEBVTT(\s|$)/', $text)) {
        throw new InvalidArgumentException('Caption file must start with WEBVTT');
    }
    return $text;
}

// Replace one published track. English overwrites captions.vtt from Whisper.
function save_caption_track(string $id, string $language, string $contents): array
{
    if (!is_content_id($id)) {
        throw new InvalidArgumentException('Invalid content id');
    }
    $language = caption_language($language);
    $job = job_by_id($id);
    if (!$job || $job['status'] !== 'completed') {
        throw new InvalidArgumentException('Captions can be added after the video is published');
    }
    require_media_mount();
    $dir = content_dir('media', $id);
    $realDir = realpath($dir);
    if ($realDir === false || !is_dir($realDir)) {
        throw new InvalidArgumentException('Published video is missing');
    }
    $text = caption_vtt_text($contents);
    $file = caption_track_file($language);
    $target = $realDir . '/' . $file;
    $partial = $target . '.partial';
    if (file_put_contents($partial, $text) === false) {
        throw new RuntimeException('Could not write captions');
    }
    if (!rename($partial, $target)) {
        @unlink($partial);
        throw new RuntimeException('Could not publish captions');
    }
    return caption_track_info($language);
}

function delete_caption_track(string $id, string $language): void
{
    if (!is_content_id($id)) {
        throw new InvalidArgumentException('Invalid content id');
    }
    $language = caption_language($language);
    $job = job_by_id($id);
    if (!$job) {
        throw new InvalidArgumentException('Not found');
    }
    require_media_mount();
    $dir = content_dir('media', $id);
    $realDir = realpath($dir);
    if ($realDir === false || !is_dir($realDir)) {
        throw new InvalidArgumentException('Published video is missing');
    }
    $path = $realDir . '/' . caption_track_file($language);
    if (!is_file($path)) {
        throw new InvalidArgumentException('That caption track is not on this video');
    }
    $real = realpath($path);
    if ($real === false || !str_starts_with($real, $realDir . '/')) {
        throw new RuntimeException('Refusing to delete outside the published video');
    }
    if (!unlink($real)) {
        throw new RuntimeException('Could not remove captions');
    }
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

// Files sitting in the configured import folders. The dashboard lists these so an
// admin can enqueue one without uploading it again. One level deep, newest first.
function list_import_files(): array
{
    $files = [];
    $seen = [];
    foreach (cfg()['paths']['imports'] as $root) {
        $prefix = realpath($root);
        if ($prefix === false || !is_dir($prefix)) {
            continue;
        }
        $handle = opendir($prefix);
        if ($handle === false) {
            continue;
        }
        while (($name = readdir($handle)) !== false) {
            if ($name === '.' || $name === '..' || str_starts_with($name, '.')) {
                continue;
            }
            $path = $prefix . '/' . $name;
            if (!is_file($path) || !is_readable($path)) {
                continue;
            }
            $real = realpath($path);
            if ($real === false || !str_starts_with($real, $prefix . '/')) {
                continue;
            }
            if (isset($seen[$real])) {
                continue;
            }
            $seen[$real] = true;
            $files[] = [
                'path' => $real,
                'name' => basename($real),
                'bytes' => (int) (filesize($real) ?: 0),
                'mtime' => (int) (filemtime($real) ?: 0),
            ];
        }
        closedir($handle);
    }
    usort($files, function (array $a, array $b): int {
        return $b['mtime'] <=> $a['mtime'] ?: strcmp($a['name'], $b['name']);
    });
    if (count($files) > 500) {
        $files = array_slice($files, 0, 500);
    }
    $counts = [];
    foreach ($files as $file) {
        $counts[$file['name']] = ($counts[$file['name']] ?? 0) + 1;
    }
    foreach ($files as &$file) {
        $shown = $counts[$file['name']] > 1
            ? basename(dirname($file['path'])) . '/' . $file['name']
            : $file['name'];
        $file['label'] = $shown . ' (' . format_bytes($file['bytes']) . ')';
        unset($file['mtime']);
    }
    unset($file);
    return $files;
}

function format_bytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $size = (float) max(0, $bytes);
    $unit = 0;
    while ($size >= 1024 && $unit < count($units) - 1) {
        $size /= 1024;
        $unit++;
    }
    $digits = $unit === 0 || $size >= 10 ? 0 : 1;
    return number_format($size, $digits) . ' ' . $units[$unit];
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

// Save the inbox vocabulary prompt and replacement rules on a job.
// The worker reads these after the Dropbox folder may already have been cleared.
function store_caption_context(string $id, ?string $prompt, ?string $replacements): void
{
    if (!is_content_id($id)) {
        throw new InvalidArgumentException('Invalid content id');
    }
    $prompt = $prompt === null ? '' : trim($prompt);
    $hasRules = $replacements !== null && trim($replacements) !== '';
    $statement = db()->prepare('UPDATE jobs SET caption_prompt=?, caption_replacements=?, updated_at=CURRENT_TIMESTAMP WHERE id=?');
    $statement->execute([
        $prompt === '' ? null : $prompt,
        $hasRules ? $replacements : null,
        $id,
    ]);
}

// media.yaml as written by bootstrap-media-yaml.py: nested maps, scalars, and | blocks.
function parse_media_yaml(string $text): array
{
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
    $lines = preg_split("/\r\n|\n|\r/", $text);
    if ($lines === false) {
        throw new InvalidArgumentException('Could not read media.yaml');
    }
    $root = [];
    $stack = [['indent' => -1, 'path' => []]];
    $count = count($lines);
    $index = 0;
    while ($index < $count) {
        $line = $lines[$index];
        $lineNo = $index + 1;
        if (trim($line) === '' || preg_match('/^\s*#/', $line) || trim($line) === '---' || trim($line) === '...') {
            $index++;
            continue;
        }
        if (preg_match('/^\t/', $line) || preg_match('/^ *\t/', $line)) {
            throw new InvalidArgumentException('line ' . $lineNo . ': tabs are not allowed in indentation');
        }
        if (!preg_match('/^( *)(.+?):(.*)$/', $line, $matches)) {
            throw new InvalidArgumentException('line ' . $lineNo . ': expected key: value');
        }
        $indent = strlen($matches[1]);
        $key = rtrim($matches[2]);
        if ($key === '') {
            throw new InvalidArgumentException('line ' . $lineNo . ': empty key');
        }
        while (count($stack) > 1 && $stack[array_key_last($stack)]['indent'] >= $indent) {
            array_pop($stack);
        }
        $parent = $stack[array_key_last($stack)]['path'];
        $rest = trim($matches[3]);
        if (preg_match('/^\|([-+]?)[0-9]?$/', $rest, $block)) {
            $index++;
            $blockLines = [];
            $blockIndent = null;
            while ($index < $count) {
                $candidate = $lines[$index];
                if (trim($candidate) === '') {
                    $blockLines[] = '';
                    $index++;
                    continue;
                }
                if (preg_match('/^\t/', $candidate)) {
                    throw new InvalidArgumentException('line ' . ($index + 1) . ': tabs are not allowed in indentation');
                }
                if (!preg_match('/^( *)(.*)$/', $candidate, $parts)) {
                    break;
                }
                $contentIndent = strlen($parts[1]);
                if ($blockIndent === null) {
                    if ($contentIndent <= $indent) {
                        break;
                    }
                    $blockIndent = $contentIndent;
                }
                if ($contentIndent < $blockIndent) {
                    break;
                }
                $blockLines[] = substr($candidate, $blockIndent);
                $index++;
            }
            $value = implode("\n", $blockLines);
            $chomp = $block[1];
            if ($chomp === '-') {
                $value = rtrim($value, "\n");
            } elseif ($chomp !== '+') {
                $value = rtrim($value, "\n");
                if ($blockLines !== []) {
                    $value .= "\n";
                }
            }
            yaml_assign($root, $parent, $key, $value);
            continue;
        }
        if ($rest === '') {
            $nextIndent = yaml_next_indent($lines, $index + 1);
            if ($nextIndent !== null && $nextIndent > $indent) {
                yaml_assign($root, $parent, $key, []);
                $stack[] = ['indent' => $indent, 'path' => array_merge($parent, [$key])];
            } else {
                yaml_assign($root, $parent, $key, null);
            }
            $index++;
            continue;
        }
        yaml_assign($root, $parent, $key, yaml_scalar($rest, $lineNo));
        $index++;
    }
    return $root;
}

function yaml_next_indent(array $lines, int $start): ?int
{
    $count = count($lines);
    for ($index = $start; $index < $count; $index++) {
        $line = $lines[$index];
        if (trim($line) === '' || preg_match('/^\s*#/', $line)) {
            continue;
        }
        if (!preg_match('/^( *)\S/', $line, $matches)) {
            return 0;
        }
        return strlen($matches[1]);
    }
    return null;
}

function yaml_assign(array &$root, array $parentPath, string $key, mixed $value): void
{
    $node = &$root;
    foreach ($parentPath as $segment) {
        if (!array_key_exists($segment, $node) || !is_array($node[$segment])) {
            $node[$segment] = [];
        }
        $node = &$node[$segment];
    }
    $node[$key] = $value;
}

function yaml_scalar(string $value, int $lineNo): mixed
{
    if (preg_match('/^(?:null|Null|NULL|~)$/', $value)) {
        return null;
    }
    if (preg_match('/^(?:true|True|TRUE)$/', $value)) {
        return true;
    }
    if (preg_match('/^(?:false|False|FALSE)$/', $value)) {
        return false;
    }
    if (strlen($value) >= 2 && $value[0] === "'" && str_ends_with($value, "'")) {
        return str_replace("''", "'", substr($value, 1, -1));
    }
    if (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
        return yaml_unescape_double(substr($value, 1, -1));
    }
    if ((str_starts_with($value, "'") && !str_ends_with($value, "'")) || (str_starts_with($value, '"') && !str_ends_with($value, '"'))) {
        throw new InvalidArgumentException('line ' . $lineNo . ': unterminated quoted value');
    }
    return $value;
}

function yaml_unescape_double(string $value): string
{
    $replaced = preg_replace_callback('/\\\\(.)/', static function (array $matches): string {
        return match ($matches[1]) {
            'n' => "\n",
            't' => "\t",
            'r' => "\r",
            '"' => '"',
            '\\' => '\\',
            default => $matches[1],
        };
    }, $value);
    return $replaced ?? $value;
}

// One term per line. Blank lines and # comments are ignored.
function caption_vocabulary_terms(string $text): array
{
    $terms = [];
    foreach (preg_split("/\r\n|\n|\r/", $text) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $terms[] = $line;
    }
    return $terms;
}

// whisper-cli --prompt is capped at n_text_ctx/2, typically 224 tokens.
// Byte length / 2 over-counts English so the course hint and early terms fit.
function build_caption_prompt(string $courseHint, array $terms): array
{
    $courseHint = trim($courseHint);
    $clean = [];
    foreach ($terms as $term) {
        $term = trim((string) $term);
        if ($term !== '') {
            $clean[] = $term;
        }
    }
    $limit = 224;
    if ($clean !== [] && caption_prompt_units(caption_prompt_text($courseHint, $clean)) > $limit) {
        $kept = [];
        foreach ($clean as $term) {
            $candidate = $kept;
            $candidate[] = $term;
            if (caption_prompt_units(caption_prompt_text($courseHint, $candidate)) > $limit) {
                break;
            }
            $kept[] = $term;
        }
    } else {
        $kept = $clean;
    }
    $prompt = caption_prompt_text($courseHint, $kept);
    return [
        'prompt' => $prompt === '' ? null : $prompt,
        'kept' => count($kept),
        'total' => count($clean),
        'skipped' => count($clean) - count($kept),
    ];
}

function caption_prompt_text(string $courseHint, array $terms): string
{
    if ($terms === []) {
        return $courseHint;
    }
    return $courseHint . '. Technical vocabulary: ' . implode(', ', $terms);
}

function caption_prompt_units(string $text): int
{
    return intdiv(strlen($text) + 1, 2);
}

// Rules are "incorrect => correct". Longest left-hand side first.
function caption_replacement_rules(string $text): array
{
    $rules = [];
    $lineNo = 0;
    foreach (preg_split("/\r\n|\n|\r/", $text) ?: [] as $line) {
        $lineNo++;
        if (str_contains($line, "\t")) {
            throw new InvalidArgumentException('Replacement line ' . $lineNo . ': tabs are not allowed; use incorrect => correct');
        }
        $stripped = trim($line);
        if ($stripped === '' || str_starts_with($stripped, '#')) {
            continue;
        }
        if (!str_contains($stripped, '=>')) {
            throw new InvalidArgumentException('Replacement line ' . $lineNo . ": expected 'incorrect => correct'");
        }
        [$left, $right] = explode('=>', $stripped, 2);
        $left = trim($left);
        $right = trim($right);
        if ($left === '') {
            throw new InvalidArgumentException('Replacement line ' . $lineNo . ': empty left-hand side');
        }
        if ($left === $right) {
            continue;
        }
        $rules[] = [$left, $right, $lineNo];
    }
    usort($rules, static function (array $a, array $b): int {
        $length = strlen($b[0]) <=> strlen($a[0]);
        return $length !== 0 ? $length : $a[2] <=> $b[2];
    });
    $ordered = [];
    foreach ($rules as $rule) {
        $ordered[] = [$rule[0], $rule[1]];
    }
    return $ordered;
}

function caption_line_is_metadata(string $line): bool
{
    $stripped = trim($line);
    if ($stripped === '' || $stripped === 'WEBVTT') {
        return true;
    }
    if (preg_match('/^(NOTE|STYLE|REGION)(\s|$)/', $stripped)) {
        return true;
    }
    if (preg_match('/^\d+$/', $stripped)) {
        return true;
    }
    return str_contains($stripped, '-->');
}

// Rewrite caption text in a WebVTT file. Timestamps and cue ids are left alone.
function apply_caption_replacements(string $path, string $rulesText): int
{
    $rules = caption_replacement_rules($rulesText);
    if ($rules === []) {
        return 0;
    }
    $text = file_get_contents($path);
    if (!is_string($text)) {
        throw new RuntimeException('Could not read captions');
    }
    if (str_starts_with($text, "\xEF\xBB\xBF")) {
        $text = substr($text, 3);
    }
    $newline = str_contains($text, "\r\n") ? "\r\n" : "\n";
    $ends = str_ends_with($text, $newline);
    $lines = explode($newline, $text);
    if ($ends) {
        array_pop($lines);
    }
    $count = 0;
    foreach ($lines as $index => $line) {
        if (caption_line_is_metadata($line)) {
            continue;
        }
        $updated = $line;
        foreach ($rules as $rule) {
            $found = substr_count($updated, $rule[0]);
            if ($found === 0) {
                continue;
            }
            $count += $found;
            $updated = str_replace($rule[0], $rule[1], $updated);
        }
        $lines[$index] = $updated;
    }
    if ($count === 0) {
        return 0;
    }
    $rendered = implode($newline, $lines);
    if ($ends) {
        $rendered .= $newline;
    }
    if (file_put_contents($path, $rendered) === false) {
        throw new RuntimeException('Could not write captions');
    }
    return $count;
}

redirect_playback_to_public_host();
enforce_public_host_limits();
