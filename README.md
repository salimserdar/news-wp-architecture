# news-wp-architecture

High-traffic WordPress news site on a single 8 vCPU / 32 GB Ubuntu VPS:
**Cloudflare → nginx FastCGI full-page cache → PHP-FPM → MariaDB.**

- **Start here:** [`docs/08-implementation-guide.md`](docs/08-implementation-guide.md) — VPS to live site with your imported DB and `wp-content`.
- Architecture, caching strategy, sizing and decisions: [`docs/README.md`](docs/README.md).

```bash
cp .env.example .env            # fill in domain, DB passwords, Cloudflare zone/token
sudo bash scripts/setup-vps.sh
# from a laptop (once):  scripts/gcs.sh grant-vm VM_NAME ZONE
scripts/pull-gcs-backup.sh      # gs://tr724-backup -> import/
scripts/import-db.sh import/wp_tr724.sql old-domain.com
scripts/import-wp-content.sh import/wp-content
scripts/post-import.sh
make stats
```
