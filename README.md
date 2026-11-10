# Video Server — MVP 0.1

Standalone PHP + SQLite video processing, captioning, HLS publishing and iframe player. **No Tsugi dependencies.** First target: `video.tsugi.org` on Ubuntu/Apache/EFS; also designed for macOS/USB volumes.

## Included

- Basic-auth administrative dashboard; upload and status (auto-refresh every five seconds)
- Authenticated Bearer API: enqueue an existing allowlisted server path, upload a file, query jobs, retry failures
- SQLite queue; PHP CLI worker claims one job at a time using a portable `flock()` lock
- FFmpeg HLS ladder at the configured heights that fit the source. A single compatible H.264/AAC rung can be stream-copied
- FFmpeg progress reporting, `ffprobe` metadata, Whisper WebVTT captions
- Atomic publication of complete HLS files (working directory renamed within configured storage root)
- Static media via Apache Alias; public watch and iframe embed pages using local hls.js when installed
- Example Apache vhost, Ubuntu cron, macOS launchd, OS dependency installers and diagnostic script

## Quick start

1. Install PHP 8.1+ with `pdo_sqlite`, FFmpeg, FFprobe, whisper.cpp and a Whisper model. See install notes below.
2. `cp config.example.php config.php` and **edit** `base_url`, credentials, storage, import directories, and binary paths. Use a unique random password and API token. Don't keep `CHANGE_ME` placeholders.
3. Set `paths.required_mount` to `/Volumes/VideoDrive` on macOS or `/efs` on Ubuntu; this is checked on install, enqueue, worker startup, and publication. For local filesystem testing use `null`.
4. `php bin/install.php`; `php bin/doctor.php`.
5. `bash bin/install-hlsjs.sh` to install a local copy of hls.js (the player will not contact an external CDN).
6. Local development: `php -S localhost:8080 -t public public/router.php` and set `base_url` to `http://localhost:8080`; visit that address and authenticate with the configured admin user/password.
7. Upload a short MP4 and run `php bin/worker.php` in another shell. Visit the completed watch/embed URL.
8. For production, configure the Apache example, DNS/HTTPS, access permissions, and cron. Serve ONLY `public/` plus the separate `/media/` Alias. Never publish `config.php`, SQLite DB, originals or work directories.

### Ubuntu dependency setup

`bash bin/install-deps-ubuntu.sh` (requires sudo). Build whisper.cpp, for example:

```sh
git clone https://github.com/ggml-org/whisper.cpp.git
cmake -S whisper.cpp -B whisper.cpp/build -DCMAKE_BUILD_TYPE=Release
cmake --build whisper.cpp/build -j2
cd whisper.cpp && sh ./models/download-ggml-model.sh base.en
```

Set `paths.whisper` to the resulting `whisper-cli` binary and `paths.whisper_model` to the downloaded model. Model paths are intentionally *not* baked into the application. For `medium`, choose `medium`/`medium.en` and budget additional CPU time and RAM.

### macOS dependencies

`bash bin/install-deps-macos.sh` (Homebrew). Build whisper.cpp as above, change model and binary paths, and configure a USB drive root such as `/Volumes/VideoDrive/video-server`. SQLite and the worker lock should remain on the Mac's internal disk. `deploy/mac-launchd.plist.example` provides an optional launchd schedule.

### PHP upload limits

Adjust `upload_max_filesize`, `post_max_size`, `max_execution_time`, `max_input_time`, and potentially Apache `LimitRequestBody` for large browser uploads. Alternatively enqueue videos already on the server using the API; the API imports a managed copy into storage.

## API

All `/api/*` requests require either HTTP Basic admin credentials or a Bearer API token. Browser writes also require an allowed Origin (when supplied). Never expose admin credentials in embedded player pages.

```sh
curl -H 'Authorization: Bearer YOUR_TOKEN' -H 'Content-Type: application/json' \
  -d '{"source":"/efs/video-imports/lecture.mp4","title":"Lecture 1","captions":true}' \
  https://video.tsugi.org/api/jobs
curl -H 'Authorization: Bearer YOUR_TOKEN' https://video.tsugi.org/api/jobs
curl -H 'Authorization: Bearer YOUR_TOKEN' https://video.tsugi.org/api/jobs/VIDEO_ID
curl -H 'Authorization: Bearer YOUR_TOKEN' -H 'Content-Type: application/json' -d '{}' \
  https://video.tsugi.org/api/jobs/VIDEO_ID/retry
curl -H 'Authorization: Bearer YOUR_TOKEN' -F 'file=@lecture.mp4' -F 'title=Lecture 1' -F 'captions=1' \
  https://video.tsugi.org/api/uploads
```

`POST /api/jobs` accepts only real file paths beneath configured `paths.imports` roots (canonical realpaths). The file is copied into managed storage before enqueuing. Restrict API tokens carefully, as enqueueing consumes disk and CPU.

## Status and processing

Job stages are queued, probe, hls, audio, whisper, publish, completed/failed. Progress percent measures FFmpeg media-time during HLS conversion; Whisper presently shows its **stage** rather than numerical percent. A conversion can finish but fail during captions; a retry reruns the job. Per-job logs remain in work directory on failure and published directory on success.

## Inbox

`paths.inbox` is the default drop folder, for example a Dropbox directory. Pass another folder to read that one instead. Put a course there as `media.yaml` plus the videos it names. Entry keys are paths relative to the `media.yaml` folder, such as `lesson-01-welcome/clip.m4v`, including files in subfolders. Also put a whisper vocabulary file (`*whisper-vocabulary*`) and a replacements file (`*whisper-replacements*`, lines of `incorrect => correct`) in that same folder or in a `whisper/` subfolder. A `media.yaml` one folder down is its own course. Absolute `media_root` and `whisper_root` paths from another machine are not followed.

```sh
php bin/start-inbox.php                         # report paths.inbox; does not queue
php bin/start-inbox.php /path/to/this/drop      # report this folder only
php bin/start-inbox.php /path/to/this/drop --apply
php bin/start-inbox.php --limit 1 --apply
```

A run only reports unless you pass `--apply`. It hashes each video and leaves queued, processing, completed, and failed jobs alone. With `--apply`, new files are copied into storage, placed on the collection and logical key from `media.yaml`, and keep that course's vocabulary prompt and replacement rules. Whisper receives the vocabulary as `--prompt`. After transcription, the replacement rules are applied to the caption text and timestamps are left unchanged. One worker is started when anything new is queued. A finished job starts the next worker, which stops if the lock is held or the queue is empty. Cron remains the backstop.

## Captions

A finished video has a Captions link on the dashboard and in the library. Upload a WebVTT file there. English replaces `captions.vtt`. Another language, such as `es`, is stored as `captions-es.vtt` next to the playlist and shown as its own player track. Uploading a language that is already present replaces that track. The cue times in the file are what the player uses.

## Security and operational notes

- Public video URLs have no authentication. Management and API endpoints require credentials.
- No viewer analytics yet. Apache access logs may still collect IP addresses by default; configure retention according to your policy.
- Production should require HTTPS and set restrictive upload limits, a private admin path or additional IP restrictions, and rate limiting. Basic auth is acceptable only over HTTPS.
- Prefer running cron as a dedicated service account, not root. Give it access to config, storage and local SQLite DB; Apache must read media files and execute PHP.
- The current MVP is **single-host**, single-worker, and not intended for multi-host shared SQLite queues. `work` and `media` must be on the same filesystem for atomic rename.
- If a worker is killed or the machine reboots mid-job, its job stays in `processing`. Recovery is currently a **manual database operation** (or a follow-up Cursor task): don't automatically requeue potentially active jobs.
- External USB drive unplugging can lead to storage errors. Check mounts before starting the worker; see `docs/CURSOR_HANDOFF.md` for follow-up requirements.
- The ladder is the even heights in `video.heights` that are not taller than the source. A shorter video keeps its own height as the top rung instead of being scaled up. One compatible H.264/AAC size is stream-copied; a ladder is transcoded so the rungs share keyframe times. Videos already published stay as they are until they are encoded again.
- Browser video upload uses PHP temp space first, so large uploads require suitable PHP temp storage and limits.

## License

Copyright (C) 2026 Charles Severance. GNU Affero General Public License version 3 or any later version; see LICENSE. hls.js and whisper.cpp are separate upstream components and keep their own licenses.
