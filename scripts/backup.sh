#!/usr/bin/env bash
# Nightly backup: database dump (zstd) plus a same-disk uploads copy.
# The database dump is uploaded to today's locked R2 bucket when the writer
# key is set (scripts/r2-db-backup.sh). That upload cannot delete objects.
#
#   scripts/backup.sh            # run now
# Installed as a cron job by scripts/setup-vps.sh (03:30 daily).
set -euo pipefail
# shellcheck source=lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
cd "${REPO_DIR}"

[[ -n "${DB_NAME:-}" ]] || { echo "missing DB_NAME"; exit 1; }

KEEP_DAYS="${BACKUP_KEEP_DAYS:-14}"
STAMP="$(date +%F_%H%M)"
DB_OUT="backups/db/${DB_NAME}_${STAMP}.sql.zst"
mkdir -p backups/db backups/uploads

echo "[$(date -Is)] DB dump -> $DB_OUT"
mariadb-dump --single-transaction --quick --routines --triggers --events \
  --default-character-set=utf8mb4 "${DB_NAME}" \
  | zstd -T0 -q -o "$DB_OUT"
ls -lh "$DB_OUT"

echo "[$(date -Is)] uploads -> backups/uploads (incremental)"
if [[ -d "${WP_ROOT}/wp-content/uploads" ]]; then
  rsync -a --delete "${WP_ROOT}/wp-content/uploads/" backups/uploads/
else
  echo "  (no ${WP_ROOT}/wp-content/uploads yet)"
fi

if [[ -n "${R2_DB_ACCESS_KEY_ID:-}" && -n "${R2_DB_SECRET_ACCESS_KEY:-}" ]]; then
  echo "[$(date -Is)] offsite db -> r2:${R2_DB_BUCKET:-news-db}/$(date -u +%Y%m%d)/"
  bash scripts/r2-db-backup.sh upload "$DB_OUT"
else
  echo "[$(date -Is)] R2 DB backup skipped (set R2_DB_ACCESS_KEY_ID and R2_DB_SECRET_ACCESS_KEY)"
fi

echo "[$(date -Is)] pruning local DB dumps older than ${KEEP_DAYS} days"
find backups/db -name '*.sql.zst' -mtime +"$KEEP_DAYS" -delete
find backups/db -name '*.sql.zst.sha256' -mtime +"$KEEP_DAYS" -delete

echo "[$(date -Is)] done"
