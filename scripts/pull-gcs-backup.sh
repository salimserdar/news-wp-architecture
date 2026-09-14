#!/usr/bin/env bash
# Pull the site backup from Google Cloud Storage onto this VM.
# Does not import — run import-db.sh / import-wp-content.sh after.
#
# Default layout (override in .env):
#   GCS_BUCKET=tr724-backup
#   GCS_DB_OBJECT=db/wp_tr724.sql       # gs://tr724-backup/db/wp_tr724.sql
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
GCS_DB_OBJECT="${GCS_DB_OBJECT:-db/wp_tr724.sql}"
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

command -v gcloud >/dev/null || {
  echo "gcloud not found. On a GCE Ubuntu image it is usually preinstalled." >&2
  echo "Otherwise:  sudo snap install google-cloud-cli --classic" >&2
  exit 1
}

bucket="${GCS_BUCKET#gs://}"
bucket="${bucket%/}"
uri="gs://${bucket}"
content_src="${uri}/${GCS_WP_CONTENT#/}"
content_dest="${IMPORT_DIR}/wp-content"

object_exists() {
  gcloud storage ls "$1" >/dev/null 2>&1
}

resolve_db_src() {
  local want="${uri}/${GCS_DB_OBJECT#/}"
  if object_exists "$want"; then
    printf '%s' "$want"
    return
  fi
  local try
  for try in \
    "${uri}/db/wp_tr724.sql" \
    "${uri}/wp_tr724.sql" \
    "${uri}/db/wp_tr724.sql.gz" \
    "${uri}/db/wp_tr724.sql.zst"
  do
    if object_exists "$try"; then
      echo "    note: ${want} not found; using ${try}" >&2
      printf '%s' "$try"
      return
    fi
  done
  echo "ERROR: dump not at ${want}" >&2
  echo "Objects under ${uri}/ :" >&2
  gcloud storage ls "${uri}/" >&2 || true
  echo "SQL-looking objects:" >&2
  gcloud storage ls "${uri}/db/" >&2 || true
  echo "Set GCS_DB_OBJECT in .env to the path after gs://${bucket}/  (example: db/wp_tr724.sql)" >&2
  exit 1
}

db_src="$(resolve_db_src)"
db_dest="${IMPORT_DIR}/$(basename "${db_src}")"

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
  gcloud storage ls "${content_src}" | head -20
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
