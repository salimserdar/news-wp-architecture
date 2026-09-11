#!/usr/bin/env bash
# Quick health view of the cache layers.   scripts/cache-stats.sh [lines]
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
LINES="${1:-20000}"
LOG=logs/nginx/access.log

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
echo "== Redis object cache =="
docker compose exec -T redis redis-cli info stats | awk -F: '
  /keyspace_hits/   { h=$2 } /keyspace_misses/ { m=$2 } /evicted_keys/ { e=$2 }
  END { gsub(/\r/,"",h); gsub(/\r/,"",m); gsub(/\r/,"",e); t=h+m; printf "  hits=%s misses=%s hit-ratio=%.1f%% evicted=%s\n", h, m, (t>0?100*h/t:0), e }'
docker compose exec -T redis redis-cli info memory | grep -E '^(used_memory_human|maxmemory_human)' | sed 's/^/  /'

echo
echo "== nginx cache on disk =="
docker compose exec -T nginx sh -c 'echo "  files: $(find /var/cache/nginx/wp -type f | wc -l)   size: $(du -sh /var/cache/nginx/wp | cut -f1)"'

echo
echo "== Containers =="
docker stats --no-stream --format "  {{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}"
