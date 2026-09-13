# 08 — Docker Implementation Guide (step by step)

Everything in this repo is ready to run. This guide takes a fresh Ubuntu 24.04 VPS to a
live, cached WordPress site with **your existing database and `wp-content`** in roughly
an hour, most of it waiting for transfers.

```
Cloudflare ──> [VPS: Docker]
                 nginx ──(cache miss)──> php (public pool)      ──> redis
                       ──(logged-in) ──> php-admin (editor pool) ──> mariadb
                 cron (WP-CLI every 60 s)     wpcli (one-off tool)
```

## What you need before starting

| Item | Where it comes from |
|------|--------------------|
| VPS root SSH access, Ubuntu 24.04 LTS, 8 vCPU / 32 GB, NVMe | your provider |
| Your domain on Cloudflare (orange-clouded later) | Cloudflare dashboard |
| Cloudflare Origin CA certificate + key | SSL/TLS → Origin Server → Create Certificate |
| Cloudflare API token, permission **Zone → Cache Purge → Purge**, scoped to the zone; and the Zone ID | My Profile → API Tokens; Zone overview (right column) |
| DB dump of the existing site (`.sql`, `.sql.gz` or `.sql.zst`) | `mysqldump --single-transaction --quick olddb \| gzip > site.sql.gz` on the old host |
| The old site's `wp-content/` (at least `uploads/`, plus `themes/`, `plugins/`) | `tar czf wp-content.tar.gz wp-content/` on the old host |
| The old site's table prefix and domain | old `wp-config.php` (`$table_prefix`) |

## Repository layout

```
docker-compose.yml         the whole stack; resource limits via .env
.env.example               copy to .env
config/
  nginx/                   Dockerfile (www-data uid 82 + self-signed fallback), nginx.conf,
                           conf.d/site.conf, snippets/ (cache, tls, hardening, realip)
  php/                     Dockerfile (igbinary, redis, imagick), pool-www.conf, pool-admin.conf, conf.d/
  mariadb/zz-tuning.cnf    InnoDB tuning
  redis/redis.conf         2 GB LRU cache, no persistence
wp/mu-plugins/             cache-control.php (TTL headers), cache-purge.php (nginx+Cloudflare purge,
                           warmer, admin-bar button, WP-CLI), perf-tweaks.php
scripts/                   setup-vps.sh, cloudflare-ips.sh, import-db.sh, import-wp-content.sh,
                           post-import.sh, wp.sh, backup.sh, cache-stats.sh
wordpress/                 (git-ignored) the live WordPress tree — created on first start
import/                    (git-ignored) drop DB dumps / archives here
backups/  logs/            (git-ignored)
```

---

## Step 1 — Prepare the VPS (10 min)

```bash
ssh root@VPS_IP
apt-get update && apt-get install -y git
git clone <this-repo> /opt/news-wp && cd /opt/news-wp
SSH_PORT=22 bash scripts/setup-vps.sh        # change SSH_PORT if you use a custom port
```

The script installs Docker, tunes the kernel, adds 2 GB swap, enables `fail2ban`, and
sets the firewall so **only Cloudflare IP ranges can reach ports 80/443**.

Important: Docker publishes ports by writing its own iptables rules that *bypass ufw*.
The script therefore adds the Cloudflare allow-list to the `DOCKER-USER` chain
(`scripts/cloudflare-ips.sh --iptables`) and installs a systemd unit so it survives
reboots, plus a weekly cron to refresh the IP ranges. Verify after boot:

```bash
iptables -L DOCKER-USER -n | head -5        # should show a jump to CF-ONLY for dports 80,443
```

Until DNS points at Cloudflare you cannot reach the site from your laptop. For testing
before cut-over, temporarily allow your own IP:
`iptables -I CF-ONLY 1 -s YOUR.IP.ADDR.ESS -j RETURN` (remove it later or just reboot).

## Step 2 — Configure (5 min)

```bash
cp .env.example .env && nano .env
```

Fill in `SITE_DOMAIN` (e.g. `www.example.com`), strong DB passwords, `DB_TABLE_PREFIX`
**exactly as in your old `wp-config.php`**, `CF_ZONE_ID`, `CF_API_TOKEN`. Leave resource
limits at their defaults (sized for 8 vCPU / 32 GB, see doc 04).

Put the Cloudflare Origin CA certificate in `config/nginx/certs/origin.pem` and the key in
`config/nginx/certs/origin.key` (see `config/nginx/certs/README.md`). If you skip this
for now, nginx generates a self-signed pair and Cloudflare must run in "Full" mode
instead of "Full (strict)".

## Step 3 — Start the stack (5–10 min, image build)

```bash
docker compose up -d --build
docker compose ps                     # wait until mariadb/redis are healthy, php/php-admin up
docker compose logs -f php            # "WordPress has been successfully copied" then "ready to handle connections"
```

On first start the `php` container copies a fresh WordPress core into `./wordpress/` and
writes an env-driven `wp-config.php`. **Do not copy your old `wp-config.php`** — all
constants (URLs, Redis, cache purge, salts via DB) are set from `docker-compose.yml`.

Quick check from the VPS itself:

```bash
curl -sk -o /dev/null -w "%{http_code} cache=%header{x-fastcgi-cache}\n" -H "Host: $SITE_DOMAIN" https://localhost/
```
You'll get a 302/200 to the WordPress installer at this point — expected; the DB is empty.

## Step 4 — Import your database (5–30 min depending on size)

```bash
# copy the dump from the old server
scp olduser@old-host:/path/site.sql.gz import/

scripts/import-db.sh import/site.sql.gz old-domain.com
```

What the script does:
1. Checks the dump's table prefix matches `DB_TABLE_PREFIX` in `.env`.
2. Drops any existing tables and streams the dump into MariaDB, fixing MySQL 8-only
   collations (`utf8mb4_0900_*`) and GTID statements on the fly.
3. Runs `wp search-replace` for `https://old-domain.com`, `http://old-domain.com` and
   `//old-domain.com` → `https://SITE_DOMAIN` across all tables (serialized-data safe,
   GUIDs untouched).
4. Sets `home`/`siteurl`, flushes rewrites, runs `wp core update-db`, purges the cache.

If the old site and new site use the **same domain**, omit the second argument.

## Step 5 — Import wp-content (uploads, themes, plugins)

```bash
scp olduser@old-host:/path/wp-content.tar.gz import/
scripts/import-wp-content.sh import/wp-content.tar.gz
# or, from a directory you rsynced:  scripts/import-wp-content.sh /mnt/old/wp-content
# uploads only:                       scripts/import-wp-content.sh <src> --uploads-only
```

Copies `uploads/`, `themes/`, `plugins/`, `languages/` into `wordpress/wp-content/` and fixes
ownership (uid 82 = `www-data` inside the containers). It deliberately skips old caching
drop-ins (`object-cache.php`, `advanced-cache.php`), old cache directories and `mu-plugins/`
(ours are mounted from `wp/mu-plugins/`).

For very large `uploads/` prefer `rsync` directly from the old server (resumable):

```bash
rsync -avz --info=progress2 olduser@old-host:/path/wp-content/uploads/ wordpress/wp-content/uploads/
chown -R 82:82 wordpress/wp-content/uploads
```

## Step 6 — Post-import (2 min)

```bash
scripts/post-import.sh
```

Deactivates page-cache plugins that would fight nginx (WP Rocket, W3TC, WP Super Cache,
LiteSpeed, Nginx Helper, the Cloudflare plugin, …), installs and enables **Redis Object
Cache**, flushes rewrites, lists cron events, purges + warms, and does a smoke test.
Expected last lines: `200 X-FastCGI-Cache=HIT`.

Then log in at `https://SITE_DOMAIN/wp-login.php` (once DNS is switched, or via the
temporary firewall exception + a hosts-file entry) and check:
- Settings → Permalinks: click Save once (harmless, ensures rewrite rules).
- Settings → Redis: "Status: Connected", client **PhpRedis**, serializer igbinary.
- Admin bar: a **Purge cache** button is present for editors and admins.

## Step 7 — Cloudflare (10 min)

In the dashboard for the zone:

1. **DNS**: `A` record for `SITE_DOMAIN` (and apex, if used) → VPS IP, **proxied** (orange cloud).
   Lower the old record's TTL a day ahead if you can.
2. **SSL/TLS → Overview**: *Full (strict)* (or *Full* if using the self-signed fallback).
   **Edge Certificates**: Always Use HTTPS on, HSTS on (after you've confirmed HTTPS works),
   Minimum TLS 1.2. Optional: **Origin Server → Authenticated Origin Pulls** on, then
   uncomment the two lines in `config/nginx/snippets/tls.conf` and `make reload-nginx`.
3. **Caching → Configuration**: Caching level Standard; **Tiered Cache** (Smart) on.
   **Browser Cache TTL**: *Respect Existing Headers*.
4. **Caching → Cache Rules** — create two rules. When several rules match, **the last
   matching rule wins** for each setting, so the bypass rule must come *after* the
   cache-everything rule:

   **Rule 1 — "Cache everything, honour origin TTL"**
   - When: `(http.host eq "SITE_DOMAIN")`
   - Then: Cache eligibility **Eligible for cache**; Edge TTL **Use cache-control header if present, bypass cache if not**; Browser TTL **Respect origin**;
     Cache key → Query string → **Ignore** the following: `utm_source utm_medium utm_campaign utm_term utm_content fbclid gclid gclsrc dclid msclkid mc_cid mc_eid _ga _gl igshid yclid twclid ttclid ref source`
     (or "Ignore all query strings except" `s p page_id preview` on Business+).

   **Rule 2 — "Bypass: logged-in / admin / dynamic"** (placed below Rule 1)
   - When: `(http.cookie contains "wordpress_logged_in_") or (http.cookie contains "wp-postpass_") or (http.cookie contains "comment_author_") or (starts_with(http.request.uri.path, "/wp-admin")) or (http.request.uri.path eq "/wp-login.php") or (http.request.uri.path eq "/xmlrpc.php") or (http.request.uri.path eq "/wp-cron.php") or (http.request.method ne "GET" and http.request.method ne "HEAD")`
   - Then: Cache eligibility **Bypass cache**.

   Rule 1 makes Cloudflare use the `s-maxage` our mu-plugin sends: 120 s for pages,
   10 s for search/REST, 300 s for feeds; static files get a year (`max-age=31536000,
   immutable` from nginx). Rule 2 guarantees editors never receive a cached page.
5. **Security → WAF**: enable Cloudflare Managed Ruleset; add a rate-limiting rule on
   `/wp-login.php` (e.g. 5 requests / minute / IP → block 10 min). Bot Fight Mode on.
6. **Speed → Optimization**: Brotli on, Early Hints on. Rocket Loader **off** (breaks themes).

Verify from your laptop after DNS propagates:

```bash
curl -sI https://SITE_DOMAIN/ | grep -iE 'cf-cache-status|x-fastcgi-cache|cache-control'
# 1st: cf-cache-status: MISS   x-fastcgi-cache: HIT     (Cloudflare missed, nginx hit)
# 2nd: cf-cache-status: HIT                              (served from the edge)
```

## Step 8 — Verify the publish → purge flow

1. Edit any article in wp-admin and save.
2. `docker compose logs --tail=20 php-admin` shows nothing unusual;
   `grep news-cache-warmer logs/nginx/access.log | tail` shows the warmer re-fetching the
   article, homepage, feed, category and author pages a second after the save.
3. `curl -sI https://SITE_DOMAIN/that-article/ | grep cf-cache-status` → `MISS` (edge purged),
   then `HIT` on the next request.

If Cloudflare purge fails, `docker compose logs php-admin | grep news-cache` shows the API
error (usually token permissions or wrong zone ID).

## Step 9 — Go-live checklist

- [ ] `scripts/cache-stats.sh` shows > 90 % HIT on HTML after a few hours of traffic
- [ ] `docker stats` — php container well below its 5 GB limit; mariadb below 11 GB
- [ ] `scripts/backup.sh` ran once manually; `backups/db/*.sql.zst` exists; optional `GCS_BUCKET` / `BACKUP_RCLONE_REMOTE` set
- [ ] Cloudflare Origin CA cert in place, SSL mode Full (strict)
- [ ] Old server kept read-only for a week as a fallback
- [ ] Uptime monitor pointed at `https://SITE_DOMAIN/-/health` (nginx-only, no PHP)

---

## Day-to-day operations

| Task | Command |
|------|---------|
| Status / logs | `docker compose ps` · `docker compose logs -f nginx php` |
| WP-CLI | `scripts/wp.sh plugin list` (or `make wp ARGS="plugin list"`) |
| Purge everything (nginx + Cloudflare) | `make purge` or the admin-bar button |
| Purge specific URLs | `scripts/wp.sh news-cache purge https://SITE_DOMAIN/some/url/` |
| Cache statistics | `make stats` |
| Reload nginx after config edits | `make reload-nginx` |
| Apply PHP / pool config edits | `docker compose restart php php-admin` |
| Update images (nginx, PHP, MariaDB minor, Redis) | `docker compose build --pull && docker compose up -d` |
| WordPress core / plugin updates | wp-admin as usual, or `scripts/wp.sh core update && scripts/wp.sh plugin update --all` |
| Backup now | `make backup` |
| GCS upload / download | `make gcs ARGS="check"` · `smoke` · `upload FILE` · `download PATH` |
| Restore DB | `zstd -dc backups/db/FILE.sql.zst \| docker compose exec -T mariadb sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"'` |

### Where the knobs are

| Want to change… | Edit |
|-----------------|------|
| Page TTL in nginx / Cloudflare | `wp/mu-plugins/cache-control.php` (`TTL_*` constants) |
| Which URLs are purged on publish | `urls_for_post()` in `wp/mu-plugins/cache-purge.php`, or hook `news_cache_purge_urls` |
| Cookie / path bypass rules | `map` blocks in `config/nginx/nginx.conf` |
| PHP workers | `config/php/pool-www.conf` (`pm.max_children`), memory limit in `.env` (`PHP_MEM`) |
| DB memory | `.env` → `DB_BUFFER_POOL`, `DB_MEM` |
| Redis size | `config/redis/redis.conf` `maxmemory` + `.env` `REDIS_MEM` |
| Upload size limit | `client_max_body_size` (nginx.conf) and `upload_max_filesize` (`config/php/conf.d/zz-wp.ini`) |

### Things that will bite you (and how we already handled them)

- **Docker bypasses ufw.** Handled via `DOCKER-USER` chain rules (Step 1). Don't rely on `ufw status`.
- **Cache purge by file deletion + `open_file_cache`** = nginx keeps serving deleted files.
  `open_file_cache` is enabled only in the static-assets location for this reason. Don't add it globally.
- **A plugin sets a cookie on anonymous pages** → nginx never caches them (`Set-Cookie` responses are
  not cached, by design). Symptom: `MISS` ratio climbs in `make stats`. Find the plugin, fix or remove it.
- **Query-string variants** (`?utm_source=…`) share the cache entry in nginx and, with the Cache Rule
  above, at Cloudflare too. Any *other* query string creates its own cache entry.
- **Plugin updates from wp-admin** run in the `php-admin` container; the `php` container picks up
  changed files within 60 s (`opcache.revalidate_freq = 60`). To force it: `docker compose restart php`.
- **`wp` from the CLI reports "PhpRedis: Not loaded"** — the CLI image lacks the extension, so it falls
  back to Predis; the web containers use PhpRedis + igbinary (verified in Settings → Redis).
- **Table prefix mismatch** → white screen / "install" page after import. `import-db.sh` refuses to run
  if the dump's prefix doesn't match `.env`.
