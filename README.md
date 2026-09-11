# news-wp-architecture

High-traffic WordPress news site on a single 8 vCPU / 32 GB VPS:
**Cloudflare → nginx FastCGI full-page cache → PHP-FPM (public + editor pools) → Redis object cache → MariaDB**, all as a Docker Compose stack.

- **Start here:** [`docs/08-docker-implementation-guide.md`](docs/08-docker-implementation-guide.md) — VPS to live site with your imported DB and `wp-content`.
- Architecture, caching strategy, sizing and decisions: [`docs/README.md`](docs/README.md).

```bash
cp .env.example .env            # fill in domain, DB passwords, Cloudflare zone/token
docker compose up -d --build
scripts/import-db.sh import/site.sql.gz old-domain.com
scripts/import-wp-content.sh import/wp-content.tar.gz
scripts/post-import.sh
make stats
```
