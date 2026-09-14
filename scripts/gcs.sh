#!/usr/bin/env bash
# Upload / download between this host and a Cloud Storage bucket.
# Uses the Compute Engine service account (Application Default Credentials).
# No JSON keys. Set GCS_BUCKET in .env (name only, or gs://name).
#
# Primary:  gcloud storage
# Optional: rclone (env_auth) and gcsfuse (mount as a folder)
#
#   scripts/gcs.sh check | smoke | ls [PREFIX]
#   scripts/gcs.sh upload LOCAL_PATH [REMOTE_PATH]
#   scripts/gcs.sh download REMOTE_PATH [LOCAL_PATH]
#   scripts/gcs.sh backup | restore-db FILE | restore-uploads | pull
#   scripts/gcs.sh install | rclone-config
#   scripts/gcs.sh rclone-upload LOCAL_PATH [REMOTE_PATH]
#   scripts/gcs.sh rclone-download REMOTE_PATH [LOCAL_PATH]
#   scripts/gcs.sh mount | unmount | fstab
#   scripts/gcs.sh grant-vm VM_NAME ZONE
#   scripts/gcs.sh grant-sa [SA_EMAIL]     # bucket IAM only, no VM needed
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
[[ -f .env ]] && { set -a; source .env; set +a; }

RCLONE_REMOTE_NAME="${GCS_RCLONE_REMOTE:-gcs}"
GCS_MOUNT="${GCS_MOUNT:-/mnt/gcs}"

need_gcloud() {
  command -v gcloud >/dev/null || {
    echo "gcloud not found. On a GCE VM use a GCP image, or install google-cloud-cli." >&2
    exit 1
  }
}

need_rclone() {
  command -v rclone >/dev/null || {
    echo "rclone not found. Run:  scripts/gcs.sh install" >&2
    exit 1
  }
}

need_gcsfuse() {
  command -v gcsfuse >/dev/null || {
    echo "gcsfuse not found. Run:  scripts/gcs.sh install" >&2
    exit 1
  }
}

bucket_name() {
  local b="${GCS_BUCKET:-tr724-backup}"
  b="${b#gs://}"
  b="${b%/}"
  printf '%s' "$b"
}

uri() { printf 'gs://%s' "$(bucket_name)"; }

need_bucket() {
  # Must run in the main shell. exit inside $(uri) only kills the subshell.
  bucket_name >/dev/null
}

remote_uri() {
  local p="${1:-}"
  p="${p#/}"
  if [[ "$p" == gs://* ]]; then
    printf '%s' "$p"
  elif [[ -z "$p" ]]; then
    uri
  else
    printf '%s/%s' "$(uri)" "$p"
  fi
}

sa_email() {
  curl -sf -H "Metadata-Flavor: Google" \
    http://metadata.google.internal/computeMetadata/v1/instance/service-accounts/default/email \
    2>/dev/null || echo "(not a GCE VM — gcloud auth application-default login, or attach a service account)"
}

gcloud_cp() {
  local src=$1 dest=$2
  if [[ -d "$src" ]]; then
    gcloud storage cp -r "$src" "$dest"
  else
    gcloud storage cp "$src" "$dest"
  fi
}

rclone_dest() {
  printf '%s:%s' "$RCLONE_REMOTE_NAME" "$(bucket_name)"
}

rclone_path() {
  local p="${1:-}"
  p="${p#/}"
  p="${p#gs://}"
  if [[ -n "$p" && "$p" == "$(bucket_name)"/* ]]; then
    p="${p#$(bucket_name)/}"
  fi
  if [[ -z "$p" ]]; then
    rclone_dest
  else
    printf '%s/%s' "$(rclone_dest)" "$p"
  fi
}

cmd="${1:-}"
shift || true

case "$cmd" in
  check)
    need_gcloud
    need_bucket
    echo "service account: $(sa_email)"
    echo "bucket:          $(uri)"
    echo "rclone remote:   ${RCLONE_REMOTE_NAME}:"
    echo "gcsfuse mount:   ${GCS_MOUNT}"
    gcloud storage ls "$(uri)" >/dev/null
    echo "ok — list succeeded."
    echo "next:  scripts/gcs.sh smoke"
    echo
    echo "If list failed with 403, from an admin account (laptop, not the VM):"
    echo "  scripts/gcs.sh grant-vm VM_NAME ZONE"
    ;;
  ls)
    need_gcloud
    need_bucket
    gcloud storage ls "$(remote_uri "${1:-}")"
    ;;
  upload)
    need_gcloud
    need_bucket
    [[ $# -ge 1 ]] || { echo "usage: $0 upload LOCAL_PATH [REMOTE_PATH]" >&2; exit 1; }
    src=$1
    [[ -e "$src" ]] || { echo "not found: $src" >&2; exit 1; }
    dest=$(remote_uri "${2:-$(basename "$src")}")
    echo "upload $src -> $dest"
    gcloud_cp "$src" "$dest"
    ;;
  download)
    need_gcloud
    need_bucket
    [[ $# -ge 1 ]] || { echo "usage: $0 download REMOTE_PATH [LOCAL_PATH]" >&2; exit 1; }
    src=$(remote_uri "$1")
    dest=${2:-.}
    if [[ "$dest" == */ || -d "$dest" ]]; then
      mkdir -p "$dest"
    else
      mkdir -p "$(dirname "$dest")"
    fi
    echo "download $src -> $dest"
    gcloud storage cp -r "$src" "$dest"
    ;;
  smoke)
    need_gcloud
    need_bucket
    id="$(date +%s).$$"
    remote="_news-wp-gcs-smoke/${id}.txt"
    local_up=$(mktemp)
    local_down=$(mktemp)
    cleanup() {
      rm -f "$local_up" "$local_down"
      gcloud storage rm "$(uri)/${remote}" --quiet >/dev/null 2>&1 || true
    }
    trap cleanup EXIT
    printf 'news-wp-gcs-smoke %s\n' "$id" >"$local_up"
    echo "upload  $(uri)/${remote}"
    gcloud storage cp "$local_up" "$(uri)/${remote}"
    echo "download $(uri)/${remote}"
    gcloud storage cp "$(uri)/${remote}" "$local_down"
    cmp -s "$local_up" "$local_down" || { echo "FAIL: downloaded bytes differ" >&2; exit 1; }
    echo "ok — gcloud storage upload + download matched"
    ;;
  backup)
    need_gcloud
    need_bucket
    mkdir -p backups/db backups/uploads
    if compgen -G 'backups/db/*.sql.zst' >/dev/null; then
      echo "upload db dumps -> $(uri)/db/"
      gcloud storage cp backups/db/*.sql.zst "$(uri)/db/"
    else
      echo "no backups/db/*.sql.zst to upload"
    fi
    if [[ -d backups/uploads ]] && [[ -n "$(ls -A backups/uploads 2>/dev/null || true)" ]]; then
      echo "sync uploads -> $(uri)/uploads/"
      gcloud storage rsync backups/uploads "$(uri)/uploads" \
        --recursive --delete-unmatched-destination-objects
    else
      echo "no backups/uploads to sync"
    fi
    ;;
  restore-db)
    need_gcloud
    need_bucket
    [[ $# -ge 1 ]] || { echo "usage: $0 restore-db FILE.sql.zst" >&2; exit 1; }
    file=$(basename "$1")
    mkdir -p backups/db
    echo "download $(uri)/db/$file -> backups/db/"
    gcloud storage cp "$(uri)/db/$file" "backups/db/$file"
    echo "restore with:"
    echo "  zstd -dc backups/db/$file | mysql \"${DB_NAME:-wordpress}\""
    ;;
  restore-uploads)
    need_gcloud
    need_bucket
    mkdir -p backups/uploads
    echo "sync $(uri)/uploads -> backups/uploads/"
    gcloud storage rsync "$(uri)/uploads" backups/uploads --recursive
    echo "copy into WordPress with:"
    echo "  rsync -a backups/uploads/ ${WP_ROOT:-/var/www/html}/wp-content/uploads/"
    echo "  chown -R www-data:www-data ${WP_ROOT:-/var/www/html}/wp-content/uploads"
    ;;
  pull)
    exec bash scripts/pull-gcs-backup.sh "$@"
    ;;
  install)
    [[ $EUID -eq 0 ]] || { echo "run as root:  sudo bash scripts/gcs.sh install" >&2; exit 1; }
    [[ "$(uname -s)" == Linux ]] || { echo "install is for Ubuntu/Debian VMs" >&2; exit 1; }
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -y
    apt-get install -y curl gnupg lsb-release unzip fuse3 || apt-get install -y curl gnupg lsb-release unzip fuse

    if ! command -v rclone >/dev/null || ! rclone help flags 2>/dev/null | grep -q -- '--gcs-env-auth'; then
      echo "==> rclone (official installer, needs env_auth for GCE)"
      curl -fsSL https://rclone.org/install.sh | bash
    fi

    if ! command -v gcsfuse >/dev/null; then
      echo "==> gcsfuse"
      GCSFUSE_REPO="gcsfuse-$(lsb_release -c -s)"
      echo "deb [signed-by=/usr/share/keyrings/cloud.google.asc] https://packages.cloud.google.com/apt ${GCSFUSE_REPO} main" \
        >/etc/apt/sources.list.d/gcsfuse.list
      curl -fsSL https://packages.cloud.google.com/apt/doc/apt-key.gpg \
        >/usr/share/keyrings/cloud.google.asc
      apt-get update -y
      apt-get install -y gcsfuse
    fi

    if [[ -f /etc/fuse.conf ]] && grep -q '^#user_allow_other' /etc/fuse.conf; then
      sed -i 's/^#user_allow_other/user_allow_other/' /etc/fuse.conf
    elif [[ -f /etc/fuse.conf ]] && ! grep -q '^user_allow_other' /etc/fuse.conf; then
      echo user_allow_other >>/etc/fuse.conf
    fi

    command -v rclone >/dev/null && rclone version | head -1
    command -v gcsfuse >/dev/null && gcsfuse --version
    echo "ok — rclone + gcsfuse installed. Next:  scripts/gcs.sh rclone-config"
    ;;
  rclone-config)
    need_rclone
    rclone help flags 2>/dev/null | grep -q -- '--gcs-env-auth' || {
      echo "this rclone is too old for env_auth (GCE, no JSON key). Run:  sudo bash scripts/gcs.sh install" >&2
      exit 1
    }
    if rclone listremotes | grep -qx "${RCLONE_REMOTE_NAME}:"; then
      rclone config update "$RCLONE_REMOTE_NAME" env_auth true --non-interactive
    else
      rclone config create "$RCLONE_REMOTE_NAME" "google cloud storage" env_auth true --non-interactive
    fi
    echo "rclone remote ${RCLONE_REMOTE_NAME}: uses VM/ADC credentials (no JSON key)."
    echo "optional .env:  BACKUP_RCLONE_REMOTE=${RCLONE_REMOTE_NAME}:$(bucket_name 2>/dev/null || echo YOUR_BUCKET)"
    echo "do not set both GCS_BUCKET and BACKUP_RCLONE_REMOTE to the same bucket (double upload)."
    ;;
  rclone-upload)
    need_rclone
    need_bucket
    [[ $# -ge 1 ]] || { echo "usage: $0 rclone-upload LOCAL_PATH [REMOTE_PATH]" >&2; exit 1; }
    src=$1
    [[ -e "$src" ]] || { echo "not found: $src" >&2; exit 1; }
    dest=$(rclone_path "${2:-$(basename "$src")}")
    echo "rclone upload $src -> $dest"
    if [[ -d "$src" ]]; then
      rclone copy "$src" "$dest"
    else
      rclone copyto "$src" "$dest"
    fi
    ;;
  rclone-download)
    need_rclone
    need_bucket
    [[ $# -ge 1 ]] || { echo "usage: $0 rclone-download REMOTE_PATH [LOCAL_PATH]" >&2; exit 1; }
    src=$(rclone_path "$1")
    dest=${2:-.}
    echo "rclone download $src -> $dest"
    if [[ -d "$dest" || "$dest" == */ || "$dest" == . ]]; then
      mkdir -p "${dest%/}"
      rclone copy "$src" "${dest%/}"
    else
      mkdir -p "$(dirname "$dest")"
      rclone copyto "$src" "$dest"
    fi
    ;;
  mount)
    need_gcsfuse
    need_bucket
    mkdir -p "$GCS_MOUNT"
    if command -v mountpoint >/dev/null && mountpoint -q "$GCS_MOUNT"; then
      echo "already mounted: $GCS_MOUNT"
      exit 0
    fi
    echo "mount $(bucket_name) -> $GCS_MOUNT"
    gcsfuse --implicit-dirs "$(bucket_name)" "$GCS_MOUNT"
    echo "ok.  cp ./file.txt ${GCS_MOUNT}/file.txt   # upload"
    echo "     cp ${GCS_MOUNT}/file.txt ./file.txt   # download"
    echo "Do not put MariaDB or live WordPress uploads on this mount."
    ;;
  unmount)
    if command -v fusermount3 >/dev/null; then
      fusermount3 -u "$GCS_MOUNT"
    elif command -v fusermount >/dev/null; then
      fusermount -u "$GCS_MOUNT"
    else
      umount "$GCS_MOUNT"
    fi
    echo "unmounted $GCS_MOUNT"
    ;;
  fstab)
    [[ $EUID -eq 0 ]] || { echo "run as root:  sudo bash scripts/gcs.sh fstab" >&2; exit 1; }
    need_bucket
    mkdir -p "$GCS_MOUNT"
    line="$(bucket_name) ${GCS_MOUNT} gcsfuse rw,allow_other,implicit_dirs,_netdev,x-systemd.requires=network-online.target 0 0"
    if grep -qE "^[^#]*[[:space:]]${GCS_MOUNT}[[:space:]]+gcsfuse" /etc/fstab; then
      echo "fstab already has ${GCS_MOUNT}"
    else
      echo "$line" >>/etc/fstab
      echo "appended to /etc/fstab"
    fi
    mount "$GCS_MOUNT" || true
    echo "ok — ${GCS_MOUNT} mounts on boot"
    ;;
  grant-vm)
    need_gcloud
    [[ $# -ge 2 ]] || { echo "usage: $0 grant-vm VM_NAME ZONE" >&2; exit 1; }
    need_bucket
    vm=$1 zone=$2
    sa=$(gcloud compute instances describe "$vm" --zone="$zone" \
      --format='value(serviceAccounts.email)')
    [[ -n "$sa" ]] || { echo "no service account on $vm" >&2; exit 1; }
    echo "granting roles/storage.objectAdmin on $(uri) to $sa"
    gcloud storage buckets add-iam-policy-binding "$(uri)" \
      --member="serviceAccount:${sa}" \
      --role='roles/storage.objectAdmin'
    if gcloud compute instances set-service-account "$vm" --zone="$zone" \
         --service-account="$sa" --scopes=cloud-platform; then
      echo "ok — SSH to the VM and run:  scripts/gcs.sh check && scripts/pull-gcs-backup.sh"
    else
      echo "IAM is set. Access scopes can only change while the VM is stopped:"
      echo "  gcloud compute instances stop $vm --zone=$zone"
      echo "  gcloud compute instances set-service-account $vm --zone=$zone --service-account=$sa --scopes=cloud-platform"
      echo "  gcloud compute instances start $vm --zone=$zone"
      exit 1
    fi
    ;;
  grant-sa)
    need_gcloud
    need_bucket
    sa="${1:-}"
    if [[ -z "$sa" ]]; then
      project="$(gcloud config get-value project 2>/dev/null || true)"
      [[ -n "$project" && "$project" != "(unset)" ]] || {
        echo "usage: $0 grant-sa SA_EMAIL   (or set gcloud project for the default Compute SA)" >&2
        exit 1
      }
      number="$(gcloud projects describe "$project" --format='value(projectNumber)')"
      sa="${number}-compute@developer.gserviceaccount.com"
    fi
    echo "granting roles/storage.objectAdmin on $(uri) to $sa"
    gcloud storage buckets add-iam-policy-binding "$(uri)" \
      --member="serviceAccount:${sa}" \
      --role="roles/storage.objectAdmin"
    echo "ok — any new VM using this SA with --scopes=cloud-platform can read the bucket immediately."
    echo "create the VM with:  scripts/create-gce-vm.sh"
    ;;
  *)
    cat >&2 <<EOF
usage: $0 COMMAND

  gcloud storage (primary, GCE service account):
    check | smoke | ls [PREFIX]
    upload LOCAL_PATH [REMOTE_PATH]
    download REMOTE_PATH [LOCAL_PATH]
    backup | restore-db FILE.sql.zst | restore-uploads
    pull [--check|--db-only|--content-only]

  admin (from a machine that can change IAM):
    grant-vm VM_NAME ZONE
    grant-sa [SA_EMAIL]

  optional rclone (no JSON key, env_auth):
    install | rclone-config
    rclone-upload LOCAL_PATH [REMOTE_PATH]
    rclone-download REMOTE_PATH [LOCAL_PATH]

  optional gcsfuse (folder mount; not for DB or live uploads):
    mount | unmount | fstab
EOF
    exit 1
    ;;
esac
