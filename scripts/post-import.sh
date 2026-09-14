#!/usr/bin/env bash
# Run once after the DB and wp-content have been imported (or after a fresh
# install). Idempotent — safe to re-run.
#
# - deactivates page-cache / optimisation plugins that fight with nginx FastCGI cache
# - removes stale drop-ins, flushes rewrites, verifies cron & cache
set -euo pipefail
# shellcheck source=lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
cd "${REPO_DIR}"

echo "==> WordPress"
wp_cli core version --extra

echo "==> Deactivating conflicting cache plugins (if present)"
CONFLICTS=(wp-super-cache w3-total-cache wp-rocket litespeed-cache wp-fastest-cache
           cache-enabler comet-cache hummingbird-performance sg-cachepress breeze
           nginx-helper cloudflare wp-optimize hyper-cache redis-cache)
for p in "${CONFLICTS[@]}"; do
  if wp_cli plugin is-active "$p" 2>/dev/null; then
    echo "   deactivating $p"; wp_cli plugin deactivate "$p"
  fi
done
for f in advanced-cache.php object-cache.php db.php; do
  if [[ -f "${WP_ROOT}/wp-content/$f" ]]; then
    echo "   removing stale drop-in wp-content/$f"
    rm -f "${WP_ROOT}/wp-content/$f"
  fi
done

echo "==> Permalinks & cron"
wp_cli rewrite flush
wp_cli cron event list --fields=hook,next_run_relative --format=table | head -15

echo "==> Purge + warm"
wp_cli news-cache purge-all

echo
echo "==> Smoke test through nginx"
host="${SITE_DOMAIN:-localhost}"
for i in 1 2; do
  curl -sk -o /dev/null -w "%{http_code} X-FastCGI-Cache=%header{x-fastcgi-cache}\n" \
    -H "Host: ${host}" https://127.0.0.1/
done
echo "    (expect 200 HIT — the purge above already re-warmed the homepage)"
