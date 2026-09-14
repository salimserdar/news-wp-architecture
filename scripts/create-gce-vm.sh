#!/usr/bin/env bash
# Create a GCE Ubuntu 24.04 VM that can already read gs://tr724-backup.
# Run from a laptop (or any machine) logged into gcloud as a project owner.
#
#   scripts/create-gce-vm.sh                    # creates GCE_NAME (default news-wp-2)
#   scripts/create-gce-vm.sh --name news-wp-2
#   scripts/create-gce-vm.sh --reuse            # keep existing NAME
#   scripts/create-gce-vm.sh --recreate         # delete NAME, then create
#   scripts/create-gce-vm.sh --grant-only       # IAM only, no instance
#
# Bucket access is granted *before* the VM exists, on the service account the
# VM will use (default Compute Engine SA unless GCE_SA is set).
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
[[ -f .env ]] && { set -a; source .env; set +a; }

GCS_BUCKET="${GCS_BUCKET:-tr724-backup}"
GCE_NAME="${GCE_NAME:-news-wp-2}"
GCE_ZONE="${GCE_ZONE:-}"
GCE_MACHINE="${GCE_MACHINE:-e2-standard-2}"   # 2 vCPU / 8 GB; e2-highmem-2 = 2 / 16 GB
GCE_DISK_GB="${GCE_DISK_GB:-100}"
GCE_DISK_TYPE="${GCE_DISK_TYPE:-pd-standard}"  # pd-balanced counts against SSD quota
GCE_SA="${GCE_SA:-}"                         # empty = project default Compute Engine SA
GRANT_ONLY=0
REUSE=0
RECREATE=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --grant-only) GRANT_ONLY=1; shift ;;
    --reuse) REUSE=1; shift ;;
    --recreate) RECREATE=1; shift ;;
    --name) GCE_NAME="$2"; shift 2 ;;
    --zone) GCE_ZONE="$2"; shift 2 ;;
    --machine) GCE_MACHINE="$2"; shift 2 ;;
    --disk) GCE_DISK_GB="$2"; shift 2 ;;
    --disk-type) GCE_DISK_TYPE="$2"; shift 2 ;;
    --sa) GCE_SA="$2"; shift 2 ;;
    -h|--help)
      cat <<EOF
Usage: $0 [--grant-only] [--reuse|--recreate] [--name NAME] [--zone ZONE] [--machine TYPE] [--disk GB] [--sa EMAIL]

Env (or .env):  GCS_BUCKET GCE_NAME GCE_ZONE GCE_MACHINE GCE_DISK_GB GCE_SA
Defaults:       tr724-backup news-wp-2 (gcloud default zone) e2-standard-2 200 default-compute-SA

  --reuse      if NAME already exists, keep it (do not create)
  --recreate   delete NAME then create it again (destroys the old disk)
EOF
      exit 0
      ;;
    *) echo "unknown arg: $1" >&2; exit 1 ;;
  esac
done

command -v gcloud >/dev/null || { echo "install gcloud (Google Cloud SDK) on this machine" >&2; exit 1; }

PROJECT="$(gcloud config get-value project 2>/dev/null || true)"
[[ -n "$PROJECT" && "$PROJECT" != "(unset)" ]] || {
  echo "set a project:  gcloud config set project PROJECT_ID" >&2
  exit 1
}

if [[ -z "$GCE_ZONE" ]]; then
  GCE_ZONE="$(gcloud config get-value compute/zone 2>/dev/null || true)"
fi
if [[ -z "$GCE_ZONE" || "$GCE_ZONE" == "(unset)" ]]; then
  echo "set a zone:  gcloud config set compute/zone us-central1-a" >&2
  echo "  or:  GCE_ZONE=us-central1-a $0" >&2
  exit 1
fi

if [[ -z "$GCE_SA" ]]; then
  NUMBER="$(gcloud projects describe "$PROJECT" --format='value(projectNumber)')"
  GCE_SA="${NUMBER}-compute@developer.gserviceaccount.com"
fi

# Strip a leading dash from a .env typo like GCE_MACHINE=-e2-standard-2
GCE_MACHINE="${GCE_MACHINE#-}"

bucket="${GCS_BUCKET#gs://}"
bucket="${bucket%/}"
uri="gs://${bucket}"
CONSOLE="https://console.cloud.google.com/compute/instancesDetail/zones/${GCE_ZONE}/instances/${GCE_NAME}?project=${PROJECT}"
LIST="https://console.cloud.google.com/compute/instances?project=${PROJECT}"

echo "project:  $PROJECT"
echo "zone:     $GCE_ZONE"
echo "sa:       $GCE_SA"
echo "bucket:   $uri"
echo "vm:       $GCE_NAME ($GCE_MACHINE, ${GCE_DISK_GB} GB ${GCE_DISK_TYPE})"
echo "console:  $LIST"
echo

echo "==> Enable APIs"
gcloud services enable compute.googleapis.com storage.googleapis.com --project="$PROJECT"

echo "==> Grant $GCE_SA objectAdmin on $uri (before the VM exists)"
gcloud storage buckets add-iam-policy-binding "$uri" \
  --member="serviceAccount:${GCE_SA}" \
  --role="roles/storage.objectAdmin" \
  --project="$PROJECT"

if [[ "$GRANT_ONLY" -eq 1 ]]; then
  echo "ok — bucket access is in place. Create the VM later with $0 (omit --grant-only)."
  exit 0
fi

echo "==> Firewall tcp:80,443 tagged news-wp (skip if it already exists)"
if gcloud compute firewall-rules describe news-wp-http-https --project="$PROJECT" >/dev/null 2>&1; then
  echo "    news-wp-http-https already exists"
else
  gcloud compute firewall-rules create news-wp-http-https \
    --project="$PROJECT" \
    --allow=tcp:80,tcp:443 \
    --target-tags=news-wp \
    --description="WordPress origin HTTP/S (lock to Cloudflare with ufw on the VM later)"
fi

if gcloud compute instances describe "$GCE_NAME" --zone="$GCE_ZONE" --project="$PROJECT" >/dev/null 2>&1; then
  echo "==> Instance $GCE_NAME already exists in ${GCE_ZONE}"
  gcloud compute instances describe "$GCE_NAME" --zone="$GCE_ZONE" --project="$PROJECT" \
    --format='table(name,status,machineType.basename(),creationTimestamp,networkInterfaces[0].accessConfigs[0].natIP)'
  echo "    Console: $CONSOLE"
  if [[ "$RECREATE" -eq 1 ]]; then
    echo "==> --recreate: deleting $GCE_NAME (disk included)"
    gcloud compute instances delete "$GCE_NAME" --zone="$GCE_ZONE" --project="$PROJECT" --quiet
  elif [[ "$REUSE" -eq 1 ]]; then
    echo "==> --reuse: keeping the existing VM"
  else
    echo "Refusing to create: that name is taken. Pick one:" >&2
    echo "  1. New VM:     $0 --name news-wp-2" >&2
    echo "  2. Keep this:  $0 --reuse" >&2
    echo "  3. Replace:    $0 --recreate     # deletes the existing disk" >&2
    echo "Also set GCE_NAME in .env so the next run does not keep targeting $GCE_NAME." >&2
    exit 1
  fi
fi

if ! gcloud compute instances describe "$GCE_NAME" --zone="$GCE_ZONE" --project="$PROJECT" >/dev/null 2>&1; then
  echo "==> Create $GCE_NAME"
  gcloud compute instances create "$GCE_NAME" \
    --project="$PROJECT" \
    --zone="$GCE_ZONE" \
    --machine-type="$GCE_MACHINE" \
    --image-family=ubuntu-2404-lts-amd64 \
    --image-project=ubuntu-os-cloud \
    --boot-disk-size="${GCE_DISK_GB}GB" \
    --boot-disk-type="${GCE_DISK_TYPE}" \
    --service-account="$GCE_SA" \
    --scopes=cloud-platform \
    --tags=news-wp \
    --metadata=enable-osconfig=TRUE
fi

IP="$(gcloud compute instances describe "$GCE_NAME" --zone="$GCE_ZONE" --project="$PROJECT" \
  --format='get(networkInterfaces[0].accessConfigs[0].natIP)')"
STATUS="$(gcloud compute instances describe "$GCE_NAME" --zone="$GCE_ZONE" --project="$PROJECT" \
  --format='get(status)')"

echo
echo "Done. VM can read $uri as soon as it boots (IAM was granted first)."
echo "  status:  $STATUS"
echo "  ssh:     gcloud compute ssh $GCE_NAME --zone=$GCE_ZONE --project=$PROJECT"
echo "  ip:      $IP"
echo "  console: $CONSOLE"
echo
echo "On the VM:"
echo "  sudo apt-get update && sudo apt-get install -y git"
echo "  git clone <this-repo> /opt/news-wp && cd /opt/news-wp"
echo "  sudo cp .env.example .env && sudo nano .env"
echo "  sudo bash scripts/setup-vps.sh"
echo "  sudo bash scripts/gcs.sh check"
echo "  sudo bash scripts/pull-gcs-backup.sh"
echo
echo "Next: docs/08-implementation-guide.md"
