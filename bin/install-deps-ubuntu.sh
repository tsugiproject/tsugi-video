#!/usr/bin/env bash
set -euo pipefail
sudo apt-get update
sudo apt-get install -y php-cli php-sqlite3 php-mbstring php-xml ffmpeg git cmake build-essential libomp-dev curl apache2
cat <<'EOF'
System packages installed.
Build whisper.cpp separately (see README), then set paths.whisper and paths.whisper_model in config.php.
EOF
