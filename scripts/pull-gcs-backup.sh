#!/usr/bin/env bash
# Pull the site backup from Google Cloud Storage onto this VM.
# Does not import — run import-db.sh / import-wp-content.sh after.
#
# Default layout (override in .env):
#   GCS_BUCKET=tr724-backup
#   GCS_DB_OBJECT=wp_tr724.sql          # gs://tr724-backup/wp_tr724.sql
#   GCS_WP_CONTENT=wp-content           # gs://tr724-backup/wp-content/
#
#   scripts/pull-gcs-backup.sh
#   scripts/pull-gcs-backup.sh --db-only
#   scripts/pull-gcs-backup.sh --content-only
#   scripts/pull-gcs-backup.sh --check
set -euo pipefail
# shellcheck source=lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
cd "${REPO_DIR}"

GCS_BUCKET="${GCS_BUCKET:-tr724-backup}"
GCS_DB_OBJECT="${GCS_DB_OBJECT:-wp_tr724.sql}"
GCS_WP_CONTENT="${GCS_WP_CONTENT:-wp-content}"
IMPORT_DIR="${REPO_DIR}/import"

DB_ONLY=0
CONTENT_ONLY=0
CHECK_ONLY=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --db-only) DB_ONLY=1; shift ;;
    --content-only) CONTENT_ONLY=1; shift ;;
    --check) CHECK_ONLY=1; shift ;;
    -h|--help)
      echo "Usage: $0 [--check] [--db-only] [--content-only]"
      exit 0
      ;;
    *) echo "unknown arg: $1" >&2; exit 1 ;;
  esac
done

bucket="${GCS_BUCKET#gs://}"
bucket="${bucket%/}"
uri="gs://${bucket}"
db_src="${uri}/${GCS_DB_OBJECT#/}"
content_src="${uri}/${GCS_WP_CONTENT#/}"
db_dest="${IMPORT_DIR}/$(basename "${GCS_DB_OBJECT}")"
content_dest="${IMPORT_DIR}/wp-content"

need_gcloud() {
  command -v gcloud >/dev/null || {
    echo "gcloud not found. On a GCE Ubuntu image it is usually preinstalled." >&2
    echo "Otherwise:  sudo snap install google-cloud-cli --classic" >&2
    exit 1
  }
}

need_gcloud

if [[ "$CHECK_ONLY" -eq 1 ]]; then
  echo "service account: $(curl -sf -H 'Metadata-Flavor: Google' \
    http://metadata.google.internal/computeMetadata/v1/instance/service-accounts/default/email \
    2>/dev/null || echo '(not a GCE VM)')"
  echo "bucket:          ${uri}"
  echo
  echo "==> ${db_src}"
  gcloud storage ls -l "${db_src}"
  echo
  echo "==> ${content_src}/  (first 20 objects)"
  gcloud storage ls "${content_src}/**" 2>/dev/null | head -20 \
    || gcloud storage ls "${content_src}" | head -20
  echo
  echo "ok — VM can read the backup. Pull with:  $0"
  exit 0
fi

if [[ "$DB_ONLY" -eq 1 && "$CONTENT_ONLY" -eq 1 ]]; then
  echo "pick one of --db-only or --content-only" >&2
  exit 1
fi

mkdir -p "${IMPORT_DIR}"

if [[ "$CONTENT_ONLY" -eq 0 ]]; then
  echo "==> DB  ${db_src} -> ${db_dest}"
  gcloud storage cp "${db_src}" "${db_dest}"
  ls -lh "${db_dest}"
fi

if [[ "$DB_ONLY" -eq 0 ]]; then
  echo "==> wp-content  ${content_src} -> ${content_dest}"
  mkdir -p "${content_dest}"
  gcloud storage rsync "${content_src}" "${content_dest}" --recursive
  du -sh "${content_dest}"
fi

echo
echo "Done. Files are in ${IMPORT_DIR}/ (not imported yet)."
echo "Next:"
if [[ "$CONTENT_ONLY" -eq 0 ]]; then
  echo "  scripts/import-db.sh ${db_dest} old-domain.com"
fi
if [[ "$DB_ONLY" -eq 0 ]]; then
  echo "  scripts/import-wp-content.sh ${content_dest}"
fi
echo "  scripts/post-import.sh"
