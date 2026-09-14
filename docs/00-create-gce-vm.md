# 00 — Create the GCE VM (gcloud) and grant the backup bucket first

Spin up the origin from your laptop. **Bucket IAM is applied before the instance
exists**, so the VM can read `gs://tr724-backup` the moment it boots — no
stop/start to fix access scopes.

## Contents

1. [One command](#1-one-command)
2. [Grant the bucket in advance (no VM yet)](#2-grant-the-bucket-in-advance-no-vm-yet)
3. [Machine size](#3-machine-size)
4. [What the script does](#4-what-the-script-does)
5. [Manual `gcloud` (same result)](#5-manual-gcloud-same-result)
6. [SSH and next steps](#6-ssh-and-next-steps)
7. [Existing VM](#7-existing-vm)

---

## 1. One command

On a machine with [gcloud](https://cloud.google.com/sdk/docs/install) logged in
as a project owner:

```bash
gcloud config set project YOUR_PROJECT_ID
gcloud config set compute/zone europe-west1-b   # pick your region

cd /path/to/news-wp-architecture
cp .env.example .env          # already has GCS_BUCKET=tr724-backup
scripts/create-gce-vm.sh
```

That:

1. Grants the Compute Engine service account `roles/storage.objectAdmin` on
   `gs://tr724-backup`
2. Opens VPC firewall tcp:80,443 for VMs tagged `news-wp`
3. Creates Ubuntu 24.04, `e2-highmem-2` (2 vCPU / 16 GB), 200 GB disk,
   `--scopes=cloud-platform`

Smaller box (2 vCPU / 8 GB):

```bash
GCE_MACHINE=e2-standard-2 GCE_DISK_GB=100 scripts/create-gce-vm.sh
```

Other knobs (env or flags): `GCE_NAME`, `GCE_ZONE`, `GCE_MACHINE`, `GCE_DISK_GB`,
`GCE_SA`, `GCS_BUCKET`. See `.env.example`.

---

## 2. Grant the bucket in advance (no VM yet)

Do this if you want IAM in place hours or days before you create the instance:

```bash
# default Compute Engine SA for the current gcloud project
scripts/gcs.sh grant-sa

# or a dedicated SA
scripts/gcs.sh grant-sa news-wp@YOUR_PROJECT_ID.iam.gserviceaccount.com

# then later, same project/zone:
scripts/create-gce-vm.sh
```

`--grant-only` on the create script is the same IAM step without creating a VM:

```bash
scripts/create-gce-vm.sh --grant-only
```

The VM must still be created with `--scopes=cloud-platform` (the create script
does that). IAM on the bucket is not enough if the instance only has the default
`storage-ro` scope.

---

## 3. Machine size

| Type | vCPU | RAM | Use |
|------|-----:|----:|-----|
| `e2-highmem-2` (script default) | 2 | 16 GB | Production origin with FastCGI cache |
| `e2-standard-2` | 2 | 8 GB | Staging / modest traffic — set `DB_BUFFER_POOL=1G` `PHP_MAX_CHILDREN=6` |
| `e2-standard-8` | 8 | 32 GB | Original sizing in [doc 04](04-resource-allocation.md) |

Cached HTML barely uses CPU. RAM is what matters (MariaDB buffer pool + PHP
children + page cache). Details: [doc 04](04-resource-allocation.md).

---

## 4. What the script does

| Step | Why |
|------|-----|
| `gcloud services enable compute storage` | APIs must be on once per project |
| `gcloud storage buckets add-iam-policy-binding` **first** | SA can list/read/write `tr724-backup` before any disk exists |
| Firewall `news-wp-http-https` | GCP VPC allows 80/443; later `WEB_OPEN=0` on the VM locks ufw to Cloudflare |
| `gcloud compute instances create` with `--scopes=cloud-platform` | Metadata server tokens can call Storage; no JSON key |

Re-running is safe: existing firewall and VM are left alone; IAM binding is
idempotent.

---

## 5. Manual `gcloud` (same result)

```bash
PROJECT=$(gcloud config get-value project)
NUMBER=$(gcloud projects describe "$PROJECT" --format='value(projectNumber)')
SA="${NUMBER}-compute@developer.gserviceaccount.com"
ZONE=europe-west1-b

# 1. Bucket first
gcloud storage buckets add-iam-policy-binding gs://tr724-backup \
  --member="serviceAccount:${SA}" \
  --role="roles/storage.objectAdmin"

# 2. Firewall (once per project)
gcloud compute firewall-rules create news-wp-http-https \
  --allow=tcp:80,tcp:443 --target-tags=news-wp

# 3. VM already allowed to use the bucket
gcloud compute instances create news-wp \
  --zone="$ZONE" \
  --machine-type=e2-highmem-2 \
  --image-family=ubuntu-2404-lts-amd64 \
  --image-project=ubuntu-os-cloud \
  --boot-disk-size=200GB \
  --boot-disk-type=pd-balanced \
  --service-account="$SA" \
  --scopes=cloud-platform \
  --tags=news-wp
```

---

## 6. SSH and next steps

```bash
gcloud compute ssh news-wp --zone=europe-west1-b
```

Then follow **[doc 08](08-implementation-guide.md)** from Step 1 (git clone,
`setup-vps.sh`). Skip doc 08 Step 3 (`grant-vm`) — the bucket was already
granted. On the VM:

```bash
scripts/gcs.sh check
scripts/pull-gcs-backup.sh
```

---

## 7. Existing VM

If `GCE_NAME` (in `.env`) is already taken, the script **exits** instead of pretending
it created a VM. Use `--name news-wp-2`, or `--reuse` / `--recreate`.

If an instance exists but cannot list the bucket (403):

```bash
scripts/gcs.sh grant-vm news-wp europe-west1-b
```

That binds IAM and tries to set `cloud-platform` scopes. Scopes can only change
while the VM is **stopped**; the command prints stop / start if needed.

Prefer creating the next VM with `scripts/create-gce-vm.sh` so this never
happens.
