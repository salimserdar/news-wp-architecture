#!/usr/bin/env bash
# Nightly backup: database dump (zstd) + uploads sync. Optional offsite copy
# with rclone (set BACKUP_RCLONE_REMOTE in .env, e.g. "r2:news-backups").
#
#   scripts/backup.sh            # run now
# Installed as a cron job by scripts/setup-vps.sh (03:30 daily).
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
set -a; source .env; set +a

KEEP_DAYS="${BACKUP_KEEP_DAYS:-14}"
STAMP="$(date +%F_%H%M)"
DB_OUT="backups/db/${DB_NAME}_${STAMP}.sql.zst"
mkdir -p backups/db backups/uploads

echo "[$(date -Is)] DB dump -> $DB_OUT"
docker compose exec -T mariadb sh -c \
  'exec mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" --single-transaction --quick --routines --triggers --events --default-character-set=utf8mb4 "$MARIADB_DATABASE"' \
  | zstd -T0 -q -o "$DB_OUT"
ls -lh "$DB_OUT"

echo "[$(date -Is)] uploads -> backups/uploads (incremental)"
rsync -a --delete wordpress/wp-content/uploads/ backups/uploads/

echo "[$(date -Is)] pruning DB dumps older than ${KEEP_DAYS} days"
find backups/db -name '*.sql.zst' -mtime +"$KEEP_DAYS" -delete

if [[ -n "${BACKUP_RCLONE_REMOTE:-}" ]] && command -v rclone >/dev/null; then
  echo "[$(date -Is)] offsite -> ${BACKUP_RCLONE_REMOTE}"
  rclone copy backups/db "${BACKUP_RCLONE_REMOTE}/db" --max-age 2d -q
  rclone sync backups/uploads "${BACKUP_RCLONE_REMOTE}/uploads" -q
fi

echo "[$(date -Is)] done"
