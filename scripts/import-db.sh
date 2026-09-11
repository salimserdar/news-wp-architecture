#!/usr/bin/env bash
# Import an existing WordPress database dump into the MariaDB container and
# rewrite the old domain to SITE_DOMAIN.
#
#   scripts/import-db.sh import/site.sql.gz [old-domain.com]
#
# - Accepts .sql, .sql.gz, .sql.zst, .sql.bz2 — put the file in ./import/
# - Fixes MySQL 8 -> MariaDB incompatibilities (utf8mb4_0900_* collations, GTID lines)
# - Replaces ALL existing tables in DB_NAME (asks for confirmation)
# - Runs wp search-replace old-domain -> SITE_DOMAIN (http and https), flushes caches
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

[[ -f .env ]] || { echo "missing .env (cp .env.example .env)"; exit 1; }
set -a; source .env; set +a

DUMP="${1:-}"; OLD_DOMAIN="${2:-}"
[[ -n "$DUMP" && -f "$DUMP" ]] || { echo "usage: $0 import/<dump.sql[.gz|.zst|.bz2]> [old-domain.com]"; exit 1; }

case "$DUMP" in
  *.gz)  CAT="gzip -dc" ;;
  *.zst) CAT="zstd -dc" ;;
  *.bz2) CAT="bzip2 -dc" ;;
  *)     CAT="cat" ;;
esac

echo "==> Waiting for MariaDB to be healthy"
until [[ "$(docker compose ps --format json mariadb | jq -r '.Health // .State' 2>/dev/null)" == "healthy" ]]; do
  sleep 2; printf '.'
done; echo

# Sanity check: table prefix in the dump vs .env
PREFIX_IN_DUMP="$($CAT "$DUMP" 2>/dev/null | grep -m1 -oE 'CREATE TABLE `?[A-Za-z0-9_]+_options`?' | sed -E 's/CREATE TABLE `?([A-Za-z0-9_]+_)options`?/\1/' || true)"
if [[ -n "$PREFIX_IN_DUMP" && "$PREFIX_IN_DUMP" != "${DB_TABLE_PREFIX:-wp_}" ]]; then
  echo "!! Dump uses table prefix '${PREFIX_IN_DUMP}' but .env has DB_TABLE_PREFIX='${DB_TABLE_PREFIX:-wp_}'."
  echo "   Set DB_TABLE_PREFIX=${PREFIX_IN_DUMP} in .env, run 'docker compose up -d' and retry."
  exit 1
fi

echo "==> This will DROP all tables in database '${DB_NAME}' and import ${DUMP}"
read -r -p "    Continue? [y/N] " ok; [[ "$ok" == [yY] ]] || exit 1

echo "==> Dropping existing tables"
docker compose exec -T mariadb sh -c '
  mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" -N -e "SELECT CONCAT(\"DROP TABLE IF EXISTS \`\", table_name, \"\`;\") FROM information_schema.tables WHERE table_schema=\"$MARIADB_DATABASE\"" \
  | mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"'

echo "==> Importing (this can take a while for large dumps)"
$CAT "$DUMP" \
  | sed -e 's/utf8mb4_0900_ai_ci/utf8mb4_unicode_ci/g' \
        -e 's/utf8mb4_0900_as_cs/utf8mb4_bin/g' \
        -e 's/utf8mb4_0900_bin/utf8mb4_bin/g' \
        -e '/^SET @@GLOBAL.GTID_PURGED/d' \
        -e '/^SET @@SESSION.SQL_LOG_BIN/d' \
  | docker compose exec -T mariadb sh -c 'exec mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" --max_allowed_packet=256M "$MARIADB_DATABASE"'

echo "==> Tables imported:"
docker compose exec -T mariadb sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=\"$MARIADB_DATABASE\""'

WP="docker compose run --rm -T wpcli wp"
if [[ -n "$OLD_DOMAIN" ]]; then
  echo "==> Rewriting ${OLD_DOMAIN} -> ${SITE_DOMAIN}"
  for scheme in https http; do
    $WP search-replace "${scheme}://${OLD_DOMAIN}" "https://${SITE_DOMAIN}" --all-tables --precise --skip-columns=guid --report-changed-only
  done
  # Plain host occurrences (serialized data, JSON) without scheme
  $WP search-replace "//${OLD_DOMAIN}" "//${SITE_DOMAIN}" --all-tables --precise --skip-columns=guid --report-changed-only
fi

echo "==> Post-import housekeeping"
$WP option update home    "https://${SITE_DOMAIN}" >/dev/null || true
$WP option update siteurl "https://${SITE_DOMAIN}" >/dev/null || true
$WP cache flush >/dev/null 2>&1 || true
$WP rewrite flush         >/dev/null 2>&1 || true
$WP core update-db        2>/dev/null || true
$WP news-cache purge-all  2>/dev/null || true

echo
echo "Done. Verify:  scripts/wp.sh option get siteurl ; scripts/wp.sh post list --post_status=publish --posts_per_page=3"
