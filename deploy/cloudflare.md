# Cloudflare

Use this only when the server has two hosts: one that serves video and one for admin. That is when `manage_url` is a different host from `base_url`. With a single host, skip this page.

For example  if, `video.tsugi.org` is the serving host and is proxied. `video-manage.tsugi.org` is the admin host and is DNS only, so that name shows the server address and Cloudflare does not sit in front of the dashboard or the API. Both names use the same Apache vhost.

The app does not send a cache lifetime for playlists, segments, or files under `/assets/`. One Cache Rule stores them. Watch and embed pages stay uncached: a logical-key address changes when a new file is published there, and the page HTML changes when a caption track is added. Caption files (`.vtt`) stay uncached because an upload replaces the same URL.

## Cache rule

**Caching → Cache Rules.** One rule:

```
(http.host eq "video.tsugi.org" and (starts_with(http.request.uri.path, "/assets/") or (starts_with(http.request.uri.path, "/media/") and not ends_with(http.request.uri.path, ".vtt"))))
```

- Cache eligibility: Eligible for cache
- Edge TTL: Ignore cache-control header and use this TTL, 30 days
- Browser TTL: Override origin and use this TTL, 1 day
- Status code TTL: `404` set to Do not cache

A second request for a playlist, a `.ts` segment, or a file under `/assets/` should show `cf-cache-status: HIT`. Watch and embed pages, and `captions.vtt`, stay `DYNAMIC`.

Most pages load `/assets/` with `?v=` set to the file's modification time, so a changed file is a new URL. The watch page does not do that for `style.css` or `hls.min.js`. A change to those two waits out the edge TTL.

## Authorization header

Cloudflare does not store a response when the request includes an `Authorization` header. The status is `BYPASS`, and every playlist and segment goes back to the server. The public host does not use that header.

**Rules → Transform Rules → Modify Request Header**, on `video.tsugi.org` only: Remove `Authorization`.

A browser that once signed in on the public name keeps sending that header until its saved login for the site is cleared. Playback still works. The files are just not cached.
