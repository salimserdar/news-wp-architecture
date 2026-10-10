# news-wp-architecture

High-traffic WordPress news site on a single Ubuntu 24.04 VPS:
**Cloudflare → nginx FastCGI full-page cache → PHP-FPM → MariaDB.**

- **Start here:** [`docs/01-provision-vps.md`](docs/01-provision-vps.md), then install, import, and Cloudflare in that order.
- Full index: [`docs/README.md`](docs/README.md).

```bash
# on the VPS (Ubuntu 24.04)
sudo apt-get update && sudo apt-get install -y git
git clone <this-repo> /opt/news-wp && cd /opt/news-wp
cp .env.example .env            # domain, DB password, Cloudflare zone and token
sudo bash scripts/setup-vps.sh

# copy the SQL dump and wp-content into import/ (scp or rsync), then:
scripts/import-db.sh import/dump.sql old-domain.com
scripts/import-wp-content.sh import/wp-content
scripts/post-import.sh
make stats
```
