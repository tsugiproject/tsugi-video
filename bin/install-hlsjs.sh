#!/usr/bin/env bash
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
# Apache-2.0 hls.js, pinned version; downloaded onto the application server,
# ensuring viewers do NOT contact a third-party CDN at playback time.
version='1.6.15'
curl --fail --location --show-error --retry 2 "https://cdn.jsdelivr.net/npm/hls.js@${version}/dist/hls.min.js" -o "$root/public/assets/hls.min.js"
echo "Installed hls.js ${version} locally. Check LICENSE upstream: https://github.com/video-dev/hls.js"
