#!/usr/bin/env bash
# Zip the WordPress plugin for upload: dist/groupcade-<version>.zip
set -euo pipefail
cd "$(dirname "$0")/.."
plugin=${1:-groupcade}
ver=$(sed -n "s/^ \* Version: *\(.*\)$/\1/p" plugin/$plugin/$plugin.php | head -1)
mkdir -p dist
rm -f "dist/$plugin-$ver.zip"
(cd plugin && zip -qr "../dist/$plugin-$ver.zip" "$plugin" -x '*.DS_Store')
echo "dist/$plugin-$ver.zip"
