#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUTPUT="${1:-$ROOT/../mirza-premium-server.zip}"
TEMP_DIR="$(mktemp -d /tmp/mirza-release.XXXXXX)"
trap 'rm -rf -- "$TEMP_DIR"' EXIT

mkdir -p "$TEMP_DIR/mirza-premium"
rsync -a "$ROOT/" "$TEMP_DIR/mirza-premium/" \
  --exclude='.git/' \
  --exclude='.DS_Store' \
  --exclude='config.local.php' \
  --exclude='error_log*' \
  --exclude='log.txt' \
  --exclude='logs/*.log' \
  --exclude='panel/log.txt' \
  --exclude='cronbot/log.txt' \
  --exclude='cronbot/error_log' \
  --exclude='api/hash.txt' \
  --exclude='vpnbot/[0-9]*/'

(
  cd "$TEMP_DIR"
  zip -qr "$OUTPUT" mirza-premium
)
echo "Release created: $OUTPUT"
