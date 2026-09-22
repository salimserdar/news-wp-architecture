# 07 — Operations: Security, Monitoring, Backups, Deploys

## Security hardening

### Network
- `ufw`: default deny incoming; allow SSH (custom port, rate-limited); allow 80/443
  from anywhere on first boot (`WEB_OPEN=1`), then **only** Cloudflare IPv4/IPv6 ranges
  (`WEB_OPEN=0`). Cron refreshes the list weekly from
  `https://www.cloudflare.com/ips-v4` / `ips-v6`.
- Authenticated Origin Pulls: Nginx requires Cloudflare's client cert → even if someone
  finds the origin IP, they can't complete a TLS handshake.
- MariaDB: localhost only (unix socket / 127.0.0.1).

### WordPress
- `DISALLOW_FILE_EDIT` in production (deploys via git/WP-CLI, not the admin UI).
- File permissions: WordPress tree owned by `www-data`; `wp-config.php` mode 640.
- Block PHP execution in `uploads/` at the Nginx level.
- Archive/AI/scanner User-Agents return 403 at Nginx (`map $http_user_agent $bad_bot`
  in `config/nginx/http.conf`). Cloudflare still lets “verified” SEO crawlers
  through; origin is what stops them hitting PHP. Search/social preview bots
  (Googlebot, Bingbot, facebookexternalhit, Twitterbot, …) are not on the list.
  Count: `grep 'bot=1' /var/log/nginx/access.log | awk '$9==403' | wc -l`.
- `xmlrpc.php` disabled (or allow-listed for Jetpack IPs if used).
- Login: Cloudflare rate limit + WAF rule on `/wp-login.php`; consider Cloudflare Access
  (Zero Trust, free for ≤ 50 users) in front of `/wp-admin` for editors — removes the whole
  brute-force class of problems.
- Plugin policy: each plugin is a performance and security liability. Target < 15 active
  plugins; audit with `wp plugin list --update=available` weekly; WP core minor auto-updates on.
- Security headers via Nginx: `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`,
  `Permissions-Policy`, CSP in report-only mode first.

### Server
- Unattended upgrades for security patches; monthly window for kernel reboots.
- `fail2ban` on SSH.
- Auditd or at least `last`/`journalctl` review in the weekly ops checklist.

## Monitoring

Recommendation: **Netdata** (single binary, zero-config, per-second metrics, free) with its
cloud dashboard, or Prometheus exporters + Grafana Cloud free tier if we want long retention.

| Signal | Source | Alert threshold |
|--------|--------|-----------------|
| Nginx cache status distribution | access log `$upstream_cache_status` → GoAccess / Netdata log parser | BYPASS > 10 % or MISS > 20 % of HTML for 10 min |
| Cloudflare cache hit ratio (HTML) | Cloudflare Analytics / GraphQL API | < 70 % for 30 min |
| PHP-FPM active vs max children | FPM `/status` via `cgi-fcgi` | active ≥ 80 % of max for 2 min |
| PHP-FPM listen queue | FPM status `listen queue` | > 0 sustained |
| PHP slow log | `request_slowlog_timeout = 5s` | any entry → review |
| MariaDB buffer pool hit rate, threads_running, slow queries | `SHOW GLOBAL STATUS`, slow log | hit rate < 99 %; threads_running > 8 |
| Disk usage / inodes (uploads, cache, logs) | node metrics | > 80 % |
| Load, CPU steal (VPS neighbours!), memory, OOM events | node metrics / `journalctl -k` | steal > 10 %; any OOM kill |
| HTTP 5xx rate at origin and at edge | Nginx log + Cloudflare | > 0.5 % for 5 min |
| Uptime / TTFB from outside | Cloudflare Health Check or UptimeRobot | 2 consecutive failures |

Weekly review: top slow queries, plugins updated, disk growth trend, cache ratio trend.

## Backups

| What | How | When | Retention | Where |
|------|-----|------|-----------|-------|
| Database | `mariadb-dump --single-transaction --quick` piped to `zstd` | Nightly + before every deploy | 14 daily | Cloud Storage (`GCS_BUCKET`) and/or rclone (`BACKUP_RCLONE_REMOTE`: R2 / B2) |
| Uploads | `gcloud storage rsync` and/or `rclone sync` incremental | Nightly | Mirror + 30-day version history in bucket | Same bucket |
| Code + config | git (this repo + site tree) | On change | — | Git remote |
| Server config | `/etc` snapshot via `etckeeper` | On change | — | Git |

Restore drill: quarterly, into the staging vhost, timed. A backup that has never been
restored is a hope, not a backup.

After media cut-over ([doc 10](10-r2-media-offload.md)): stop the origin `uploads/`
rsync in `scripts/backup.sh`. R2 object versioning is the media backup; restore with
`rclone copy` from the **media** bucket (`news-media`), not from `BACKUP_RCLONE_REMOTE`.
DB dumps and the commands below stay as they are until that day.

### Google Cloud Storage (upload / download)

On a GCE VM the instance service account is enough — no JSON key. Grant that account
`roles/storage.objectAdmin` on the bucket, set the VM scope to `cloud-platform`, then:

```bash
# laptop, once per project (IAM before any VM):
scripts/gcs.sh grant-sa
scripts/create-gce-vm.sh

# .env
GCS_BUCKET=tr724-backup

# on the VPS:
scripts/gcs.sh check                         # list the bucket (proves IAM + scopes)
scripts/pull-gcs-backup.sh --check           # see the dump + wp-content
scripts/pull-gcs-backup.sh                   # -> import/wp_tr724.sql + import/wp-content/
scripts/gcs.sh upload ./file.txt             # VM -> bucket
scripts/gcs.sh download file.txt ./          # bucket -> VM
scripts/gcs.sh backup                        # push backups/db + backups/uploads
```

Nightly `scripts/backup.sh` calls `gcs.sh backup` when `GCS_BUCKET` is set.

Optional paths (still no JSON key — GCE metadata / ADC):

```bash
sudo bash scripts/gcs.sh install          # rclone (env_auth) + gcsfuse
scripts/gcs.sh rclone-config
scripts/gcs.sh rclone-upload ./file.txt
scripts/gcs.sh rclone-download file.txt ./

scripts/gcs.sh mount                      # folder at GCS_MOUNT (/mnt/gcs)
# cp ./file.txt /mnt/gcs/file.txt         # upload
# cp /mnt/gcs/file.txt ./file.txt         # download
scripts/gcs.sh unmount
# persist:  sudo bash scripts/gcs.sh fstab
```

Do not put MariaDB data or live `wp-content/uploads` on the gcsfuse mount.

From a machine that can change IAM (often your laptop, not the VM):

```bash
scripts/gcs.sh grant-vm VM_NAME ZONE   # existing VM only
scripts/gcs.sh grant-sa                # IAM in advance, no VM needed
```

## Deploy flow

```
git push → on the VPS:
  1. git pull in this repo
  2. sudo bash scripts/setup-vps.sh     (refreshes nginx/PHP/mu-plugins; does not wipe DB)
  3. wp core/plugin/theme verify-checksums
  4. systemctl reload php8.3-fpm        (clears OPcache, zero dropped requests)
  5. purge Nginx + Cloudflare cache for changed theme assets (or purge everything, off-peak)
  6. smoke test: curl -skI -H "Host: $SITE_DOMAIN" https://127.0.0.1/ | grep -iE 'HTTP|x-fastcgi-cache'
```

## Runbooks (to write during implementation)

- Traffic spike checklist: check cache status ratio → check FPM queue → check DB threads →
  if PHP saturated, temporarily raise Nginx TTL & disable non-essential plugins via WP-CLI.
- "Site shows stale content": purge single URL (Nginx then Cloudflare) → verify headers →
  check purge plugin logs (`grep news-cache /var/log/php8.3-fpm.log` / syslog).
- "Site down but Nginx up": confirm stale-serving is working (readers OK), then fix PHP/DB.
- Emergency full cache purge and warm.
- Restore from backup (DB, uploads, full).
