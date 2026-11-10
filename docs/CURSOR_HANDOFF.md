# Cursor handoff — MVP implementation notes and recommended next steps

Start by reading README.md and config.example.php. The application deliberately has **zero dependencies on Tsugi**. The first deployment hostname is video.tsugi.org, but all installations must work independently.

## Current boundaries

- Public Apache document root is `public/`; static HLS is exposed via Apache Alias `/media/` pointing at configured `storage/media`.
- Private `config.php`, sqlite DB, source originals and processing work are never under the public root.
- API, dashboard, player and worker reuse `src/bootstrap.php`.
- Worker is scheduled via cron or launchd; one job per invocation. A PHP file lock prevents overlap. `BEGIN IMMEDIATE` claims an eligible job.

## Priority follow-ups

1. End-to-end smoke test with MP4 inputs and whisper-cli on target Ubuntu EC2 and macOS (including different binary output naming across whisper.cpp versions).
2. Add robust stale processing recovery, cancellation, retries, delete, free-space/mount checks, worker heartbeat, and more detailed stderr display. Prevent output publication if the underlying USB mount disappears.
3. Add proper admin session authentication/CSRF mechanism or place admin behind external reverse proxy; current MVP uses HTTP Basic with an Origin check, which is a minimal starting point.
4. Improve public player with poster images, full subtitle selection, native playback and verified local hls.js bundled with license. Add accessible UI and iframe resize guidance.
5. Support separate HLS/caption job retry and optional subtitle upload/edit.
6. Add opt-in first-party analytics: play events and heartbeats, aggregate counts, optional coarse IP geolocation and map, with configurable retention. **No analytics in MVP.**
7. Add configurable output profiles and potential ABR ladder. Add API idempotency keys and pagination.
8. Add tests for ffprobe parsing, API authorization, path traversal, job claim and publish behavior. Avoid shell command injection, arbitrary file path reads and directory traversal.

## One important operational constraint

`config.php` includes base URL and host-specific paths. Keep internal SQLite DB and worker lock on local disk, media on EFS or a USB 3 volume. New installations should run `php bin/doctor.php` before touching media. Never assume missing `/Volumes/...` is a valid drive, because macOS can recreate the mountpoint as an ordinary local directory.
