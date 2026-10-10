# High-Traffic WordPress News Site — Architecture Docs

Target: a WordPress news site that must survive traffic spikes (breaking news, social
virality) on a **single Ubuntu 24.04 VPS**, with Cloudflare in front, running
**native packages** (nginx, PHP-FPM, MariaDB). Any VPS provider works.

The guiding principle is simple: **the origin should almost never render a page.**
Every layer exists to keep requests away from PHP and MySQL.

```
Reader ──> Cloudflare (edge cache, WAF, TLS)
             └──> nginx (FastCGI full-page cache)
                    └──> PHP-FPM  ──> MariaDB
```

## Deploy, in order

| Step | Document |
|------|----------|
| 1 | [**01 — Provision the VPS**](01-provision-vps.md) — Ubuntu 24.04, size, SSH, ports |
| 2 | [**02 — Install and configure**](02-install-and-configure.md) — nginx, PHP-FPM, MariaDB, `.env`, Origin CA |
| 3 | [**03 — Import the site**](03-import-the-site.md) — copy the dump and `wp-content`, then import |
| 4 | [**04 — Cloudflare and go-live**](04-cloudflare-and-go-live.md) — DNS, Cache Rules, WAF, lock the firewall |
| 5 | [**05 — Operations**](05-operations.md) — security, monitoring, R2 database backups, deploys |
| 6 | [**06 — Load test**](06-load-test.md) — origin k6 runbook and empty results tables |
| 7 | [**07 — R2 media offload**](07-media-offload.md) — later, when `uploads/` outgrows the disk |

## Reference

Read these when you are choosing or tuning, not while you are installing.

| Document | What it covers |
|----------|----------------|
| [Requirements](reference/requirements.md) | Traffic goals, constraints, what "high traffic" means here |
| [Architecture](reference/architecture.md) | Layers, request flow, component responsibilities |
| [Caching](reference/caching.md) | Cache layers, TTLs, bypass rules, invalidation |
| [Resource allocation](reference/resources.md) | How CPU and RAM are divided on an 8 vCPU / 32 GB box |
| [Decisions](reference/decisions.md) | Options considered, decision log, open questions |

## Already in this repo

| Piece | Where |
|-------|-------|
| Base server (sysctl, swap, fail2ban, Cloudflare-aware firewall, weekly IP refresh, nightly backup cron) | `scripts/setup-vps.sh`, `scripts/cloudflare-ips.sh` |
| MariaDB tuning | `config/mariadb/` |
| PHP 8.3-FPM, OPcache, wp-config constants, system cron | `config/php/`, `scripts/setup-vps.sh` |
| nginx FastCGI cache, bypass maps, stale-while-revalidate, Cloudflare real IP, TLS | `config/nginx/` |
| TTLs, purge-on-publish, warmer, admin-bar button, `wp news-cache` | `wp/mu-plugins/` |
| DB import (MySQL 8 → MariaDB, domain rewrite), wp-content import, post-import cleanup | `scripts/import-db.sh`, `scripts/import-wp-content.sh`, `scripts/post-import.sh` |
| Backups (DB zstd into one locked R2 bucket, a folder per day), cache statistics, WP-CLI wrapper | `scripts/backup.sh`, `scripts/r2-db-backup.sh`, `Makefile` |

## Status

- [x] Architecture written and decisions taken ([Decisions](reference/decisions.md))
- [x] Native stack: nginx FastCGI cache, PHP-FPM, MariaDB, mu-plugins, scripts
- [ ] VPS provisioned ([01](01-provision-vps.md))
- [ ] Stack installed and `.env` filled ([02](02-install-and-configure.md))
- [ ] Database and `wp-content` imported ([03](03-import-the-site.md))
- [ ] Cloudflare configured and origin firewall locked ([04](04-cloudflare-and-go-live.md))
- [ ] Load test recorded ([06](06-load-test.md))
