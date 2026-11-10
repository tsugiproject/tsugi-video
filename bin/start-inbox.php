<?php
declare(strict_types=1);

// Hash the videos named by an inbox media.yaml and queue any SHA-256 this
// server has not already queued or finished. A Dropbox folder is a fine inbox.

require dirname(__DIR__) . '/src/bootstrap.php';

set_time_limit(0);

const INBOX_VIDEO_EXTENSIONS = ['mp4', 'm4v', 'mov', 'webm', 'mkv', 'avi', 'mpg', 'mpeg'];

function out(string $line): void
{
    echo $line, "\n";
    flush();
}

function usage(): void
{
    echo <<<TXT
Usage: php bin/start-inbox.php [PATH] [--enqueue] [--limit N]

Hash the videos named by each media.yaml in the inbox and queue any SHA-256
that is not already queued, processing, or completed.

The inbox is a drop folder (a Dropbox directory works). It holds media.yaml,
the videos that file names (paths are relative to the media.yaml folder,
including subfolders), a whisper vocabulary file, and a whisper replacements
file. A media.yaml one folder down is a separate course.

A run only reports what it would queue. Pass --enqueue to copy videos and
change the queue.

  PATH           Drop folder for this run. Omit it to use paths.inbox in config.php
  --enqueue      Copy new videos into storage and queue them
  --dry-run      Report only. This is already the default
  --limit N      Queue at most N new videos (0 means no limit)

TXT;
}

// The drop folder may come before or after the flags. paths.inbox is the default.
function parse_inbox_args(array $argv): array
{
    $path = null;
    $flagPath = null;
    $sawDry = false;
    $sawApply = false;
    $limit = 0;
    $help = false;
    $args = array_slice($argv, 1);
    $count = count($args);
    for ($index = 0; $index < $count; $index++) {
        $arg = $args[$index];
        if ($arg === '--') {
            for ($rest = $index + 1; $rest < $count; $rest++) {
                if ($path !== null) {
                    throw new InvalidArgumentException('Pass one drop folder, or none to use paths.inbox');
                }
                $path = $args[$rest];
            }
            break;
        }
        if ($arg === '--help' || $arg === '-h') {
            $help = true;
            continue;
        }
        if ($arg === '--dry-run') {
            $sawDry = true;
            continue;
        }
        if ($arg === '--enqueue') {
            $sawApply = true;
            continue;
        }
        if ($arg === '--limit' || $arg === '--inbox') {
            $value = $args[$index + 1] ?? null;
            if (!is_string($value) || $value === '' || str_starts_with($value, '-')) {
                throw new InvalidArgumentException($arg . ' needs a value');
            }
            $index++;
            if ($arg === '--limit') {
                if (!preg_match('/^\d+$/', $value)) {
                    throw new InvalidArgumentException('--limit must be a whole number');
                }
                $limit = (int) $value;
            } else {
                $flagPath = $value;
            }
            continue;
        }
        if (str_starts_with($arg, '--limit=')) {
            $value = substr($arg, strlen('--limit='));
            if (!preg_match('/^\d+$/', $value)) {
                throw new InvalidArgumentException('--limit must be a whole number');
            }
            $limit = (int) $value;
            continue;
        }
        if (str_starts_with($arg, '--inbox=')) {
            $value = substr($arg, strlen('--inbox='));
            if ($value === '') {
                throw new InvalidArgumentException('--inbox needs a value');
            }
            $flagPath = $value;
            continue;
        }
        if (str_starts_with($arg, '-')) {
            throw new InvalidArgumentException('Unknown option: ' . $arg);
        }
        if ($path !== null) {
            throw new InvalidArgumentException('Pass one drop folder, or none to use paths.inbox');
        }
        $path = $arg;
    }
    if ($path !== null && $flagPath !== null && $path !== $flagPath) {
        throw new InvalidArgumentException('Pass the drop folder once');
    }
    if ($sawDry && $sawApply) {
        throw new InvalidArgumentException('Pass either --enqueue or --dry-run');
    }
    return [
        'path' => $path ?? $flagPath ?? '',
        'dry' => !$sawApply,
        'limit' => $limit,
        'help' => $help,
    ];
}

function inbox_directory(string $given): string
{
    if ($given === '') {
        $given = pathof('inbox');
    }
    if ($given === '') {
        throw new InvalidArgumentException('Pass the drop folder, or set paths.inbox in config.php as the default');
    }
    $real = realpath($given);
    if ($real === false || !is_dir($real)) {
        throw new InvalidArgumentException('Inbox folder is missing: ' . $given);
    }
    return $real;
}

function prepare_database_dir(): void
{
    $dir = dirname(pathof('database'));
    if ($dir === '' || $dir === '.') {
        throw new RuntimeException('Database path is missing');
    }
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create ' . $dir);
    }
}

/** @return resource */
function hold_inbox_lock()
{
    $handle = fopen(dirname(pathof('database')) . '/inbox.lock', 'c');
    if (!$handle || !flock($handle, LOCK_EX | LOCK_NB)) {
        fwrite(STDERR, "Another start-inbox is already running.\n");
        exit(0);
    }
    return $handle;
}

function find_inbox_catalogs(string $inbox): array
{
    $found = [];
    if (is_file($inbox . '/media.yaml')) {
        $found[] = $inbox . '/media.yaml';
    }
    $nested = [];
    $handle = opendir($inbox);
    if ($handle === false) {
        throw new RuntimeException('Could not read ' . $inbox);
    }
    while (($name = readdir($handle)) !== false) {
        if ($name === '.' || $name === '..' || str_starts_with($name, '.')) {
            continue;
        }
        $dir = $inbox . '/' . $name;
        if (is_dir($dir) && is_file($dir . '/media.yaml')) {
            $nested[] = $dir . '/media.yaml';
        }
    }
    closedir($handle);
    sort($nested, SORT_STRING);
    return array_merge($found, $nested);
}

function inbox_file_inside(string $inbox, string $path, bool $limitSize): ?string
{
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }
    $real = realpath($path);
    if ($real === false || !is_file($real) || !is_readable($real) || !str_starts_with($real, $inbox . '/')) {
        return null;
    }
    if ($limitSize && filesize($real) !== false && filesize($real) > 2 * 1024 * 1024) {
        throw new InvalidArgumentException('Whisper sidecar is unexpectedly large: ' . $real);
    }
    return $real;
}

function find_inbox_sidecar(string $inbox, string $courseDir, ?string $named, string $needle): ?string
{
    // Named files may sit next to media.yaml or, when the yaml says so, at the
    // inbox root. A course that does not name a file only sees its own folder,
    // so one drop does not inherit another course's rules.
    $courseDirs = [$courseDir];
    if (is_dir($courseDir . '/whisper')) {
        $courseDirs[] = $courseDir . '/whisper';
    }
    $namedDirs = $courseDirs;
    if ($courseDir !== $inbox) {
        $namedDirs[] = $inbox;
        if (is_dir($inbox . '/whisper')) {
            $namedDirs[] = $inbox . '/whisper';
        }
    }
    $relativeName = null;
    if ($named !== null) {
        $named = str_replace('\\', '/', trim($named));
        if ($named !== '' && !str_contains($named, '..') && !str_starts_with($named, '/')) {
            $relativeName = $named;
        } elseif ($named !== '' && !str_contains(basename($named), '..')) {
            $relativeName = basename($named);
        }
    }
    if ($relativeName !== null) {
        foreach ($namedDirs as $dir) {
            $hit = inbox_file_inside($inbox, $dir . '/' . $relativeName, true);
            if ($hit !== null) {
                return $hit;
            }
        }
    }
    foreach ($courseDirs as $dir) {
        $names = [];
        $scan = opendir($dir);
        if ($scan === false) {
            continue;
        }
        while (($name = readdir($scan)) !== false) {
            if ($name === '.' || $name === '..' || str_starts_with($name, '.')) {
                continue;
            }
            if (stripos($name, $needle) === false || !is_file($dir . '/' . $name)) {
                continue;
            }
            $names[] = $name;
        }
        closedir($scan);
        sort($names, SORT_STRING);
        foreach ($names as $name) {
            $hit = inbox_file_inside($inbox, $dir . '/' . $name, true);
            if ($hit !== null) {
                return $hit;
            }
        }
    }
    return null;
}

function read_sidecar(string $path): string
{
    $text = file_get_contents($path);
    if (!is_string($text)) {
        throw new InvalidArgumentException('Could not read ' . $path);
    }
    return preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
}

function scalar_text(mixed $value): string
{
    if (is_string($value)) {
        return trim($value);
    }
    if (is_int($value) || is_float($value)) {
        return trim((string) $value);
    }
    return '';
}

function entry_path_is_unsafe(string $relative): bool
{
    $relative = str_replace('\\', '/', trim($relative));
    if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, "\0")) {
        return true;
    }
    if (str_starts_with($relative, './')) {
        $relative = substr($relative, 2);
    }
    foreach (explode('/', $relative) as $part) {
        if ($part === '' || $part === '.' || $part === '..') {
            return true;
        }
    }
    return false;
}

function resolve_inbox_video(string $inbox, string $courseDir, string $relative): ?string
{
    $relative = str_replace('\\', '/', trim($relative));
    if (str_starts_with($relative, './')) {
        $relative = substr($relative, 2);
    }
    if (entry_path_is_unsafe($relative)) {
        return null;
    }
    return inbox_file_inside($inbox, $courseDir . '/' . $relative, false);
}

/**
 * @param array{queued:int,queued_status:int,processing:int,completed:int,failed:int,missing:int,mismatch:int,unsafe:int,skipped:int} $totals
 */
function ingest_catalog(string $inbox, string $catalog, bool $dry, int $limit, array &$totals, int &$queuedNow): bool
{
    if ($limit > 0 && $totals['queued'] >= $limit) {
        return true;
    }
    out('');
    out('media.yaml: ' . $catalog);
    $text = file_get_contents($catalog);
    if (!is_string($text)) {
        throw new InvalidArgumentException('Could not read ' . $catalog);
    }
    try {
        $doc = parse_media_yaml($text);
    } catch (InvalidArgumentException $error) {
        throw new InvalidArgumentException($catalog . ': ' . $error->getMessage(), 0, $error);
    }
    if (!isset($doc['entries']) || !is_array($doc['entries'])) {
        throw new InvalidArgumentException($catalog . ': entries must be a mapping');
    }

    $courseDir = dirname($catalog);
    $courseHint = scalar_text($doc['course_hint'] ?? '');
    $vocabName = array_key_exists('whisper_vocabulary', $doc) ? scalar_text($doc['whisper_vocabulary']) : null;
    $replaceName = array_key_exists('whisper_replacements', $doc) ? scalar_text($doc['whisper_replacements']) : null;
    $vocabPath = find_inbox_sidecar($inbox, $courseDir, $vocabName !== '' ? $vocabName : null, 'whisper-vocabulary');
    $replacePath = find_inbox_sidecar($inbox, $courseDir, $replaceName !== '' ? $replaceName : null, 'whisper-replacements');

    $terms = [];
    if ($vocabPath === null) {
        out('vocabulary: not found');
    } else {
        $terms = caption_vocabulary_terms(read_sidecar($vocabPath));
    }
    $built = build_caption_prompt($courseHint, $terms);
    if ($vocabPath !== null) {
        $note = $built['kept'] . '/' . $built['total'] . ' terms in the Whisper prompt';
        if ($built['skipped'] > 0) {
            $note .= ', ' . $built['skipped'] . ' skipped for length';
        }
        out('vocabulary: ' . $vocabPath . ' (' . $note . ')');
    }
    if ($courseHint !== '') {
        out('course hint: ' . $courseHint);
    }

    $rulesText = null;
    if ($replacePath === null) {
        out('replacements: not found');
    } else {
        $rulesText = read_sidecar($replacePath);
        $ruleCount = count(caption_replacement_rules($rulesText));
        out('replacements: ' . $replacePath . ' (' . $ruleCount . ' rules)');
    }
    $prompt = $built['prompt'];

    foreach ($doc['entries'] as $rawRelative => $entry) {
        $relative = trim((string) $rawRelative);
        if (!is_array($entry)) {
            out('skip  not an entry  ' . $relative);
            $totals['skipped']++;
            continue;
        }
        if (entry_path_is_unsafe($relative)) {
            out('skip  unsafe path  ' . $relative);
            $totals['unsafe']++;
            continue;
        }
        $path = resolve_inbox_video($inbox, $courseDir, $relative);
        if ($path === null) {
            $outside = realpath($courseDir . '/' . $relative);
            if ($outside !== false && !str_starts_with($outside, $inbox . '/')) {
                out('skip  outside inbox  ' . $relative);
                $totals['unsafe']++;
                continue;
            }
            out('miss  ' . $relative);
            $totals['missing']++;
            continue;
        }
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($extension, INBOX_VIDEO_EXTENSIONS, true)) {
            out('skip  not a video  ' . $relative);
            $totals['skipped']++;
            continue;
        }
        if ($limit > 0 && $totals['queued'] >= $limit) {
            return true;
        }

        out('hash  ' . $relative);
        $hash = hash_file('sha256', $path);
        if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/', $hash)) {
            out('skip  could not hash  ' . $relative);
            $totals['skipped']++;
            continue;
        }
        out('      ' . $hash);

        $mismatched = false;
        $expected = strtolower(scalar_text($entry['sha256'] ?? ''));
        if ($expected !== '' && $expected !== $hash) {
            out('WARN  sha256 in media.yaml does not match the file');
            out('      yaml ' . $expected);
            out('      file ' . $hash);
            $mismatched = true;
        }
        $yamlSize = $entry['size'] ?? null;
        $actualSize = filesize($path);
        if ((is_int($yamlSize) || (is_string($yamlSize) && is_numeric($yamlSize))) && $actualSize !== false && (int) $yamlSize !== (int) $actualSize) {
            out('WARN  size in media.yaml is ' . (int) $yamlSize . ' bytes; file is ' . (int) $actualSize);
            $mismatched = true;
        }
        if ($mismatched) {
            $totals['mismatch']++;
        }

        $title = scalar_text($entry['title'] ?? '');
        if ($title === '') {
            $title = basename($relative);
        }
        $description = scalar_text($entry['description'] ?? '');
        if ($description !== '') {
            out('      description ' . strlen($description) . ' characters');
        }
        $tags = scalar_text($entry['tags'] ?? '');
        $tagList = tag_list($tags);
        if ($tagList !== []) {
            out('      tags ' . implode(', ', $tagList));
        }
        $collection = scalar_text($entry['collection'] ?? '');
        $key = scalar_text($entry['logical_key'] ?? '');
        $place = false;
        if ($collection !== '' || $key !== '') {
            try {
                require_placement_pair($collection, $key);
                $place = true;
            } catch (InvalidArgumentException $error) {
                out('WARN  ' . $relative . ' placement skipped: ' . $error->getMessage());
            }
        }

        $existing = job_by_id($hash);
        if ($existing) {
            $status = (string) $existing['status'];
            if ($status === 'queued' && !$dry) {
                $statement = db()->prepare('UPDATE jobs SET title=?, original_name=? WHERE id=?');
                $statement->execute([$title, $relative, $hash]);
                store_caption_context($hash, $prompt, $rulesText);
            }
            if (!$dry && in_array($status, ['queued', 'processing', 'completed'], true)) {
                store_job_description($hash, $description);
                store_job_tags($hash, $tags);
            }
            if ($status === 'queued' || $status === 'processing' || $status === 'completed') {
                if ($place && !$dry) {
                    $moved = assign_placement($hash, $collection, $key);
                    if ($moved['replaced']) {
                        out('place ' . $moved['collection'] . '/' . $moved['logical_key'] . ' now points at this video');
                    }
                }
                $totals[$status === 'queued' ? 'queued_status' : $status]++;
                out(($dry ? 'would keep' : 'keep') . '  ' . $status . '  ' . $hash . '  ' . $relative);
                continue;
            }
            if ($status === 'failed') {
                $totals['failed']++;
                out('skip  failed  ' . $hash . '  ' . $relative);
                continue;
            }
        }

        $slot = $place ? $collection . '/' . $key . '  ' : '';
        if ($dry) {
            $totals['queued']++;
            out('would queue  ' . $slot . $hash . '  ' . $relative);
            out('            ' . $title);
            continue;
        }

        $job = create_job($path, $relative, $title, true);
        store_job_description($job['id'], $description);
        store_job_tags($job['id'], $tags);
        if ($place) {
            $moved = assign_placement($job['id'], $collection, $key);
            if ($moved['replaced']) {
                out('place ' . $moved['collection'] . '/' . $moved['logical_key'] . ' now points at this video');
            }
        }
        if ((string) $job['status'] === 'queued') {
            store_caption_context($job['id'], $prompt, $rulesText);
            $queuedNow++;
            $totals['queued']++;
            out('queue ' . $slot . $job['id'] . '  ' . $relative);
            out('      ' . $title);
            continue;
        }
        $totals[(string) $job['status'] === 'completed' ? 'completed' : 'queued_status']++;
        out('keep  ' . $job['status'] . '  ' . $job['id'] . '  ' . $relative);
    }
    return false;
}

try {
    $parsed = parse_inbox_args($argv);
} catch (InvalidArgumentException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
if ($parsed['help']) {
    usage();
    exit(0);
}

$dry = $parsed['dry'];
$limit = $parsed['limit'];

$queuedNow = 0;
$exit = 0;
$inboxLock = null;

try {
    $inbox = inbox_directory($parsed['path']);
    prepare_database_dir();
    init_db();
    if (!$dry) {
        require_media_mount();
        if (!is_dir(pathof('storage'))) {
            throw new RuntimeException('Storage directory is missing. Run php bin/install.php');
        }
        $inboxLock = hold_inbox_lock();
    }
    $catalogs = find_inbox_catalogs($inbox);
    if ($catalogs === []) {
        throw new InvalidArgumentException('No media.yaml in ' . $inbox . ' or its first-level folders');
    }

    out('inbox: ' . $inbox);
    if ($dry) {
        out('dry-run');
    }
    $totals = [
        'queued' => 0,
        'queued_status' => 0,
        'processing' => 0,
        'completed' => 0,
        'failed' => 0,
        'missing' => 0,
        'mismatch' => 0,
        'unsafe' => 0,
        'skipped' => 0,
    ];
    $stopped = false;
    foreach ($catalogs as $catalog) {
        if ($stopped) {
            break;
        }
        $stopped = ingest_catalog($inbox, $catalog, $dry, $limit, $totals, $queuedNow);
    }

    out('');
    out(($dry ? 'Would queue: ' : 'Queued: ') . $totals['queued']);
    out('Already queued: ' . $totals['queued_status']);
    out('Already processing: ' . $totals['processing']);
    out('Already completed: ' . $totals['completed']);
    out('Failed, left alone: ' . $totals['failed']);
    out('Missing files: ' . $totals['missing']);
    out('SHA-256 mismatches: ' . $totals['mismatch']);
    if ($totals['unsafe'] > 0) {
        out('Unsafe paths: ' . $totals['unsafe']);
    }
    if ($totals['skipped'] > 0) {
        out('Skipped: ' . $totals['skipped']);
    }
    if ($stopped) {
        out('Stopped after --limit ' . $limit . '.');
    }
    if ($dry && $totals['queued'] > 0) {
        out('Dry-run only. Re-run with --enqueue to queue.');
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'ERROR: ' . $error->getMessage() . "\n");
    $exit = 1;
}

if (!$dry && $queuedNow > 0) {
    start_worker();
    out('Started one worker. Cron keeps going until the queue is empty.');
}

// $inboxLock stays in scope so the flock is held until this process exits.
unset($inboxLock);

exit($exit);
