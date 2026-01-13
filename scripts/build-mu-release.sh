#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_DIR="$ROOT_DIR/app/public/wp-content/mu-plugins"
OUT_DIR="${1:-$ROOT_DIR/dist}"
VERSION="${2:-}"

if [[ -z "$VERSION" ]]; then
  echo "Usage: $0 <output-dir> <version>" >&2
  exit 1
fi

mkdir -p "$OUT_DIR"

ZIP_NAME="nesta-dashboard-${VERSION}.zip"
ZIP_PATH="$OUT_DIR/$ZIP_NAME"

rm -f "$ZIP_PATH"

(
  cd "$PLUGIN_DIR"
  zip -r "$ZIP_PATH" "nesta-dashboard" "nesta-dashboard.php" \
    -x "*/.DS_Store" -x "*.DS_Store"
)

echo "$ZIP_PATH"
