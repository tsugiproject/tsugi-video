<?php
// Copy to config.php; adjust paths. Never commit config.php or put it under public/.
return [
    'base_url' => 'https://video.tsugi.org', // public playback; this host is the cached one
    'manage_url' => 'https://video-manage.tsugi.org', // same app; leave DNS-only so this host is not cached
    'redirect_to' => "https://www.tsugi.org", // optional; the public host sends any other URL here instead of naming this host
    'app_name' => 'Video Server',
    'admin_user' => 'admin',
    'admin_password' => 'CHANGE_ME_TO_A_LONG_PASSWORD',
    'api_token' => 'CHANGE_ME_TO_A_LONG_RANDOM_BEARER_TOKEN',
    'paths' => [
        'storage' => '/efs/video-server', // originals, work and published HLS; USB volume on macOS
        'required_mount' => '/efs', // use '/Volumes/VideoDrive' on Mac, or null for local-only testing
        'database' => __DIR__ . '/var/video.sqlite', // local disk, NOT EFS
        'imports' => ['/efs/video-imports'], // only these roots accepted by path API
        'inbox' => null, // default drop folder for bin/start-inbox.php; pass a path to use another folder
        'ffmpeg' => 'ffmpeg',
        'ffprobe' => 'ffprobe',
        'whisper' => 'whisper-cli',
        'whisper_model' => __DIR__ . '/models/ggml-base.en.bin',
        'lock_file' => __DIR__ . '/var/worker.lock',
    ],
    'video' => [
        'heights' => [1080, 720, 360], // rungs taller than the source are skipped
        'height' => 720, // used only when heights is omitted
        'crf' => 24,
        'preset' => 'veryfast',
        'threads' => 2,
        'segment_seconds' => 6,
        'copy_compatible' => true,
    ],
    'captions' => ['enabled' => true, 'threads' => 2, 'language' => 'en'],
    'uploads' => ['max_bytes' => 5 * 1024 * 1024 * 1024],
    'analytics' => ['enabled' => false], // reserved; MVP performs no playback tracking
];
