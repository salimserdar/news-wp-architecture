#!/usr/bin/env bash
# Nightly backup: database dump (zstd) + uploads sync. Optional offsite copy
# with rclone (BACKUP_RCLONE_REMOTE) and/or gcloud storage (GCS_BUCKET).
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

echo "[$(date -Is)] pruning DB dumps older than ${KEEP_DAYS} days"
find backups/db -name '*.sql.zst' -mtime +"$KEEP_DAYS" -delete

if [[ -n "${BACKUP_RCLONE_REMOTE:-}" ]] && command -v rclone >/dev/null; then
  echo "[$(date -Is)] offsite rclone -> ${BACKUP_RCLONE_REMOTE}"
  rclone copy backups/db "${BACKUP_RCLONE_REMOTE}/db" --max-age 2d -q
  rclone sync backups/uploads "${BACKUP_RCLONE_REMOTE}/uploads" -q
fi

if [[ -n "${GCS_BUCKET:-}" ]] && command -v gcloud >/dev/null; then
  echo "[$(date -Is)] offsite gcs -> gs://${GCS_BUCKET#gs://}"
  bash scripts/gcs.sh backup
fi

echo "[$(date -Is)] done"
