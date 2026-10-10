# 05 — Operations

Security, monitoring, backups, and the deploy flow for a site that is already
live ([04](04-cloudflare-and-go-live.md)).

## Security hardening

### Network

- `ufw`: default deny incoming; allow SSH (custom port, rate-limited); allow
  80/443 from anywhere on first boot (`WEB_OPEN=1`), then **only** Cloudflare
  IPv4/IPv6 ranges (`WEB_OPEN=0`). Cron refreshes the list weekly from
  `https://www.cloudflare.com/ips-v4` and `ips-v6`.
- Authenticated Origin Pulls: nginx requires Cloudflare's client certificate, so
  even if someone finds the origin IP they cannot complete a TLS handshake.
- MariaDB: localhost only (unix socket / 127.0.0.1).

### WordPress

- `DISALLOW_FILE_EDIT` in production (deploys via git / WP-CLI, not the admin UI).
- File permissions: WordPress tree owned by `www-data`; `wp-config.php` mode 640.
- Block PHP execution in `uploads/` at the nginx level.
- Archive/AI/scanner User-Agents return 403 at nginx (`map $http_user_agent $bad_bot`
  in `config/nginx/http.conf`). Cloudflare still lets "verified" SEO crawlers
  through; the origin is what stops them hitting PHP. Search and social preview
  bots (Googlebot, Bingbot, facebookexternalhit, Twitterbot, …) are not on the
  list. Count: `grep 'bot=1' /var/log/nginx/access.log | awk '$9==403' | wc -l`.
- `xmlrpc.php` disabled (or allow-listed for Jetpack IPs if used).
- Login: Cloudflare rate limit plus a WAF rule on `/wp-login.php`; consider
  Cloudflare Access (Zero Trust, free for ≤ 50 users) in front of `/wp-admin`
  for editors.
- Plugin policy: each plugin is a performance and security liability. Target
  fewer than 15 active plugins; audit with `wp plugin list --update=available`
  weekly; WordPress core minor auto-updates on.
- Security headers via nginx: `X-Content-Type-Options`, `X-Frame-Options`,
  `Referrer-Policy`, `Permissions-Policy`, CSP in report-only mode first.

### Server

- Unattended upgrades for security patches; monthly window for kernel reboots.
- `fail2ban` on SSH.
- Auditd, or at least a `last` / `journalctl` review in the weekly ops checklist.

## Monitoring

Recommendation: **Netdata** (single binary, zero-config, per-second metrics) with
its cloud dashboard, or Prometheus exporters plus Grafana Cloud free tier if you
want long retention.

| Signal | Source | Alert threshold |
|--------|--------|-----------------|
| Nginx cache status distribution | access log `$upstream_cache_status` → GoAccess / Netdata log parser | BYPASS > 10 % or MISS > 20 % of HTML for 10 min |
| Cloudflare cache hit ratio (HTML) | Cloudflare Analytics / GraphQL API | < 70 % for 30 min |
| PHP-FPM active vs max children | FPM `/status` via `cgi-fcgi` | active ≥ 80 % of max for 2 min |
| PHP-FPM listen queue | FPM status `listen queue` | > 0 sustained |
| PHP slow log | `request_slowlog_timeout = 5s` | any entry → review |
| MariaDB buffer pool hit rate, threads_running, slow queries | `SHOW GLOBAL STATUS`, slow log | hit rate < 99 %; threads_running > 8 |
| Disk usage / inodes (uploads, cache, logs) | node metrics | > 80 % |
| Load, CPU steal, memory, OOM events | node metrics / `journalctl -k` | steal > 10 %; any OOM kill |
| HTTP 5xx rate at origin and at edge | nginx log + Cloudflare | > 0.5 % for 5 min |
| Uptime / TTFB from outside | Cloudflare Health Check or UptimeRobot | 2 consecutive failures |

Weekly review: top slow queries, plugins updated, disk growth trend, cache ratio trend.

## Backups

| What | How | When | Retention | Where |
|------|-----|------|-----------|-------|
| Database | `mariadb-dump --single-transaction --quick` piped to `zstd` | Nightly + before every deploy | 14 daily | Local `backups/db/`, plus rclone when `BACKUP_RCLONE_REMOTE` is set |
| Uploads | `rclone sync` incremental | Nightly | Mirror on the remote; use the remote's versioning (about 30 days) if you want history | Same remote |
| Code + config | git (this repo + the site tree) | On change | — | Git remote |
| Server config | `/etc` snapshot via `etckeeper` | On change | — | Git |

`BACKUP_RCLONE_REMOTE` is any rclone remote, for example `r2:news-backups` or a
Backblaze B2 remote. Leave it empty to keep backups only on the VPS disk.

```bash
# .env
BACKUP_RCLONE_REMOTE=r2:news-backups

# once, as the user that runs the nightly cron (often root)
rclone config    # create that remote; do not commit the config

# on the VPS
make backup      # writes backups/db/*.sql.zst and syncs uploads
```

Nightly `scripts/backup.sh` copies `backups/db` and syncs `backups/uploads` to
`BACKUP_RCLONE_REMOTE` when it is set and `rclone` is on `PATH`. Ubuntu 24.04's
apt rclone is often too old for the Cloudflare provider. If `rclone version` is
below 1.61:

```bash
curl -fsSL https://rclone.org/install.sh | sudo bash
```

Restore a database dump:

```bash
zstd -dc backups/db/FILE.sql.zst | mysql "$DB_NAME"
```

Restore drill: quarterly, into a staging vhost, timed. A backup that has never
been restored is a hope, not a backup.

After media cut-over ([07](07-media-offload.md)): stop the origin `uploads/`
rsync in `scripts/backup.sh`. R2 object versioning is the media backup; restore
with `rclone copy` from the **media** bucket (`news-media`), not from
`BACKUP_RCLONE_REMOTE`. Database dumps stay as they are.

Do not mount object storage (rclone mount or similar) as the live
`wp-content/uploads` tree or as the MariaDB datadir.

## Day-to-day commands

| Task | Command |
|------|---------|
| Status / logs | `systemctl status nginx php8.3-fpm mariadb` · `journalctl -u nginx -u php8.3-fpm -f` · `tail -f /var/log/nginx/access.log` |
| WP-CLI | `scripts/wp.sh plugin list` (or `make wp ARGS="plugin list"`) |
| Purge everything (nginx + Cloudflare) | `make purge` or the admin-bar button |
| Purge specific URLs | `scripts/wp.sh news-cache purge https://SITE_DOMAIN/some/url/` |
| Cache statistics | `make stats` |
| Origin load test | [06](06-load-test.md) · `make loadtest-urls` · `make loadtest-observe` |
| Reload nginx after config edits | `make reload-nginx` (as root) |
| Apply PHP / pool config edits | re-run `scripts/setup-vps.sh` or `systemctl reload php8.3-fpm` |
| WordPress core / plugin updates | wp-admin as usual, or `scripts/wp.sh core update && scripts/wp.sh plugin update --all` |
| Backup now | `make backup` |
| Restore DB | `zstd -dc backups/db/FILE.sql.zst \| mysql "$DB_NAME"` |

### Where the knobs are

| Want to change… | Edit |
|-----------------|------|
| Page TTL in nginx / Cloudflare | `wp/mu-plugins/cache-control.php` (`TTL_*` constants) |
| Which URLs are purged on publish | `urls_for_post()` in `wp/mu-plugins/cache-purge.php`, or hook `news_cache_purge_urls` |
| Cookie / path bypass rules | `map` blocks in `config/nginx/http.conf` |
| Which bot User-Agents get 403 | `map $http_user_agent $bad_bot` in `config/nginx/http.conf` |
| PHP workers | `config/php/pool-www.conf` (`pm.max_children`) or `.env` `PHP_MAX_CHILDREN` |
| DB memory | `.env` → `DB_BUFFER_POOL` |
| Upload size limit | `client_max_body_size` (`config/nginx/http.conf`) and `upload_max_filesize` (`config/php/conf.d/zz-wp.ini`) |

### Things that will bite you

- **Cache purge by file deletion + `open_file_cache`** means nginx keeps serving
  deleted files. `open_file_cache` is enabled only in the static-assets location
  for this reason. Don't add it globally.
- **A plugin sets a cookie on anonymous pages** → nginx never caches them
  (`Set-Cookie` responses are not cached, by design). Symptom: the `MISS` ratio
  climbs in `make stats`. Find the plugin, fix it, or remove it.
- **Query-string variants** (`?utm_source=…`) share the cache entry in nginx and,
  with the Cache Rule in [04](04-cloudflare-and-go-live.md), at Cloudflare too.
  Any *other* query string creates its own cache entry.
- **Plugin updates from wp-admin** are picked up within 60 s
  (`opcache.revalidate_freq = 60`). To force it: `systemctl reload php8.3-fpm`.
- **Newspaper / tagDiv + PHP JIT** hangs `wp-admin/load-styles.php`. JIT is
  disabled in the pool config.
- **Loopback to the public hostname.** Setup adds `127.0.0.1 SITE_DOMAIN` to
  `/etc/hosts` so wp-cron and Site Health do not hairpin out through Cloudflare.

## Deploy flow

```
git push → on the VPS:
  1. git pull in this repo
  2. sudo bash scripts/setup-vps.sh     (refreshes nginx/PHP/mu-plugins; does not wipe the DB)
  3. wp core/plugin/theme verify-checksums
  4. systemctl reload php8.3-fpm        (clears OPcache, zero dropped requests)
  5. purge nginx + Cloudflare cache for changed theme assets (or purge everything, off-peak)
  6. smoke test: curl -skI -H "Host: $SITE_DOMAIN" https://127.0.0.1/ | grep -iE 'HTTP|x-fastcgi-cache'
```

## Runbooks

- Traffic spike: check the cache status ratio → check the FPM queue → check DB
  threads → if PHP is saturated, temporarily raise the nginx TTL and disable
  non-essential plugins via WP-CLI.
- "Site shows stale content": purge the single URL (nginx, then Cloudflare) →
  verify headers → check purge plugin logs
  (`grep news-cache /var/log/php8.3-fpm.log` or syslog).
- "Site down but nginx up": confirm stale-serving is working (readers are OK),
  then fix PHP or the database.
- Emergency full cache purge and warm.
- Restore from backup (database, uploads, or both).
