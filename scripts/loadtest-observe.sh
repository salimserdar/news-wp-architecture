#!/usr/bin/env bash
# Sample origin metrics every INTERVAL seconds while k6 runs. Run on the VPS.
#
#   sudo bash scripts/loadtest-observe.sh
#   INTERVAL=2 OUT=loadtest/results/observe.csv sudo -E bash scripts/loadtest-observe.sh
#
# Ctrl-C stops sampling; the CSV is already flushed each row.
set -euo pipefail
# shellcheck source=lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
cd "${REPO_DIR}"

INTERVAL="${INTERVAL:-2}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
OUT="${OUT:-loadtest/results/observe-${STAMP}.csv}"
LOG="${NGINX_ACCESS_LOG}"
SOCK="${SOCK:-/run/php/php8.3-fpm.sock}"

mkdir -p "$(dirname "$OUT")"

header="ts,elapsed_s,new_lines,hit,miss,bypass,stale,updating,expired,other,s2xx,s3xx,s4xx,s5xx,php_procs,php_rss_mb,load1,load5,mem_avail_mb,swap_used_mb,mysql_threads,fpm_active,fpm_idle,fpm_queue"
echo "$header" > "$OUT"
echo "writing $OUT (every ${INTERVAL}s) — Ctrl-C to stop" >&2
echo "log=$LOG" >&2

started_at="$(date +%s)"
prev_lines=0
if [[ -f "$LOG" ]]; then
  prev_lines="$(wc -l < "$LOG" | tr -d ' ')"
else
  echo "warning: $LOG missing — cache/status columns will stay 0" >&2
fi

cleanup() {
  echo >&2
  echo "stopped. csv: $OUT" >&2
}
trap cleanup EXIT
trap 'exit 0' INT TERM

count_cache() {
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

count_status() {
  awk '
    {
      s = $(NF-4) + 0
      if (s < 100 || s > 599) {
        for (i = 1; i <= NF; i++) if ($i ~ /^[1-5][0-9][0-9]$/) { s = $i + 0; break }
      }
      if (s >= 200 && s < 300) c2++
      else if (s >= 300 && s < 400) c3++
      else if (s >= 400 && s < 500) c4++
      else if (s >= 500 && s < 600) c5++
    }
    END { printf "%d,%d,%d,%d", c2+0, c3+0, c4+0, c5+0 }
  '
}

php_rss_mb() {
  ps -C php-fpm8.3 -o rss= 2>/dev/null | awk '{ t += $1 } END { printf "%.0f", t / 1024 }'
}

php_procs() {
  ps -C php-fpm8.3 --no-headers 2>/dev/null | wc -l | tr -d ' '
}

fpm_line() {
  if [[ ! -S "$SOCK" ]] || ! command -v cgi-fcgi >/dev/null 2>&1; then
    echo ",,"
    return
  fi
  SCRIPT_NAME='/-/fpm-status' SCRIPT_FILENAME='/-/fpm-status' \
  QUERY_STRING='json' REQUEST_METHOD='GET' \
  cgi-fcgi -bind -connect "$SOCK" 2>/dev/null | awk '
    /"active processes"/ { gsub(/[^0-9]/,"",$NF); a=$NF }
    /"idle processes"/ { gsub(/[^0-9]/,"",$NF); i=$NF }
    /"listen queue"/ { gsub(/[^0-9]/,"",$NF); q=$NF }
    END { printf "%s,%s,%s", a, i, q }
  '
}

mysql_threads() {
  mysqladmin status 2>/dev/null | awk -F'Threads: ' '{ print $2+0 }' | awk '{ print $1 }'
}

echo "ts elapsed HIT MISS BYPASS STALE 2xx 5xx php_n load1 fpm_act/q" >&2

while true; do
  now="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  elapsed=$(( $(date +%s) - started_at ))
  new_lines=0
  cache_csv="0,0,0,0,0,0,0"
  st="0,0,0,0"
  if [[ -f "$LOG" ]]; then
    total_lines="$(wc -l < "$LOG" | tr -d ' ')"
    new_lines=$((total_lines - prev_lines))
    if (( new_lines < 0 )); then new_lines=0; prev_lines=0; fi
    if (( new_lines > 0 )); then
      chunk="$(tail -n "$new_lines" "$LOG")"
      cache_csv="$(printf '%s\n' "$chunk" | count_cache)"
      st="$(printf '%s\n' "$chunk" | count_status)"
    fi
    prev_lines="$total_lines"
  fi

  load1="$(awk '{print $1}' /proc/loadavg)"
  load5="$(awk '{print $2}' /proc/loadavg)"
  mem_avail="$(awk '/MemAvailable/ { printf "%.0f", $2/1024 }' /proc/meminfo)"
  swap_used="$(awk '/SwapTotal/ { t=$2 } /SwapFree/ { f=$2 } END { printf "%.0f", (t-f)/1024 }' /proc/meminfo)"
  nphp="$(php_procs)"
  rss="$(php_rss_mb)"
  mthr="$(mysql_threads || true)"
  fpm="$(fpm_line || echo ",,")"

  echo "$now,$elapsed,$new_lines,$cache_csv,$st,$nphp,${rss:-0},$load1,$load5,$mem_avail,$swap_used,${mthr:-},$fpm" >> "$OUT"

  IFS=',' read -r hit miss bypass stale updating expired other <<< "$cache_csv"
  IFS=',' read -r s2 s3 s4 s5 <<< "$st"
  IFS=',' read -r fact fidle fq <<< "$fpm"
  printf '%s +%ss  HIT=%s MISS=%s BYPASS=%s STALE=%s  2xx=%s 5xx=%s  php=%s  load=%s  fpm_act=%s q=%s\n' \
    "$now" "$elapsed" "$hit" "$miss" "$bypass" "$stale" "$s2" "$s5" "$nphp" "$load1" "${fact:--}" "${fq:--}" >&2

  sleep "$INTERVAL"
done
