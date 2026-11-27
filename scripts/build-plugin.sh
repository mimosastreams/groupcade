#!/usr/bin/env bash
# Zip the WordPress plugin for upload: dist/groupcade-<version>.zip
set -euo pipefail
cd "$(dirname "$0")/.."
ver=$(sed -n "s/^define( 'GC_VERSION', '\(.*\)' );/\1/p" plugin/groupcade/groupcade.php)
mkdir -p dist
rm -f "dist/groupcade-$ver.zip"
(cd plugin && zip -qr "../dist/groupcade-$ver.zip" groupcade -x '*.DS_Store')
echo "dist/groupcade-$ver.zip"
