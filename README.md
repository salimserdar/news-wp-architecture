# news-wp-architecture

High-traffic WordPress news site on a single 8 vCPU / 32 GB Ubuntu VPS:
**Cloudflare → nginx FastCGI full-page cache → PHP-FPM → MariaDB.**

- **Start here:** [`docs/00-create-gce-vm.md`](docs/00-create-gce-vm.md) (create the VM + grant `gs://tr724-backup` first), then [`docs/08-implementation-guide.md`](docs/08-implementation-guide.md).
- Full index: [`docs/README.md`](docs/README.md).

```bash
# laptop
gcloud config set project YOUR_PROJECT_ID
gcloud config set compute/zone europe-west1-b
scripts/create-gce-vm.sh

# on the VM
cp .env.example .env            # fill in domain, DB passwords, Cloudflare zone/token
sudo bash scripts/setup-vps.sh
scripts/gcs.sh check
scripts/pull-gcs-backup.sh      # gs://tr724-backup -> import/
scripts/import-db.sh import/wp_tr724.sql old-domain.com
scripts/import-wp-content.sh import/wp-content
scripts/post-import.sh
make stats
```
