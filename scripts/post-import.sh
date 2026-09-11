#!/usr/bin/env bash
# Run once after the DB and wp-content have been imported (or after a fresh
# install). Idempotent — safe to re-run.
#
# - deactivates page-cache / optimisation plugins that fight with nginx + Redis
# - installs + enables Redis Object Cache (object-cache.php drop-in)
# - removes stale drop-ins, flushes rewrites, verifies cron & cache
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
WP="docker compose run --rm -T wpcli wp"

echo "==> WordPress"
$WP core version --extra

echo "==> Deactivating conflicting cache plugins (if present)"
CONFLICTS=(wp-super-cache w3-total-cache wp-rocket litespeed-cache wp-fastest-cache
           cache-enabler comet-cache hummingbird-performance sg-cachepress breeze
           nginx-helper cloudflare wp-optimize hyper-cache)
for p in "${CONFLICTS[@]}"; do
  if $WP plugin is-active "$p" 2>/dev/null; then
    echo "   deactivating $p"; $WP plugin deactivate "$p"
  fi
done
# Old drop-ins from those plugins
for f in advanced-cache.php object-cache.php db.php; do
  if [[ -f "wordpress/wp-content/$f" ]] && ! grep -q "Redis Object Cache" "wordpress/wp-content/$f" 2>/dev/null; then
    echo "   removing stale drop-in wp-content/$f"
    rm -f "wordpress/wp-content/$f"
  fi
done

echo "==> Redis Object Cache"
$WP plugin is-installed redis-cache 2>/dev/null || $WP plugin install redis-cache
$WP plugin activate redis-cache >/dev/null
$WP redis enable --force >/dev/null 2>&1 || $WP redis enable || true
$WP redis status

echo "==> Permalinks & cron"
$WP rewrite flush
$WP cron event list --fields=hook,next_run_relative --format=table | head -15

echo "==> Purge + warm"
$WP news-cache purge-all

echo
echo "==> Smoke test through nginx"
docker compose exec -T nginx sh -c 'for i in 1 2; do curl -sk -o /dev/null -w "%{http_code} X-FastCGI-Cache=%header{x-fastcgi-cache}\n" -H "Host: $SITE_DOMAIN" https://localhost/; done'
echo "    (expect 200 HIT — the purge above already re-warmed the homepage)"
