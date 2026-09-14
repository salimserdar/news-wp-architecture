#!/usr/bin/env bash
# Quick health view of the cache layers.   scripts/cache-stats.sh [lines]
set -euo pipefail
# shellcheck source=lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
cd "${REPO_DIR}"
LINES="${1:-20000}"
LOG="${NGINX_ACCESS_LOG}"

echo "== nginx FastCGI cache — last ${LINES} requests (excluding static) =="
if [[ -s "$LOG" ]]; then
  tail -n "$LINES" "$LOG" | grep -oE 'cache=[A-Z-]+' | grep -v 'cache=STATIC' | sort | uniq -c | sort -rn \
    | awk '{ total += $1; rows[NR] = $0 } END { for (i = 1; i <= NR; i++) { split(rows[i], f, " "); printf "  %-22s %8d  %5.1f%%\n", f[2], f[1], 100 * f[1] / total } }'
  echo
  echo "== Top MISS / BYPASS URLs (should be rare and explainable) =="
  tail -n "$LINES" "$LOG" | grep -E 'cache=(MISS|BYPASS|EXPIRED)' | awk '{print $7}' | sort | uniq -c | sort -rn | head -15
else
  echo "  (no access log yet at $LOG)"
fi

echo
echo "== nginx cache on disk =="
if [[ -d "${NGINX_CACHE}" ]]; then
  echo "  files: $(find "${NGINX_CACHE}" -type f | wc -l | tr -d ' ')   size: $(du -sh "${NGINX_CACHE}" | cut -f1)"
else
  echo "  (no ${NGINX_CACHE})"
fi

echo
echo "== PHP-FPM =="
if command -v cgi-fcgi >/dev/null && [[ -S /run/php/php8.3-fpm.sock ]]; then
  SCRIPT_NAME='/-/fpm-status' SCRIPT_FILENAME='/-/fpm-status' \
  QUERY_STRING='json' REQUEST_METHOD='GET' \
  cgi-fcgi -bind -connect /run/php/php8.3-fpm.sock 2>/dev/null \
    | awk '/"active processes"/ || /"idle processes"/ || /"listen queue"/ { print }'
else
  echo "  processes: $(ps -C php-fpm8.3 --no-headers 2>/dev/null | wc -l | tr -d ' ')"
fi
echo "  RSS: $(ps -C php-fpm8.3 -o rss= 2>/dev/null | awk '{ t += $1 } END { printf "%.0f MB\n", t / 1024 }')"

echo
echo "== MariaDB =="
mysqladmin status 2>/dev/null | sed 's/^/  /' || echo "  (mysqladmin not available)"

echo
echo "== Host =="
uptime | sed 's/^/  /'
free -h | sed 's/^/  /'
