#!/usr/bin/env bash
# Import an existing WordPress database dump and rewrite the old domain to SITE_DOMAIN.
#
#   scripts/import-db.sh import/site.sql.gz [old-domain.com]
#   scripts/import-db.sh --yes import/site.sql.gz [old-domain.com]
#
# - Accepts .sql, .sql.gz, .sql.zst, .sql.bz2 — put the file in ./import/
# - Fixes MySQL 8 -> MariaDB incompatibilities (utf8mb4_0900_* collations, GTID lines)
# - phpMyAdmin dumps CREATE without keys then ALTER TABLE ADD PRIMARY KEY at the end.
#   Duplicate id=0 rows abort that first ALTER — we skip Action Scheduler *row data*
#   (not content; jobs are recreated), remap any remaining duplicate zeros, then
#   apply the keys. Set IMPORT_ACTION_SCHEDULER=1 to keep those queue rows.
# - Stops PHP-FPM during import and always starts it again on success or failure.
# - Replaces ALL existing tables in DB_NAME (asks for confirmation)
# - Runs wp search-replace old-domain -> SITE_DOMAIN (http and https), flushes caches
set -euo pipefail
# shellcheck source=lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
cd "${REPO_DIR}"

YES=0
INDEXES_ONLY=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --yes|-y) YES=1; shift ;;
    --indexes-only) INDEXES_ONLY=1; shift ;;
    -*) echo "usage: $0 [--yes] [--indexes-only] import/<dump.sql[.gz|.zst|.bz2]> [old-domain.com]" >&2; exit 1 ;;
    *) break ;;
  esac
done

[[ -n "${DB_NAME:-}" ]] || { echo "missing DB_NAME (.env or /root/news-wp-credentials)"; exit 1; }
[[ -n "${SITE_DOMAIN:-}" ]] || { echo "missing SITE_DOMAIN in .env"; exit 1; }

DUMP="${1:-}"; OLD_DOMAIN="${2:-}"
[[ -n "$DUMP" && -f "$DUMP" ]] || { echo "usage: $0 [--yes] [--indexes-only] import/<dump.sql[.gz|.zst|.bz2]> [old-domain.com]"; exit 1; }

case "$DUMP" in
  *.gz)  CAT="gzip -dc" ;;
  *.zst) CAT="zstd -dc" ;;
  *.bz2) CAT="bzip2 -dc" ;;
  *)     CAT="cat" ;;
esac

systemctl is-active --quiet mariadb || { echo "MariaDB is not running"; exit 1; }

sanitize_dump() {
  sed -e 's/utf8mb4_0900_ai_ci/utf8mb4_unicode_ci/g' \
      -e 's/utf8mb4_0900_as_cs/utf8mb4_bin/g' \
      -e 's/utf8mb4_0900_bin/utf8mb4_bin/g' \
      -e '/^SET @@GLOBAL.GTID_PURGED/d' \
      -e '/^SET @@SESSION.SQL_LOG_BIN/d' \
      -e '/^START TRANSACTION;/d' \
      -e '/^COMMIT;/d'
}

mysql_import() {
  mysql --binary-mode --max_allowed_packet=1G "${DB_NAME}"
}

kill_db_clients() {
  local id
  while read -r id; do
    [[ -n "$id" ]] || continue
    mysql -e "KILL ${id}" >/dev/null 2>&1 || true
  done < <(mysql -N -e "SELECT id FROM information_schema.processlist
    WHERE db='${DB_NAME}' AND id <> CONNECTION_ID()")
}

restore_services() {
  if [[ -f /usr/lib/systemd/system/php8.3-fpm.service ]]; then
    systemctl start php8.3-fpm 2>/dev/null || true
  fi
}

# phpMyAdmin "quick" dumps: CREATE TABLE (no keys) + INSERT, then ALTER TABLE ADD KEY.
# Do not `tail | grep -q` under pipefail: grep -q exits early, tail gets SIGPIPE (141).
deferred_indexes=0
if [[ "$CAT" == "cat" ]]; then
  if grep -a -q '^-- Indexes for dumped tables' < <(tail -n 30000 "$DUMP"); then
    deferred_indexes=1
  fi
elif grep -a -q '^-- Indexes for dumped tables' < <($CAT "$DUMP"); then
  deferred_indexes=1
fi

pk_pairs_from_dump() {
  $CAT "$DUMP" | awk '
    /^-- Indexes for dumped tables/ { p=1 }
    /^-- AUTO_INCREMENT for dumped tables/ { p=0 }
    /^-- Constraints for dumped tables/ { p=0 }
    p
  ' | perl -0777 -ne '
    while (/ALTER TABLE `([^`]+)`\s+ADD PRIMARY KEY \(`([^`]+)`\)[,;]/g) {
      print "$1\t$2\n";
    }
  '
}

# Duplicate action_id=0 (etc.) cannot become a PRIMARY KEY.
remap_duplicate_zero_pks() {
  local table col zeros dtype
  echo "==> Remapping duplicate 0 primary-key values"
  while IFS=$'\t' read -r table col; do
    [[ -n "$table" && -n "$col" ]] || continue
    dtype="$(mysql -N -e "SELECT DATA_TYPE FROM information_schema.columns
      WHERE table_schema='${DB_NAME}' AND table_name='${table}' AND column_name='${col}'")"
    if [[ -z "$dtype" ]]; then
      echo "    skip missing ${table}"
      continue
    fi
    case "$dtype" in
      tinyint|smallint|mediumint|int|bigint|integer) ;;
      *) continue ;;
    esac
    zeros="$(mysql -N "${DB_NAME}" -e "SELECT COUNT(*) FROM \`${table}\` WHERE \`${col}\`=0")"
    if [[ "${zeros:-0}" -gt 1 ]]; then
      echo "    ${table}.${col}: ${zeros} rows with 0 → unique ids"
      mysql "${DB_NAME}" -e "
        SET @m := (SELECT IFNULL(MAX(\`${col}\`),0) FROM \`${table}\`);
        UPDATE \`${table}\` SET \`${col}\` = (@m := @m + 1) WHERE \`${col}\` = 0;
      "
    fi
  done < <(pk_pairs_from_dump)
}

apply_deferred_indexes() {
  echo "==> Applying indexes / AUTO_INCREMENT from dump (this can take a while)"
  echo "    killing other DB sessions so ALTER TABLE can lock"
  kill_db_clients

  local tmp
  tmp="$(mktemp)"
  $CAT "$DUMP" | awk '
    /^-- Indexes for dumped tables/ { p=1 }
    /^-- Constraints for dumped tables/ { p=0 }
    p
  ' | sanitize_dump > "$tmp"

  DB_NAME="${DB_NAME}" python3 - "$tmp" <<'PY'
import os, re, subprocess, sys

db = os.environ["DB_NAME"]
sql = open(sys.argv[1], encoding="utf-8", errors="replace").read()
stmts = [s.strip() for s in re.split(r";\s*\n", sql) if s.strip()]

def mysql_n(query: str) -> str:
    return subprocess.check_output(["mysql", "-N", "-e", query], text=True).strip()

for stmt in stmts:
    if not re.search(r"\bALTER\s+TABLE\b", stmt, re.I):
        continue
    m = re.search(r"ALTER TABLE `([^`]+)`", stmt)
    if not m:
        continue
    table = m.group(1)
    exists = mysql_n(
        f"SELECT COUNT(*) FROM information_schema.tables "
        f"WHERE table_schema='{db}' AND table_name='{table}'"
    )
    if exists == "0":
        print(f"    skip missing {table}", flush=True)
        continue
    if re.search(r"ADD PRIMARY KEY", stmt, re.I):
        has_pk = mysql_n(
            f"SELECT COUNT(*) FROM information_schema.statistics "
            f"WHERE table_schema='{db}' AND table_name='{table}' AND index_name='PRIMARY'"
        )
        if has_pk != "0":
            print(f"    skip existing PK {table}", flush=True)
            continue
    print(f"    ALTER {table}", flush=True)
    # Remapped id=0 rows sit above the dump's AUTO_INCREMENT=N; forcing N
    # makes MariaDB resequence and hit Duplicate entry.
    stmt = re.sub(r",\s*AUTO_INCREMENT=\d+", "", stmt, flags=re.I)
    proc = subprocess.run(["mysql", db, "-e", stmt + ";"], capture_output=True, text=True)
    if proc.returncode != 0:
        err = proc.stderr
        if any(s in err for s in (
            "Duplicate key name",
            "Multiple primary key defined",
            "Duplicate column name",
            "already exists",
        )):
            print(f"    skip already-applied {table}", flush=True)
            continue
        sys.stderr.write(err)
        sys.exit(proc.returncode)
print("    indexes done", flush=True)
PY
  rm -f "$tmp"
}

PREFIX_IN_DUMP="$($CAT "$DUMP" 2>/dev/null | grep -m1 -oE 'CREATE TABLE `?[A-Za-z0-9_]+_options`?' | sed -E 's/CREATE TABLE `?([A-Za-z0-9_]+_)options`?/\1/' || true)"
if [[ -n "$PREFIX_IN_DUMP" && "$PREFIX_IN_DUMP" != "${DB_TABLE_PREFIX:-wp_}" ]]; then
  echo "!! Dump uses table prefix '${PREFIX_IN_DUMP}' but .env has DB_TABLE_PREFIX='${DB_TABLE_PREFIX:-wp_}'."
  echo "   Set DB_TABLE_PREFIX=${PREFIX_IN_DUMP} in .env and retry."
  exit 1
fi

lock="${REPO_DIR}/.import-db.lock"
exec 9>"$lock"
if ! flock -n 9; then
  echo "!! another import-db.sh is already running (lock ${lock})" >&2
  exit 1
fi

trap restore_services EXIT

if [[ "$INDEXES_ONLY" -eq 1 ]]; then
  echo "==> --indexes-only: keep rows in '${DB_NAME}', apply remaining keys from ${DUMP}"
  [[ "$deferred_indexes" -eq 1 ]] || { echo "dump has no deferred phpMyAdmin index section"; exit 1; }
  if systemctl is-active --quiet php8.3-fpm; then
    echo "==> Stopping php8.3-fpm during ALTER TABLE"
    systemctl stop php8.3-fpm
  fi
  remap_duplicate_zero_pks
  apply_deferred_indexes
else
  echo "==> This will DROP all tables in database '${DB_NAME}' and import ${DUMP}"
  if [[ "$YES" -ne 1 ]]; then
    read -r -p "    Continue? [y/N] " ok; [[ "$ok" == [yY] ]] || exit 1
  fi
  if systemctl is-active --quiet php8.3-fpm; then
    echo "==> Stopping php8.3-fpm during import (site will 502 until this finishes)"
    systemctl stop php8.3-fpm
  fi
  kill_db_clients

  echo "==> Recreating database '${DB_NAME}' (drops all tables)"
  mysql -e "SET FOREIGN_KEY_CHECKS=0; DROP DATABASE IF EXISTS \`${DB_NAME}\`; CREATE DATABASE \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

echo "==> Importing (this can take a while for large dumps)"
if [[ "${IMPORT_ACTION_SCHEDULER:-}" == "1" ]]; then
  echo "    IMPORT_ACTION_SCHEDULER=1 — keeping Action Scheduler queue rows"
else
  echo "    skipping Action Scheduler queue rows (not content; avoids duplicate id=0)"
fi
if [[ "$deferred_indexes" -eq 1 ]]; then
  echo "    phpMyAdmin dump: load rows first, then keys"
  {
    printf '%s\n' "SET SESSION sql_mode='NO_AUTO_VALUE_ON_ZERO,NO_ENGINE_SUBSTITUTION';" \
                  "SET FOREIGN_KEY_CHECKS=0;" "SET UNIQUE_CHECKS=0;"
    keep_as=0
    [[ "${IMPORT_ACTION_SCHEDULER:-}" == "1" ]] && keep_as=1
    $CAT "$DUMP" | keep_as="$keep_as" awk '
      /^-- Indexes for dumped tables/ { p=1 }
      p { next }
      /^-- Dumping data for table `[^`]*actionscheduler_(actions|logs|claims)`/ {
        if (ENVIRON["keep_as"] == "1") { print; next }
        skip=1; next
      }
      /^-- Table structure for table / { skip=0 }
      /^-- Dumping data for table / { skip=0 }
      skip { next }
      { print }
    ' | sanitize_dump
  } | mysql_import
  remap_duplicate_zero_pks
  apply_deferred_indexes
else
  keep_as=0
  [[ "${IMPORT_ACTION_SCHEDULER:-}" == "1" ]] && keep_as=1
  $CAT "$DUMP" | keep_as="$keep_as" awk '
    /^-- Dumping data for table `[^`]*actionscheduler_(actions|logs|claims)`/ {
      if (ENVIRON["keep_as"] == "1") { print; next }
      skip=1; next
    }
    /^-- Table structure for table / { skip=0 }
    /^-- Dumping data for table / { skip=0 }
    skip { next }
    { print }
  ' | sanitize_dump | mysql_import
  fi
fi

echo "==> Tables imported:"
mysql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}'"
mysql -N -e "SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema='${DB_NAME}' AND index_name='PRIMARY'" | awk '{print "    tables with PRIMARY KEY: "$1}'

if [[ -n "$OLD_DOMAIN" ]]; then
  echo "==> Rewriting ${OLD_DOMAIN} -> ${SITE_DOMAIN}"
  for scheme in https http; do
    wp_cli search-replace "${scheme}://${OLD_DOMAIN}" "https://${SITE_DOMAIN}" --all-tables --precise --skip-columns=guid --report-changed-only
  done
  wp_cli search-replace "//${OLD_DOMAIN}" "//${SITE_DOMAIN}" --all-tables --precise --skip-columns=guid --report-changed-only
fi

echo "==> Post-import housekeeping"
wp_cli option update home    "https://${SITE_DOMAIN}" >/dev/null || true
wp_cli option update siteurl "https://${SITE_DOMAIN}" >/dev/null || true
wp_cli cache flush >/dev/null 2>&1 || true
wp_cli rewrite flush         >/dev/null 2>&1 || true
wp_cli core update-db        2>/dev/null || true
wp_cli news-cache purge-all  2>/dev/null || true

restore_services

echo
echo "Done. Verify:  scripts/wp.sh option get siteurl ; scripts/wp.sh post list --post_status=publish --posts_per_page=3"
