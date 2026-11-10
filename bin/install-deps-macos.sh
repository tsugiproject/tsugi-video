#!/usr/bin/env bash
set -euo pipefail
if ! command -v brew >/dev/null; then echo 'Homebrew required: https://brew.sh' >&2;exit 1;fi
brew install php ffmpeg cmake git libomp
printf 'Dependencies installed. Build whisper.cpp (README), then configure config.php.\n'
