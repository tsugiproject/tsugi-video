<?php
// Copy to config.php; adjust paths. Never commit config.php or put it under public/.
return [
    'base_url' => 'https://video.tsugi.org',
    'app_name' => 'Video Server',
    'admin_user' => 'admin',
    'admin_password' => 'CHANGE_ME_TO_A_LONG_PASSWORD',
    'api_token' => 'CHANGE_ME_TO_A_LONG_RANDOM_BEARER_TOKEN',
    'paths' => [
        'storage' => '/efs/video-server', // originals, work and published HLS; USB volume on macOS
        'required_mount' => '/efs', // use '/Volumes/VideoDrive' on Mac, or null for local-only testing
        'database' => __DIR__ . '/var/video.sqlite', // local disk, NOT EFS
        'imports' => ['/efs/video-imports'], // only these roots accepted by path API
        'ffmpeg' => 'ffmpeg',
        'ffprobe' => 'ffprobe',
        'whisper' => 'whisper-cli',
        'whisper_model' => __DIR__ . '/models/ggml-base.en.bin',
        'lock_file' => __DIR__ . '/var/worker.lock',
    ],
    'video' => [
        'height' => 720,
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
