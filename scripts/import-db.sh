#!/usr/bin/env bash
# Import an existing WordPress database dump and rewrite the old domain to SITE_DOMAIN.
#
#   scripts/import-db.sh import/site.sql.gz [old-domain.com]
#   scripts/import-db.sh --resume-indexes import/site.sql [old-domain.com]
#
# - Accepts .sql, .sql.gz, .sql.zst, .sql.bz2 — put the file in ./import/
# - Fixes MySQL 8 -> MariaDB incompatibilities (utf8mb4_0900_* collations, GTID lines)
# - phpMyAdmin dumps CREATE without keys then ALTER TABLE ADD PRIMARY KEY at the end.
#   Duplicate id=0 rows (SQL_MODE=NO_AUTO_VALUE_ON_ZERO) abort that first ALTER and
#   leave every table without indexes — we remap those zeros, then apply the keys.
# - Replaces ALL existing tables in DB_NAME (asks for confirmation)
# - Runs wp search-replace old-domain -> SITE_DOMAIN (http and https), flushes caches
set -euo pipefail
# shellcheck source=lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
cd "${REPO_DIR}"

RESUME_INDEXES=0
if [[ "${1:-}" == "--resume-indexes" ]]; then
  RESUME_INDEXES=1
  shift
fi

[[ -n "${DB_NAME:-}" ]] || { echo "missing DB_NAME (.env or /root/news-wp-credentials)"; exit 1; }
[[ -n "${SITE_DOMAIN:-}" ]] || { echo "missing SITE_DOMAIN in .env"; exit 1; }

DUMP="${1:-}"; OLD_DOMAIN="${2:-}"
[[ -n "$DUMP" && -f "$DUMP" ]] || { echo "usage: $0 [--resume-indexes] import/<dump.sql[.gz|.zst|.bz2]> [old-domain.com]"; exit 1; }

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
      -e '/^SET @@SESSION.SQL_LOG_BIN/d'
}

mysql_import() {
  mysql --max_allowed_packet=256M "${DB_NAME}"
}

# phpMyAdmin "quick" dumps: CREATE TABLE (no keys) + INSERT, then ALTER TABLE ADD KEY.
deferred_indexes=0
if [[ "$CAT" == "cat" ]] && tail -n 20000 "$DUMP" | grep -q '^-- Indexes for dumped tables$'; then
  deferred_indexes=1
elif [[ "$CAT" != "cat" ]] && $CAT "$DUMP" | grep -q '^-- Indexes for dumped tables$'; then
  deferred_indexes=1
fi

# Single-column integer PRIMARY KEYs from the dump's ALTER section.
pk_pairs_from_dump() {
  $CAT "$DUMP" | awk '
    /^-- Indexes for dumped tables$/ { p=1 }
    /^-- AUTO_INCREMENT for dumped tables$/ { p=0 }
    /^-- Constraints for dumped tables$/ { p=0 }
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
  if systemctl is-active --quiet php8.3-fpm; then
    echo "    stopping php8.3-fpm so ALTER TABLE can lock"
    systemctl stop php8.3-fpm
    trap 'systemctl start php8.3-fpm 2>/dev/null || true' EXIT
  fi
  mysql -N -e "SELECT id FROM information_schema.processlist
    WHERE db='${DB_NAME}' AND id <> CONNECTION_ID() AND command <> 'Sleep'" \
    | while read -r id; do mysql -e "KILL ${id}" >/dev/null 2>&1 || true; done

  $CAT "$DUMP" | awk '
    /^-- Indexes for dumped tables$/ { p=1 }
    /^-- Constraints for dumped tables$/ { p=0 }
    p
  ' | sanitize_dump | mysql_import
}

# Sanity check: table prefix in the dump vs .env
PREFIX_IN_DUMP="$($CAT "$DUMP" 2>/dev/null | grep -m1 -oE 'CREATE TABLE `?[A-Za-z0-9_]+_options`?' | sed -E 's/CREATE TABLE `?([A-Za-z0-9_]+_)options`?/\1/' || true)"
if [[ -n "$PREFIX_IN_DUMP" && "$PREFIX_IN_DUMP" != "${DB_TABLE_PREFIX:-wp_}" ]]; then
  echo "!! Dump uses table prefix '${PREFIX_IN_DUMP}' but .env has DB_TABLE_PREFIX='${DB_TABLE_PREFIX:-wp_}'."
  echo "   Set DB_TABLE_PREFIX=${PREFIX_IN_DUMP} in .env and retry."
  exit 1
fi

if [[ "$RESUME_INDEXES" -eq 1 ]]; then
  echo "==> Resume: keep existing tables in '${DB_NAME}', apply indexes from ${DUMP}"
  [[ "$deferred_indexes" -eq 1 ]] || { echo "dump has no deferred phpMyAdmin index section"; exit 1; }
  remap_duplicate_zero_pks
  apply_deferred_indexes
else
  echo "==> This will DROP all tables in database '${DB_NAME}' and import ${DUMP}"
  read -r -p "    Continue? [y/N] " ok; [[ "$ok" == [yY] ]] || exit 1

  echo "==> Dropping existing tables"
  mysql -N -e "SELECT CONCAT('DROP TABLE IF EXISTS \`', table_name, '\`;') FROM information_schema.tables WHERE table_schema='${DB_NAME}'" \
    | mysql "${DB_NAME}"

  echo "==> Importing (this can take a while for large dumps)"
  if [[ "$deferred_indexes" -eq 1 ]]; then
    echo "    phpMyAdmin dump: load rows first, then keys"
    $CAT "$DUMP" | awk '
      /^-- Indexes for dumped tables$/ { p=1 }
      !p
    ' | sanitize_dump | { cat; echo "COMMIT;"; } | mysql_import
    remap_duplicate_zero_pks
    apply_deferred_indexes
  else
    $CAT "$DUMP" | sanitize_dump | mysql_import
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

if [[ -f /usr/lib/systemd/system/php8.3-fpm.service ]]; then
  systemctl start php8.3-fpm 2>/dev/null || true
fi

echo
echo "Done. Verify:  scripts/wp.sh option get siteurl ; scripts/wp.sh post list --post_status=publish --posts_per_page=3"
