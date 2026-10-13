#!/usr/bin/env bash
# Read the nginx "news" access log (config/nginx/http.conf). No nginx changes.
#
#   scripts/access-log.sh attack [--lines N] [--since 15m|2h|1d] [logfile]
#   scripts/access-log.sh errors|bots|top [same flags] [logfile]
#   scripts/access-log.sh goaccess [term|html|live] [same flags] [logfile]
#
# make log-attack ARGS="--since 15m"
# make log-attack ARGS="errors --since 1h"
# make log-goaccess ARGS="html"
#
# Static files, favicon, /-/health, wp-config, xmlrpc, and dotfiles are
# access_log off, so they never appear here.
set -euo pipefail
export LC_ALL=C

# shellcheck source=lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

GOACCESS_LOG_FORMAT='%h %^[%d:%t %^] "%r" %s %b "%R" "%u" cache=%^ rt=%T urt=%^ ray=%^ bot=%^'
GOACCESS_DATE_FORMAT='%d/%b/%Y'
GOACCESS_TIME_FORMAT='%T'
HTML_OUT="${REPO_DIR}/logs/goaccess.html"
GOACCESS_PORT="${GOACCESS_PORT:-7890}"

TSV=""
META=""
cleanup() {
  if [[ -n "${TSV}" ]]; then rm -f "${TSV}"; fi
  if [[ -n "${META}" ]]; then rm -f "${META}"; fi
}
trap cleanup EXIT

usage() {
  cat <<'EOF'
Usage: scripts/access-log.sh <attack|errors|bots|top|goaccess> [options] [logfile]

  attack    Request rate, status, cache, IPs, paths, user agents, bot=1,
            rt>1s, 5xx, unique URLs, Cloudflare ray, wp-login / wp-json / author=
  errors    5xx and upstream times over 1s
  bots      Requests already marked bot=1
  top       Paths, referrers, and MISS / BYPASS / EXPIRED paths
  goaccess  term (default), html, or live

Options:
  --lines N     Last N lines (default 20000). With --since, limits the scan.
  --since SPEC  Only lines newer than SPEC: 90s, 15m, 2h, 1d.
                Without --lines, the whole file is read.
  logfile       Defaults to NGINX_ACCESS_LOG (/var/log/nginx/access.log).
                A .gz file is read with gzip -dc.

goaccess html writes logs/goaccess.html (not served by nginx).
goaccess live follows the plain log and binds 127.0.0.1 only
(GOACCESS_PORT, default 7890).

A leading flag with no command is attack:
  scripts/access-log.sh --since 15m
EOF
}

cmd="attack"
if [[ $# -gt 0 ]]; then
  case "$1" in
    attack|errors|bots|top|goaccess)
      cmd="$1"
      shift
      ;;
    --help|-h)
      usage
      exit 0
      ;;
    --*)
      cmd="attack"
      ;;
    *)
      echo "unknown command: $1" >&2
      usage >&2
      exit 1
      ;;
  esac
fi

gmode="term"
if [[ "$cmd" == "goaccess" && $# -gt 0 ]]; then
  case "$1" in
    term|html|live)
      gmode="$1"
      shift
      ;;
  esac
fi

LINES=20000
LINES_SET=0
SINCE=""
LOG=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    --lines)
      if [[ $# -lt 2 ]]; then
        echo "missing value for --lines" >&2
        exit 1
      fi
      LINES="$2"
      LINES_SET=1
      shift 2
      ;;
    --since)
      if [[ $# -lt 2 ]]; then
        echo "missing value for --since" >&2
        exit 1
      fi
      SINCE="$2"
      shift 2
      ;;
    --help|-h)
      usage
      exit 0
      ;;
    --)
      shift
      break
      ;;
    -*)
      echo "unknown option: $1" >&2
      exit 1
      ;;
    *)
      if [[ -n "$LOG" ]]; then
        echo "unexpected argument: $1" >&2
        exit 1
      fi
      LOG="$1"
      shift
      ;;
  esac
done
if [[ $# -gt 0 ]]; then
  if [[ -n "$LOG" ]]; then
    echo "unexpected argument: $1" >&2
    exit 1
  fi
  LOG="$1"
fi

if [[ ! "$LINES" =~ ^[1-9][0-9]*$ ]]; then
  echo "--lines expects a positive integer" >&2
  exit 1
fi

CUTOFF=0
if [[ -n "$SINCE" ]]; then
  if [[ ! "$SINCE" =~ ^([0-9]+)([smhd])$ ]]; then
    echo "--since expects a number plus s, m, h, or d (for example 15m)" >&2
    exit 1
  fi
  n="${BASH_REMATCH[1]}"
  unit="${BASH_REMATCH[2]}"
  if [[ "$n" -eq 0 ]]; then
    echo "--since must be greater than 0" >&2
    exit 1
  fi
  case "$unit" in
    s) sec="$n" ;;
    m) sec=$((n * 60)) ;;
    h) sec=$((n * 3600)) ;;
    d) sec=$((n * 86400)) ;;
  esac
  CUTOFF=$(( $(date +%s) - sec ))
fi

if [[ -z "$LOG" ]]; then
  LOG="${NGINX_ACCESS_LOG}"
fi
if [[ ! -f "$LOG" ]]; then
  echo "no access log at ${LOG}" >&2
  exit 1
fi
if [[ ! -r "$LOG" ]]; then
  echo "cannot read ${LOG}" >&2
  exit 1
fi

if [[ -n "$SINCE" && "$LINES_SET" -eq 1 ]]; then
  how="last ${LINES} lines, within ${SINCE}"
elif [[ -n "$SINCE" ]]; then
  how="since ${SINCE}"
else
  how="last ${LINES} lines"
fi

# Emit raw log lines for the selected window. --since scans the whole file
# unless --lines was set explicitly.
feed() {
  if [[ "$LOG" == *.gz ]]; then
    gzip -dc -- "$LOG"
  else
    cat -- "$LOG"
  fi | {
    if [[ "$LINES_SET" -eq 1 || -z "$SINCE" ]]; then
      tail -n "$LINES"
    else
      cat
    fi
  }
}

# mode=tsv writes unit-separated rows to stdout and a meta file.
# mode=raw writes original lines that match the news format.
AWK_PARSE='
function mon(name,    k) {
  k = index("JanFebMarAprMayJunJulAugSepOctNovDec", name)
  if (k == 0 || (k % 3) != 1) return 0
  return int(k / 3) + 1
}
function civil_epoch(y, m, d, H, M, S,    era, yoe, doy, doe, adj) {
  y -= (m <= 2)
  if (y >= 0) era = int(y / 400)
  else era = int((y - 399) / 400)
  yoe = y - era * 400
  if (m > 2) adj = -3
  else adj = 9
  doy = int((153 * (m + adj) + 2) / 5) + d - 1
  doe = yoe * 365 + int(yoe / 4) - int(yoe / 100) + doy
  return (era * 146097 + doe - 719468) * 86400 + H * 3600 + M * 60 + S
}
function epoch_nginx(timestr,    n, parts, datepart, tz, nb, bits, d, mo, y, H, M, S, sign, oh, om, off) {
  n = split(timestr, parts, " ")
  if (n < 1) return -1
  datepart = parts[1]
  tz = "+0000"
  if (n >= 2) tz = parts[2]
  nb = split(datepart, bits, "[/:]")
  if (nb != 6) return -1
  d = bits[1] + 0
  mo = mon(bits[2])
  y = bits[3] + 0
  H = bits[4] + 0
  M = bits[5] + 0
  S = bits[6] + 0
  if (mo < 1 || H > 23 || M > 59 || S > 60) return -1
  off = 0
  if (tz ~ /^[+-][0-9][0-9][0-9][0-9]$/) {
    if (substr(tz, 1, 1) == "-") sign = -1
    else sign = 1
    oh = substr(tz, 2, 2) + 0
    om = substr(tz, 4, 2) + 0
    off = sign * (oh * 3600 + om * 60)
  }
  return civil_epoch(y, mo, d, H, M, S) - off
}
function cut_quoted(rest, result,    j) {
  sub(/^[ ]+/, "", rest)
  if (substr(rest, 1, 1) != "\"") return 0
  j = index(substr(rest, 2), "\"")
  if (j < 1) return 0
  result[1] = substr(rest, 2, j - 1)
  result[2] = substr(rest, j + 2)
  return 1
}
function field_after(s, key, nextkey,    p, q, val) {
  p = index(s, key)
  if (p == 0) return ""
  p += length(key)
  if (nextkey == "") {
    val = substr(s, p)
  } else {
    q = index(substr(s, p), nextkey)
    if (q == 0) return ""
    val = substr(s, p, q - 1)
  }
  sub(/^[ ]+/, "", val)
  sub(/[ ]+$/, "", val)
  return val
}
function clean(s) {
  gsub(/\r/, "", s)
  gsub(/\n/, " ", s)
  gsub(OFS, " ", s)
  return s
}
function parse_line(line,    i, j, rest, req, n, a, k, q, uri, result) {
  if (line == "") return 0
  i = index(line, " ")
  if (i < 2) return 0
  p_ip = substr(line, 1, i - 1)
  i = index(line, "[")
  j = index(line, "]")
  if (i < 1 || j <= i) return 0
  p_time = substr(line, i + 1, j - i - 1)
  p_ep = epoch_nginx(p_time)
  if (p_ep < 0) return 0
  rest = substr(line, j + 1)
  if (!cut_quoted(rest, result)) return 0
  req = result[1]
  rest = result[2]
  n = split(req, a, " ")
  if (n < 3) return 0
  p_method = a[1]
  uri = a[2]
  for (k = 3; k <= n - 1; k++) uri = uri " " a[k]
  p_uri = uri
  sub(/^[ ]+/, "", rest)
  if (!match(rest, /^[0-9][0-9][0-9] ([0-9]+|-)/)) return 0
  split(substr(rest, 1, RLENGTH), a, " ")
  p_status = a[1]
  p_bytes = a[2]
  rest = substr(rest, RLENGTH + 1)
  if (!cut_quoted(rest, result)) return 0
  p_ref = result[1]
  rest = result[2]
  if (!cut_quoted(rest, result)) return 0
  p_ua = result[1]
  rest = result[2]
  sub(/^[ ]+/, "", rest)
  if (index(rest, "cache=") != 1) return 0
  if (index(rest, " rt=") == 0 || index(rest, " urt=") == 0) return 0
  if (index(rest, " ray=") == 0 || index(rest, " bot=") == 0) return 0
  p_cache = field_after(rest, "cache=", " rt=")
  p_rt = field_after(rest, " rt=", " urt=")
  p_urt = field_after(rest, " urt=", " ray=")
  p_ray = field_after(rest, " ray=", " bot=")
  p_bot = field_after(rest, " bot=", "")
  if (p_bot != "0" && p_bot != "1") return 0
  p_path = p_uri
  q = index(p_uri, "?")
  if (q > 0) p_path = substr(p_uri, 1, q - 1)
  if (p_path == "") p_path = "/"
  return 1
}
BEGIN { OFS = sprintf("%c", 31); parsed = 0; skipped = 0; min_e = 0; max_e = 0; min_raw = ""; max_raw = "" }
{
  if (!parse_line($0)) { skipped++; next }
  if (cutoff + 0 > 0 && p_ep < cutoff + 0) next
  if (min_e == 0 || p_ep < min_e) { min_e = p_ep; min_raw = p_time }
  if (p_ep > max_e) { max_e = p_ep; max_raw = p_time }
  if (mode == "raw") { print; parsed++; next }
  parsed++
  row = clean(p_ip) OFS (p_ep + 0) OFS clean(p_time) OFS clean(p_method) OFS clean(p_uri) OFS clean(p_path) OFS p_status OFS clean(p_cache) OFS clean(p_rt) OFS clean(p_urt) OFS clean(p_ray) OFS p_bot OFS clean(p_ua) OFS clean(p_ref)
  print row
}
END {
  if (mode == "tsv") {
    out = sprintf("parsed=%d\nskipped=%d\nmin_epoch=%d\nmax_epoch=%d\nmin_raw=%s\nmax_raw=%s\n", parsed, skipped, min_e + 0, max_e + 0, min_raw, max_raw)
    print out > meta
  } else if (skipped > 0) {
    printf "omitted %d lines that are not in the news log format\n", skipped > "/dev/stderr"
  }
}
'

load_window() {
  TSV=$(mktemp "${TMPDIR:-/tmp}/access-log.XXXXXX")
  META=$(mktemp "${TMPDIR:-/tmp}/access-log.XXXXXX")
  : > "$TSV"
  : > "$META"
  feed | awk -v mode=tsv -v cutoff="$CUTOFF" -v meta="$META" "$AWK_PARSE" > "$TSV"
  parsed=0
  skipped=0
  min_epoch=0
  max_epoch=0
  min_raw=""
  max_raw=""
  while IFS='=' read -r key val; do
    case "$key" in
      parsed) parsed="$val" ;;
      skipped) skipped="$val" ;;
      min_epoch) min_epoch="$val" ;;
      max_epoch) max_epoch="$val" ;;
      min_raw) min_raw="$val" ;;
      max_raw) max_raw="$val" ;;
    esac
  done < "$META"
}

print_header() {
  local rate span
  echo "== access log: ${cmd} =="
  echo "  file: ${LOG}"
  echo "  window: ${how}"
  echo "  parsed: ${parsed}  skipped: ${skipped}"
  if [[ -n "$min_raw" ]]; then
    echo "  from: ${min_raw}"
    echo "  to:   ${max_raw}"
  fi
  if [[ "$parsed" -eq 0 ]]; then
    echo "  (no matching lines)"
    return
  fi
  span=$((max_epoch - min_epoch))
  if [[ "$span" -lt 1 ]]; then
    echo "  rate: n/a (span < 1s)"
  else
    rate=$(awk -v n="$parsed" -v s="$span" 'BEGIN { printf "%.1f req/s over %ds", n / s, s }')
    echo "  rate: ${rate}"
  fi
}

section() {
  echo
  echo "== $1 =="
}

# Rank newline-delimited values. Empty input prints "(none)".
top_n() {
  local n="$1"
  local data="$2"
  if [[ -z "$data" ]]; then
    echo "  (none)"
    return
  fi
  printf '%s\n' "$data" | awk -v n="$n" -v total="$parsed" '
    NR <= n {
      c = $1 + 0
      $1 = ""
      sub(/^ /, "")
      label = $0
      if (label == "") label = "(empty)"
      if (length(label) > 140) label = substr(label, 1, 137) "..."
      pct = 0
      if (total > 0) pct = 100 * c / total
      printf "  %8d  %5.1f%%  %s\n", c, pct, label
    }
  '
}

rank_values() {
  if [[ -z "${1:-}" ]]; then
    return 0
  fi
  printf '%s\n' "$1" | sort | uniq -c | sort -nr
}

# Column extract. $1 is the field number. Optional $2 is bot|5xx|slowrt|slowurt|miss|ref.
collect_field() {
  local field="$1"
  local where="${2:-all}"
  awk -F "$(printf '\037')" -v f="$field" -v where="$where" '
    function num(s) {
      if (match(s, /[0-9]+(\.[0-9]+)?/)) return substr(s, RSTART, RLENGTH) + 0
      return -1
    }
    NF >= 14 {
      ok = 0
      if (where == "all") ok = 1
      else if (where == "5xx" && $7 + 0 >= 500) ok = 1
      else if (where == "slowrt" && num($9) > 1) ok = 1
      else if (where == "slowurt" && num($10) > 1) ok = 1
      else if (where == "bot" && $12 == "1") ok = 1
      else if (where == "miss" && ($8 == "MISS" || $8 == "BYPASS" || $8 == "EXPIRED")) ok = 1
      else if (where == "ref" && $14 != "" && $14 != "-") ok = 1
      else if (where == "404" && $7 + 0 == 404) ok = 1
      if (!ok) next
      v = $f
      if (where == "ref") sub(/\?.*/, "", v)
      if (v == "") v = "(empty)"
      print v
    }
  ' "$TSV"
}

scalars() {
  awk -F "$(printf '\037')" '
    function num(s) {
      if (match(s, /[0-9]+(\.[0-9]+)?/)) return substr(s, RSTART, RLENGTH) + 0
      return -1
    }
    NF >= 14 {
      n++
      ips[$1] = 1
      uris[$5] = 1
      paths[$6] = 1
      if ($12 == "1") bots++
      if ($11 == "" || $11 == "-") noray++
      if (num($9) > 1) slowrt++
      if (num($10) > 1) slowurt++
      if ($7 + 0 >= 500) s5++
      if ($7 + 0 == 404) s404++
      if ($6 ~ /\/wp-login\.php$/) {
        login++
        if ($4 == "POST") login_post++
      }
      if ($6 ~ /^\/wp-json(\/|$)/) wpjson++
      if (index($5, "author=") > 0) author++
      if ($8 == "MISS") miss++
      if ($8 == "BYPASS") bypass++
      if ($8 == "EXPIRED") expired++
    }
    END {
      uips = 0; for (k in ips) uips++
      uuris = 0; for (k in uris) uuris++
      upaths = 0; for (k in paths) upaths++
      printf "unique_ip=%d\nunique_url=%d\nunique_path=%d\n", uips, uuris, upaths
      printf "bots=%d\nnoray=%d\nslowrt=%d\nslowurt=%d\ns5=%d\ns404=%d\n", bots + 0, noray + 0, slowrt + 0, slowurt + 0, s5 + 0, s404 + 0
      printf "login=%d\nlogin_post=%d\nwpjson=%d\nauthor=%d\n", login + 0, login_post + 0, wpjson + 0, author + 0
      printf "miss=%d\nbypass=%d\nexpired=%d\n", miss + 0, bypass + 0, expired + 0
    }
  ' "$TSV"
}

load_scalars() {
  unique_ip=0
  unique_url=0
  unique_path=0
  bots=0
  noray=0
  slowrt=0
  slowurt=0
  s5=0
  s404=0
  login=0
  login_post=0
  wpjson=0
  author=0
  miss=0
  bypass=0
  expired=0
  local key val stats
  stats=$(scalars)
  while IFS='=' read -r key val; do
    case "$key" in
      unique_ip) unique_ip="$val" ;;
      unique_url) unique_url="$val" ;;
      unique_path) unique_path="$val" ;;
      bots) bots="$val" ;;
      noray) noray="$val" ;;
      slowrt) slowrt="$val" ;;
      slowurt) slowurt="$val" ;;
      s5) s5="$val" ;;
      s404) s404="$val" ;;
      login) login="$val" ;;
      login_post) login_post="$val" ;;
      wpjson) wpjson="$val" ;;
      author) author="$val" ;;
      miss) miss="$val" ;;
      bypass) bypass="$val" ;;
      expired) expired="$val" ;;
    esac
  done <<EOF
$stats
EOF
}

pct_of() {
  awk -v n="$1" -v d="$2" 'BEGIN { if (d + 0 == 0) print 0; else printf "%.0f", (100 * n) / d }'
}

print_signals() {
  local fired=0 share frag
  share=$(pct_of $((miss + bypass)) "$parsed")
  frag=0
  if [[ "$unique_path" -gt 0 && "$unique_url" -gt $((unique_path * 3)) ]]; then
    frag=1
  fi
  if [[ "$parsed" -ge 100 && "$share" -ge 20 && "$frag" -eq 1 ]]; then
    if [[ "$fired" -eq 0 ]]; then section "signals"; fired=1; fi
    echo "  Unique URLs are much higher than unique paths, and many miss the cache. Query strings are fragmenting the cache. Check the FPM queue before raising PHP."
  fi
  if [[ "$login_post" -ge 20 || ( "$parsed" -ge 50 && "$(pct_of "$login_post" "$parsed")" -ge 5 ) ]]; then
    if [[ "$fired" -eq 0 ]]; then section "signals"; fired=1; fi
    echo "  POST /wp-login.php is elevated. nginx limit_req zone login is 5 req/min. Confirm the Cloudflare WAF on that path. This report does not ban anyone."
  fi
  if [[ "$noray" -ge 20 && "$(pct_of "$noray" "$parsed")" -ge 10 ]]; then
    if [[ "$fired" -eq 0 ]]; then section "signals"; fired=1; fi
    echo "  Many requests have an empty Cloudflare ray. They did not come through Cloudflare. Check WEB_OPEN and Authenticated Origin Pulls."
  fi
  if [[ "$(pct_of "$bots" "$parsed")" -ge 25 ]]; then
    if [[ "$fired" -eq 0 ]]; then section "signals"; fired=1; fi
    echo "  bot=1 is a large share of this window. Those user agents are already 403 before PHP, so the load to chase is MISS and 5xx."
  fi
  if [[ "$s5" -ge 10 || ( "$parsed" -ge 50 && "$(pct_of "$s5" "$parsed")" -ge 1 ) ]]; then
    if [[ "$fired" -eq 0 ]]; then section "signals"; fired=1; fi
    echo "  5xx is elevated. High urt means PHP-FPM or MariaDB is saturated; nginx is still writing this log."
  fi
}

cmd_attack() {
  print_header
  if [[ "$parsed" -eq 0 ]]; then
    echo
    echo "note: static assets, favicon, /-/health, wp-config, xmlrpc, and dotfiles are access_log off and do not appear."
    return
  fi
  load_scalars
  echo "  unique paths: ${unique_path}   unique urls: ${unique_url}   unique ips: ${unique_ip}"
  echo "  no Cloudflare ray: ${noray} ($(pct_of "$noray" "$parsed")%)   bot=1: ${bots} ($(pct_of "$bots" "$parsed")%)   rt>1s: ${slowrt}   5xx: ${s5}"
  section "status"
  top_n 10 "$(rank_values "$(collect_field 7)")"
  section "cache"
  top_n 10 "$(rank_values "$(collect_field 8)")"
  section "method"
  top_n 10 "$(rank_values "$(collect_field 4)")"
  section "top ips"
  top_n 10 "$(rank_values "$(collect_field 1)")"
  section "top paths"
  top_n 10 "$(rank_values "$(collect_field 6)")"
  section "top user agents"
  top_n 8 "$(rank_values "$(collect_field 13)")"
  section "suspicious paths"
  printf "  %-18s %8d\n" "wp-login.php" "$login"
  printf "  %-18s %8d\n" "POST /wp-login.php" "$login_post"
  printf "  %-18s %8d\n" "/wp-json" "$wpjson"
  printf "  %-18s %8d\n" "author=" "$author"
  if [[ "$s404" -gt 0 ]]; then
    section "top 404 paths"
    top_n 8 "$(rank_values "$(collect_field 6 404)")"
  fi
  section "rt>1s paths"
  if [[ "$slowrt" -eq 0 ]]; then
    echo "  (none)"
  else
    top_n 8 "$(rank_values "$(collect_field 6 slowrt)")"
  fi
  section "5xx paths"
  if [[ "$s5" -eq 0 ]]; then
    echo "  (none)"
  else
    top_n 8 "$(rank_values "$(collect_field 6 5xx)")"
  fi
  print_signals
  echo
  echo "note: static assets, favicon, /-/health, wp-config, xmlrpc, and dotfiles are access_log off and do not appear."
}

cmd_errors() {
  print_header
  if [[ "$parsed" -eq 0 ]]; then
    return
  fi
  load_scalars
  section "5xx"
  echo "  count: ${s5}"
  if [[ "$s5" -eq 0 ]]; then
    echo "  (none)"
  else
    top_n 8 "$(rank_values "$(collect_field 7 5xx)")"
    section "5xx paths"
    top_n 8 "$(rank_values "$(collect_field 6 5xx)")"
    section "5xx sample"
    awk -F "$(printf '\037')" '
      function num(s) {
        if (match(s, /[0-9]+(\.[0-9]+)?/)) return substr(s, RSTART, RLENGTH) + 0
        return -1
      }
      NF >= 14 && $7 + 0 >= 500 {
        printf "%f\037%s\037%s\037%s\037%s\n", num($10), $7, $9, $10, $5
      }
    ' "$TSV" | sort -t "$(printf '\037')" -k1,1nr | awk -F "$(printf '\037')" '
      NR <= 8 { printf "  status=%s rt=%s urt=%s %s\n", $2, $3, $4, $5 }
    '
  fi
  section "slow upstream (urt>1s)"
  echo "  count: ${slowurt}"
  if [[ "$slowurt" -eq 0 ]]; then
    echo "  (none)"
  else
    top_n 8 "$(rank_values "$(collect_field 6 slowurt)")"
    section "slowest upstream"
    awk -F "$(printf '\037')" '
      function num(s) {
        if (match(s, /[0-9]+(\.[0-9]+)?/)) return substr(s, RSTART, RLENGTH) + 0
        return -1
      }
      NF >= 14 && num($10) > 1 {
        printf "%f\037%s\037%s\037%s\037%s\n", num($10), $7, $9, $10, $5
      }
    ' "$TSV" | sort -t "$(printf '\037')" -k1,1nr | awk -F "$(printf '\037')" '
      NR <= 8 { printf "  urt=%s rt=%s status=%s %s\n", $4, $3, $2, $5 }
    '
  fi
}

cmd_bots() {
  print_header
  if [[ "$parsed" -eq 0 ]]; then
    return
  fi
  load_scalars
  section "bot=1"
  echo "  count: ${bots} ($(pct_of "$bots" "$parsed")% of ${parsed})"
  if [[ "$bots" -eq 0 ]]; then
    echo "  (none)"
    return
  fi
  section "bot status"
  top_n 8 "$(rank_values "$(collect_field 7 bot)")"
  section "bot user agents"
  top_n 10 "$(rank_values "$(collect_field 13 bot)")"
  section "bot paths"
  top_n 10 "$(rank_values "$(collect_field 6 bot)")"
}

cmd_top() {
  print_header
  if [[ "$parsed" -eq 0 ]]; then
    return
  fi
  load_scalars
  section "top paths"
  top_n 15 "$(rank_values "$(collect_field 6)")"
  section "top referrers"
  top_n 15 "$(rank_values "$(collect_field 14 ref)")"
  section "top MISS / BYPASS / EXPIRED paths"
  top_n 15 "$(rank_values "$(collect_field 6 miss)")"
}

require_goaccess() {
  if ! command -v goaccess >/dev/null 2>&1; then
    echo "goaccess is not installed" >&2
    exit 1
  fi
}

cmd_goaccess() {
  require_goaccess
  ga_flags=(
    --no-global-config
    --log-format="$GOACCESS_LOG_FORMAT"
    --date-format="$GOACCESS_DATE_FORMAT"
    --time-format="$GOACCESS_TIME_FORMAT"
  )
  case "$gmode" in
    term)
      if [[ ! -t 1 ]]; then
        echo "goaccess term mode needs a terminal. Use html or live, or run attack." >&2
        exit 1
      fi
      feed | awk -v mode=raw -v cutoff="$CUTOFF" "$AWK_PARSE" | goaccess "${ga_flags[@]}" -
      ;;
    html)
      mkdir -p "${REPO_DIR}/logs"
      feed | awk -v mode=raw -v cutoff="$CUTOFF" "$AWK_PARSE" | goaccess "${ga_flags[@]}" -o "$HTML_OUT" -
      echo "wrote ${HTML_OUT}"
      ;;
    live)
      if [[ "$LOG" == *.gz ]]; then
        echo "live mode needs a plain log file, not .gz" >&2
        exit 1
      fi
      if [[ -n "$SINCE" || "$LINES_SET" -eq 1 ]]; then
        echo "live follows the whole file; ignoring --since and --lines" >&2
      fi
      # Read the whole help text. grep -q would SIGPIPE goaccess, and pipefail
      # would then look like --addr is missing.
      help=$(goaccess --help 2>&1 || true)
      case "$help" in
        *--addr*) ;;
        *)
          echo "this goaccess cannot bind the realtime server to 127.0.0.1 (--addr). Use html or term." >&2
          exit 1
          ;;
      esac
      mkdir -p "${REPO_DIR}/logs"
      echo "WebSocket on 127.0.0.1:${GOACCESS_PORT} — open ${HTML_OUT} on this machine only"
      case "$help" in
        *--ws-url*)
          goaccess "${ga_flags[@]}" --real-time-html --addr=127.0.0.1 --port="$GOACCESS_PORT" --ws-url=127.0.0.1 -o "$HTML_OUT" -f "$LOG"
          ;;
        *)
          goaccess "${ga_flags[@]}" --real-time-html --addr=127.0.0.1 --port="$GOACCESS_PORT" -o "$HTML_OUT" -f "$LOG"
          ;;
      esac
      ;;
  esac
}

case "$cmd" in
  attack) load_window; cmd_attack ;;
  errors) load_window; cmd_errors ;;
  bots) load_window; cmd_bots ;;
  top) load_window; cmd_top ;;
  goaccess) cmd_goaccess ;;
esac
