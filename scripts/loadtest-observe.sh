#!/usr/bin/env bash
# Sample origin metrics every INTERVAL seconds while k6 runs. Run on the VPS.
#
#   scripts/loadtest-observe.sh
#   INTERVAL=2 OUT=loadtest/results/observe.csv scripts/loadtest-observe.sh
#
# Ctrl-C stops sampling; the CSV is already flushed each row.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

INTERVAL="${INTERVAL:-2}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
OUT="${OUT:-loadtest/results/observe-${STAMP}.csv}"
LOG="${LOG:-logs/nginx/access.log}"

if [[ -f .env ]]; then
  set -a
  # shellcheck disable=SC1091
  source .env
  set +a
fi

mkdir -p "$(dirname "$OUT")"

header="ts,elapsed_s,new_lines,hit,miss,bypass,stale,updating,expired,other,php_cpu,php_mem,php_admin_cpu,php_admin_mem,nginx_cpu,nginx_mem,mariadb_cpu,mariadb_mem,redis_cpu,redis_mem,php_active,php_idle,php_listen_queue,php_max_active,php_slow,admin_active,admin_idle,admin_listen_queue,admin_max_active,admin_slow,redis_hits,redis_misses,redis_hit_pct,redis_evicted,db_threads_running"
echo "$header" > "$OUT"
echo "writing $OUT (every ${INTERVAL}s) — Ctrl-C to stop" >&2

started_at="$(date +%s)"
prev_lines=0
if [[ -f "$LOG" ]]; then
  prev_lines="$(wc -l < "$LOG" | tr -d ' ')"
fi

cleanup() {
  echo >&2
  echo "stopped. csv: $OUT" >&2
}
trap cleanup EXIT
trap 'exit 0' INT TERM

fpm_kv() {
  local svc="$1"
  docker compose exec -T "$svc" php - < scripts/fpm-status.php 2>/dev/null \
    | tr ' ' '\n' | awk -F= '
        $1=="active"{a=$2}
        $1=="idle"{i=$2}
        $1=="listen_queue"{q=$2}
        $1=="max_active"{m=$2}
        $1=="slow"{s=$2}
        END { printf "%s,%s,%s,%s,%s", a+0,i+0,q+0,m+0,s+0 }
      '
}

redis_line() {
  docker compose exec -T redis redis-cli INFO stats 2>/dev/null | awk -F: '
    $1=="keyspace_hits" { h=$2 }
    $1=="keyspace_misses" { m=$2 }
    $1=="evicted_keys" { e=$2 }
    END {
      gsub(/\r/,"",h); gsub(/\r/,"",m); gsub(/\r/,"",e);
      t=h+m;
      pct=(t>0? 100*h/t : 0);
      printf "%s,%s,%.1f,%s", h+0, m+0, pct, e+0
    }'
}

db_threads() {
  if [[ -z "${DB_USER:-}" || -z "${DB_PASSWORD:-}" ]]; then
    echo ""
    return
  fi
  docker compose exec -T -e MYSQL_PWD="$DB_PASSWORD" mariadb \
    mariadb -u"$DB_USER" -N -s -e "SHOW GLOBAL STATUS LIKE 'Threads_running';" 2>/dev/null \
    | awk '{print $NF}'
}

count_cache() {
  # stdin: new access-log lines (mawk-safe; no gawk match(..., a))
  awk '
    {
      if (match($0, /cache=[A-Z-]+/)) {
        s = substr($0, RSTART + 6, RLENGTH - 6)
        if (s == "STATIC") next
        c[s]++
      }
    }
    END {
      other = c["REVALIDATED"] + c["-"]
      printf "%d,%d,%d,%d,%d,%d,%d",
        c["HIT"]+0, c["MISS"]+0, c["BYPASS"]+0, c["STALE"]+0,
        c["UPDATING"]+0, c["EXPIRED"]+0, other+0
    }'
}

cname() {
  docker compose ps --format '{{.Name}} {{.Service}}' 2>/dev/null \
    | awk -v s="$1" '$2==s { print $1; exit }'
}

PHP_CT="$(cname php || true)"
ADMIN_CT="$(cname php-admin || true)"
NGINX_CT="$(cname nginx || true)"
DB_CT="$(cname mariadb || true)"
REDIS_CT="$(cname redis || true)"

pick_stat() {
  local name="$1"
  if [[ -z "$name" ]]; then
    echo ","
    return
  fi
  echo "$2" | awk -v n="$name" '$1==n { print $2","$3; found=1 } END { if (!found) print "," }'
}

echo "ts elapsed hit miss bypass stale upd php_act php_q admin_act db_thr redis_hit% php_cpu nginx_cpu" >&2

while true; do
  now="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  elapsed=$(( $(date +%s) - started_at ))

  total_lines=0
  new_lines=0
  cache_csv="0,0,0,0,0,0,0"
  if [[ -f "$LOG" ]]; then
    total_lines="$(wc -l < "$LOG" | tr -d ' ')"
    new_lines=$((total_lines - prev_lines))
    if (( new_lines < 0 )); then
      new_lines=0
      prev_lines=0
    fi
    if (( new_lines > 0 )); then
      cache_csv="$(tail -n "$new_lines" "$LOG" | count_cache)"
    fi
    prev_lines="$total_lines"
  fi

  stats="$(docker stats --no-stream --format '{{.Name}} {{.CPUPerc}} {{.MemUsage}}' 2>/dev/null | awk '{ gsub(/%/,"",$2); split($3,a,"/"); print $1,$2,a[1] }' || true)"

  php_stat="$(pick_stat "$PHP_CT" "$stats")"
  admin_stat="$(pick_stat "$ADMIN_CT" "$stats")"
  nginx_stat="$(pick_stat "$NGINX_CT" "$stats")"
  db_stat="$(pick_stat "$DB_CT" "$stats")"
  redis_stat="$(pick_stat "$REDIS_CT" "$stats")"

  php_fpm="$(fpm_kv php || echo "0,0,0,0,0")"
  admin_fpm="$(fpm_kv php-admin || echo "0,0,0,0,0")"
  redis_csv="$(redis_line || echo "0,0,0,0")"
  threads="$(db_threads || true)"

  row="$now,$elapsed,$new_lines,$cache_csv,$php_stat,$admin_stat,$nginx_stat,$db_stat,$redis_stat,$php_fpm,$admin_fpm,$redis_csv,${threads:-}"
  echo "$row" >> "$OUT"

  IFS=',' read -r hit miss bypass stale updating expired other <<< "$cache_csv"
  IFS=',' read -r php_active php_idle php_q php_max php_slow <<< "$php_fpm"
  IFS=',' read -r admin_active _ _ _ _ <<< "$admin_fpm"
  IFS=',' read -r _ _ redis_pct _ <<< "$redis_csv"
  php_cpu="${php_stat%%,*}"
  nginx_cpu="${nginx_stat%%,*}"
  printf '%s +%ss  HIT=%s MISS=%s BYPASS=%s STALE=%s UPD=%s  php=%s q=%s admin=%s db=%s redis=%.1f%%  cpu php=%s nginx=%s\n' \
    "$now" "$elapsed" "$hit" "$miss" "$bypass" "$stale" "$updating" \
    "$php_active" "$php_q" "$admin_active" "${threads:--}" "${redis_pct:-0}" \
    "${php_cpu:--}" "${nginx_cpu:--}" >&2

  sleep "$INTERVAL"
done
