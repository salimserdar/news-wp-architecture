# 07 — Operations: Security, Monitoring, Backups, Deploys

## Security hardening

### Network
- `ufw`: default deny incoming; allow SSH (custom port, rate-limited); allow 80/443 **only**
  from Cloudflare IPv4/IPv6 ranges (cron job refreshes the list weekly from
  `https://www.cloudflare.com/ips-v4` / `ips-v6`).
- Authenticated Origin Pulls: Nginx requires Cloudflare's client cert → even if someone
  finds the origin IP, they can't complete a TLS handshake.
- MariaDB and Redis: no published ports; reachable only on the internal Docker network.

### WordPress
- `DISALLOW_FILE_EDIT`, `DISALLOW_FILE_MODS` in production (deploys via git/WP-CLI, not the admin UI).
- File permissions: code owned by deploy user, read-only for `www-data`; only `uploads/` and
  `cache/` writable.
- Block PHP execution in `uploads/` at the Nginx level.
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
- `fail2ban` on SSH and Nginx auth/limit logs.
- Auditd or at least `last`/`journalctl` review in the weekly ops checklist.

## Monitoring

Recommendation: **Netdata** (single binary, zero-config, per-second metrics, free) with its
cloud dashboard, or Prometheus exporters + Grafana Cloud free tier if we want long retention.

| Signal | Source | Alert threshold |
|--------|--------|-----------------|
| Nginx cache status distribution | access log `$upstream_cache_status` → GoAccess / Netdata log parser | BYPASS > 10 % or MISS > 20 % of HTML for 10 min |
| Cloudflare cache hit ratio (HTML) | Cloudflare Analytics / GraphQL API | < 70 % for 30 min |
| PHP-FPM active vs max children | FPM `/status` page (localhost only) | active ≥ 80 % of max for 2 min |
| PHP-FPM listen queue | FPM status `listen queue` | > 0 sustained |
| PHP slow log | `request_slowlog_timeout = 5s` | any entry → review |
| MariaDB buffer pool hit rate, threads_running, slow queries | `SHOW GLOBAL STATUS`, slow log | hit rate < 99 %; threads_running > 8 |
| Redis hit ratio, evicted keys, memory | `INFO` | hit ratio < 85 %; evictions > 0 |
| Disk usage / inodes (uploads, cache, logs) | node metrics | > 80 % |
| Load, CPU steal (VPS neighbours!), memory, OOM events | node metrics / `journalctl -k` | steal > 10 %; any OOM kill |
| HTTP 5xx rate at origin and at edge | Nginx log + Cloudflare | > 0.5 % for 5 min |
| Uptime / TTFB from outside | Cloudflare Health Check or UptimeRobot | 2 consecutive failures |

Weekly review: top slow queries, plugins updated, disk growth trend, cache ratio trend.

## Backups

| What | How | When | Retention | Where |
|------|-----|------|-----------|-------|
| Database | `mariabackup` (hot, consistent) or `mysqldump --single-transaction --quick` piped to `zstd` | Nightly + before every deploy | 14 daily, 8 weekly | Cloudflare R2 / Backblaze B2 (offsite, versioned bucket) |
| Uploads | `rclone sync` incremental | Nightly | Mirror + 30-day version history in bucket | Same bucket |
| Code + config | git (this repo + site repo) | On change | — | Git remote |
| Server config | `/etc` snapshot via `etckeeper` | On change | — | Git |

Restore drill: quarterly, into the staging vhost, timed. A backup that has never been
restored is a hope, not a backup.

## Deploy flow

```
git push → deploy.sh on server:
  1. git pull in release dir (or rsync from CI artifact)
  2. composer install --no-dev (if used)
  3. wp core/plugin/theme verify-checksums
  4. symlink swap  current → new release   (atomic)
  5. systemctl reload php8.3-fpm           (clears OPcache, zero dropped requests)
  6. wp cache flush                         (Redis) — only if data model changed
  7. purge Nginx + Cloudflare cache for changed theme assets (or purge everything, off-peak)
  8. smoke test: curl -sI https://site/ | grep -E 'HTTP|x-fastcgi-cache|cf-cache-status'
```

Rollback = point the symlink at the previous release + FPM reload. Keep 3 releases.

## Runbooks (to write during implementation)

- Traffic spike checklist: check cache status ratio → check FPM queue → check DB threads →
  if PHP saturated, temporarily raise Nginx TTL & disable non-essential plugins via WP-CLI.
- "Site shows stale content": purge single URL (Nginx then Cloudflare) → verify headers →
  check purge plugin logs.
- "Site down but Nginx up": confirm stale-serving is working (readers OK), then fix PHP/DB.
- Emergency full cache purge and warm.
- Restore from backup (DB, uploads, full).
