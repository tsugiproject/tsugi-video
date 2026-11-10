<?php
declare(strict_types=1);

// Process one queued video. After it completes, start a new worker for the next
// item. That process exits if the lock is held or the queue is empty, and it
// opens that job's own log. A failed job does not start another worker.

require dirname(__DIR__) . '/src/bootstrap.php';

set_time_limit(0);

function logmsg(string $message): void
{
    global $log, $id;
    if ($log !== '') {
        file_put_contents($log, gmdate('c') . ' ' . $message . "\n", FILE_APPEND);
    }
    echo "$id: $message\n";
}

// Run a command without a shell. Stdout can feed a progress callback; stderr is appended to the job log.
function command_run(array $args, ?callable $progress = null): void
{
    global $log;
    $spec = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['file', $log, 'a'],
    ];
    logmsg('Running: ' . implode(' ', array_map(
        static fn (string $arg): string => preg_match('/\s/', $arg) ? escapeshellarg($arg) : $arg,
        $args
    )));
    $process = proc_open($args, $spec, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not launch ' . ($args[0] ?? 'command'));
    }

    stream_set_blocking($pipes[1], true);
    try {
        while (($line = fgets($pipes[1])) !== false) {
            if ($progress) {
                $progress(trim($line));
            }
        }
    } finally {
        fclose($pipes[1]);
    }

    $exit = proc_close($process);
    if ($exit !== 0) {
        throw new RuntimeException(($args[0] ?? 'Command') . ' failed with exit ' . $exit . '; see worker log');
    }
}

function probe(string $source): array
{
    $command = [
        pathof('ffprobe'),
        '-v', 'error',
        '-show_entries', 'format=duration:stream=codec_type,codec_name,width,height',
        '-of', 'json',
        $source,
    ];
    $spec = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $spec, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('ffprobe could not start');
    }

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('Invalid media file: ' . substr($error, 0, 400));
    }

    $data = json_decode($output, true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid ffprobe JSON');
    }
    return $data;
}

function whisper_supports_prompt(): bool
{
    static $supports = null;
    if ($supports !== null) {
        return $supports;
    }
    $spec = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open([pathof('whisper'), '--help'], $spec, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        $supports = false;
        return false;
    }
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    $supports = str_contains((string) $output . (string) $error, '--prompt');
    return $supports;
}

// Map FFmpeg's out_time_us onto the 5%–75% slice of the job progress bar.
function progress_ffmpeg(string $line): void
{
    global $id, $duration;
    if (preg_match('/^out_time_us=(\d+)$/', $line, $matches) && $duration > 0) {
        $fraction = min(1, ((float) $matches[1] / 1e6) / $duration);
        update_job($id, 'processing', 'hls', 5 + 70 * $fraction);
    }
}

// flock works on Ubuntu and macOS. The Linux flock(1) utility does not.
$lock = fopen(pathof('lock_file'), 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

init_db();
require_media_mount();

// Claim one queued job. BEGIN IMMEDIATE locks the SQLite write so two workers cannot take the same row.
$db = db();
$db->exec('BEGIN IMMEDIATE');
try {
    $job = $db->query("SELECT * FROM jobs WHERE status='queued' ORDER BY created_at, id LIMIT 1")->fetch();
    if (!$job) {
        $db->commit();
        exit(0);
    }
    $statement = $db->prepare("UPDATE jobs SET status='processing', stage='probe', progress=1, started_at=CURRENT_TIMESTAMP, finished_at=NULL, updated_at=CURRENT_TIMESTAMP WHERE id=?");
    $statement->execute([$job['id']]);
    $db->commit();
} catch (Throwable $error) {
    $db->rollBack();
    throw $error;
}

$id = $job['id'];
$log = '';

try {
    $work = content_dir('work', $id);
    $final = content_dir('media', $id);
    // A leftover staging directory is from a crashed run. The published tree stays until publish_staged().
    if (is_dir($work)) {
        remove_tree(realpath($work));
    }
    if (!is_dir($work) && !mkdir($work, 0770, true)) {
        throw new RuntimeException('Could not create staging directory');
    }
    // This run's log. The next process sets its own path after it claims a job.
    $log = job_log_path($id);
    logmsg('--- run started: ' . $job['original_name'] . ' ---');
    $probe = probe($job['source_path']);
    $duration = (float) ($probe['format']['duration'] ?? 0);
    if ($duration <= 0) {
        throw new RuntimeException('Source has no readable duration');
    }

    $videoStream = null;
    $audioStream = null;
    foreach (($probe['streams'] ?? []) as $stream) {
        if (($stream['codec_type'] ?? '') === 'video' && !$videoStream) {
            $videoStream = $stream;
        }
        if (($stream['codec_type'] ?? '') === 'audio' && !$audioStream) {
            $audioStream = $stream;
        }
    }
    if (!$videoStream) {
        throw new RuntimeException('Video stream required');
    }

    $statement = db()->prepare('UPDATE jobs SET duration=? WHERE id=?');
    $statement->execute([$duration, $id]);

    $video = cfg()['video'];
    $height = (int) $video['height'];
    // Stream-copy only when the source is already H.264/AAC and not taller than the profile.
    $copy = ($video['copy_compatible'] ?? false)
        && ($videoStream['codec_name'] ?? '') === 'h264'
        && ($audioStream['codec_name'] ?? '') === 'aac'
        && (int) ($videoStream['height'] ?? 9999) <= $height;

    $args = [
        pathof('ffmpeg'),
        '-hide_banner', '-loglevel', 'warning', '-nostdin', '-y',
        '-progress', 'pipe:1', '-stats_period', '2',
        '-i', $job['source_path'],
        '-map', '0:v:0', '-map', '0:a:0?',
    ];
    if ($copy) {
        $args = array_merge($args, ['-c', 'copy']);
        logmsg('HLS stream copy enabled');
    } else {
        // Even height, and never upscale past the source.
        $scale = 'scale=-2:trunc(min(ih\\,' . $height . ')/2)*2';
        $args = array_merge($args, [
            '-vf', $scale,
            '-c:v', 'libx264',
            '-preset', (string) $video['preset'],
            '-crf', (string) $video['crf'],
            '-threads', (string) $video['threads'],
            '-pix_fmt', 'yuv420p',
            '-force_key_frames', 'expr:gte(t,n_forced*' . (int) $video['segment_seconds'] . ')',
            '-c:a', 'aac',
            '-b:a', '128k',
        ]);
        logmsg('HLS transcoding enabled');
    }

    update_job($id, 'processing', 'hls', 5);
    $args = array_merge($args, [
        '-f', 'hls',
        '-hls_time', (string) $video['segment_seconds'],
        '-hls_playlist_type', 'vod',
        '-hls_segment_filename', $work . '/segment%05d.ts',
        $work . '/index.m3u8',
    ]);
    command_run($args, 'progress_ffmpeg');
    if (!is_file($work . '/index.m3u8')) {
        throw new RuntimeException('No HLS playlist generated');
    }

    if ((int) $job['captions'] === 1 && (bool) cfg()['captions']['enabled']) {
        update_job($id, 'processing', 'audio', 77);
        logmsg('Extracting Whisper input');
        $wav = $work . '/audio.wav';
        command_run([
            pathof('ffmpeg'),
            '-hide_banner', '-loglevel', 'error', '-nostdin', '-y',
            '-i', $job['source_path'],
            '-vn', '-ar', '16000', '-ac', '1', '-c:a', 'pcm_s16le',
            $wav,
        ]);

        update_job($id, 'processing', 'whisper', 83);
        logmsg('Generating captions');
        $captions = cfg()['captions'];
        $args = [
            pathof('whisper'),
            '-m', pathof('whisper_model'),
            '-f', $wav,
            '-l', (string) $captions['language'],
            '-t', (string) $captions['threads'],
            '-ovtt',
            '-of', $work . '/captions',
        ];
        // -p is processors in whisper-cli. The vocabulary flag is the long form --prompt.
        $prompt = trim((string) ($job['caption_prompt'] ?? ''));
        if ($prompt !== '') {
            if (whisper_supports_prompt()) {
                $args[] = '--prompt';
                $args[] = $prompt;
                logmsg('Whisper vocabulary prompt (' . strlen($prompt) . ' bytes)');
            } else {
                logmsg('NOTE: whisper-cli has no --prompt; vocabulary will not be used');
            }
        }
        command_run($args);
        @unlink($wav);
        if (!is_file($work . '/captions.vtt')) {
            throw new RuntimeException('Whisper did not produce captions.vtt');
        }
        $rules = (string) ($job['caption_replacements'] ?? '');
        if (trim($rules) !== '') {
            $applied = apply_caption_replacements($work . '/captions.vtt', $rules);
            logmsg('Caption replacements applied: ' . $applied);
        }
    }

    // Check the mount again so a drive unplugged mid-job is not published.
    require_media_mount();
    update_job($id, 'processing', 'publish', 98);

    // Apache only exposes /media. The finished staging tree is renamed into place on this volume.
    publish_staged($work, $final);

    $statement = db()->prepare("UPDATE jobs SET status='completed', stage='completed', progress=100, finished_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id=?");
    $statement->execute([$id]);
    logmsg('Completed');
    echo "Completed $id\n";
} catch (Throwable $error) {
    logmsg('ERROR: ' . $error->getMessage());
    update_job($id, 'failed', 'failed', (float) (job_by_id($id)['progress'] ?? 0), $error->getMessage());
    db()->prepare('UPDATE jobs SET finished_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$id]);
    exit(1);
}

// Drop the lock before the next process starts. If this process still held it,
// the new worker would see the lock and exit without taking the next job.
flock($lock, LOCK_UN);
fclose($lock);
start_worker();
exit(0);
