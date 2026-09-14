#!/usr/bin/env bash
# Copy an existing site's wp-content (uploads, themes, plugins) into WP_ROOT.
#
#   scripts/import-wp-content.sh /path/to/old/wp-content            # uploads + themes + plugins
#   scripts/import-wp-content.sh /path/to/old/wp-content --uploads-only
#   scripts/import-wp-content.sh import/wp-content.tar.gz           # archive containing wp-content/
#
# What is deliberately NOT copied:
#   - mu-plugins/           -> ours are installed from ./wp/mu-plugins
#   - object-cache.php, advanced-cache.php, db.php  -> old caching drop-ins
#   - cache/, wp-rocket-config/, w3tc-config/, et_cache, litespeed/  -> old page caches
#   - upgrade/, upgrade-temp-backup/
set -euo pipefail
# shellcheck source=lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
cd "${REPO_DIR}"

SRC="${1:-}"; MODE="${2:-all}"
[[ -n "$SRC" ]] || { echo "usage: $0 <old-wp-content-dir | archive.tar.gz|.zip> [--uploads-only]"; exit 1; }
DEST="${WP_ROOT}/wp-content"
[[ -d "${WP_ROOT}/wp-includes" ]] || { echo "${WP_ROOT} has no WordPress core yet — run 'sudo bash scripts/setup-vps.sh' first"; exit 1; }

TMP=""
if [[ -f "$SRC" ]]; then
  TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
  echo "==> Extracting $SRC"
  case "$SRC" in
    *.tar.gz|*.tgz) tar -xzf "$SRC" -C "$TMP" ;;
    *.tar.zst)      tar --zstd -xf "$SRC" -C "$TMP" ;;
    *.tar)          tar -xf "$SRC" -C "$TMP" ;;
    *.zip)          unzip -q "$SRC" -d "$TMP" ;;
    *) echo "unknown archive type"; exit 1 ;;
  esac
  SRC="$(find "$TMP" -type d -name wp-content | head -1)"
  [[ -n "$SRC" ]] || { echo "no wp-content/ directory found inside the archive"; exit 1; }
fi
[[ -d "$SRC/uploads" ]] || echo "!! warning: $SRC/uploads not found"

RSYNC=(rsync -a --info=progress2 --no-owner --no-group
  --exclude 'cache/' --exclude 'wp-rocket-config/' --exclude 'w3tc-config/' --exclude 'et_cache/'
  --exclude 'litespeed/' --exclude 'upgrade/' --exclude 'upgrade-temp-backup/' --exclude '*.log'
  --exclude '.DS_Store')

echo "==> uploads"
mkdir -p "$DEST/uploads"
"${RSYNC[@]}" "$SRC/uploads/" "$DEST/uploads/"

if [[ "$MODE" != "--uploads-only" ]]; then
  for d in themes plugins languages; do
    if [[ -d "$SRC/$d" ]]; then
      echo "==> $d"
      mkdir -p "$DEST/$d"
      "${RSYNC[@]}" --exclude 'index.php' "$SRC/$d/" "$DEST/$d/"
    fi
  done
fi

echo "==> Ownership -> www-data"
if [[ "$(id -u)" -eq 0 ]]; then chown -R www-data:www-data "$DEST"; else sudo chown -R www-data:www-data "$DEST"; fi
find "$DEST" -type d -exec chmod 755 {} + 2>/dev/null || true
find "$DEST" -type f -exec chmod 644 {} + 2>/dev/null || true

echo
echo "Done. Old page-cache plugins are still *registered* in the DB; deactivate them:"
echo "  scripts/post-import.sh"
