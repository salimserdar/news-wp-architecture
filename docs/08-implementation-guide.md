# 08 — Implementation Guide (step by step)

Everything in this repo is ready to run. This guide takes a fresh Ubuntu 24.04 VPS to a
live, cached WordPress site with **your existing database and `wp-content`** in roughly
an hour, most of it waiting for transfers.

Create the VM first: **[doc 00](00-create-gce-vm.md)** (`scripts/create-gce-vm.sh` grants
`gs://tr724-backup` **before** the instance exists).

```
Cloudflare ──> [VPS: native packages]
                 nginx ──(cache miss / logged-in)──> PHP-FPM ──> MariaDB
                 system cron (WP-CLI every 60 s)
```

## Contents

1. [What you need](#what-you-need-before-starting)
2. [Step 1 — Prepare the VPS](#step-1--prepare-the-vps-1020-min)
3. [Step 2 — Configure](#step-2--configure-5-min)
4. [Step 3 — Confirm bucket access](#step-3--confirm-bucket-access)
5. [Step 4 — Pull the backup from GCS](#step-4--pull-the-backup-from-gcs)
6. [Step 5 — Import the database](#step-5--import-your-database-530-min-depending-on-size)
7. [Step 6 — Import wp-content](#step-6--import-wp-content-uploads-themes-plugins)
8. [Step 7 — Post-import](#step-7--post-import-2-min)
9. [Step 8 — Cloudflare](#step-8--cloudflare-10-min)
10. [Step 9 — Verify publish → purge](#step-9--verify-the-publish--purge-flow)
11. [Step 10 — Go-live checklist](#step-10--go-live-checklist)
12. [Day-to-day operations](#day-to-day-operations)

## What you need before starting

| Item | Where it comes from |
|------|--------------------|
| VPS root SSH access, Ubuntu 24.04 LTS (GCE) | [doc 00](00-create-gce-vm.md) — `scripts/create-gce-vm.sh` |
| Your domain on Cloudflare (orange-clouded later) | Cloudflare dashboard |
| Cloudflare Origin CA certificate + key | SSL/TLS → Origin Server → Create Certificate |
| Cloudflare API token, permission **Zone → Cache Purge → Purge**, scoped to the zone; and the Zone ID | My Profile → API Tokens; Zone overview (right column) |
| Site backup in Cloud Storage | `gs://tr724-backup/db/wp_tr724.sql` and `gs://tr724-backup/wp-content/` |
| The old site's table prefix and domain | old `wp-config.php` (`$table_prefix`) |

## Repository layout

```
.env.example               copy to .env
config/
  nginx/                   http.conf, site.conf, snippets/ (cache, tls, hardening, realip)
  php/                     pool-www.conf, conf.d/zz-wp.ini
  mariadb/zz-tuning.cnf    InnoDB tuning
wp/mu-plugins/             cache-control.php (TTL headers), cache-purge.php (nginx+Cloudflare purge,
                           warmer, admin-bar button, WP-CLI), perf-tweaks.php
scripts/                   create-gce-vm.sh, setup-vps.sh, gcs.sh, pull-gcs-backup.sh,
                           import-db.sh, import-wp-content.sh, post-import.sh, wp.sh, backup.sh
import/                    (git-ignored) drop DB dumps / archives here
backups/  logs/            (git-ignored)
```

WordPress itself lives at `/var/www/html` on the VPS (not in this repo).

---

## Step 1 — Prepare the VPS (10–20 min)

If you do not have a VM yet, create it with bucket access already granted:

```bash
# laptop
scripts/create-gce-vm.sh
gcloud compute ssh news-wp --zone=YOUR_ZONE
```

Then on the VM:

```bash
sudo apt-get update && sudo apt-get install -y git
git clone <this-repo> /opt/news-wp && cd /opt/news-wp
cp .env.example .env && nano .env     # SITE_DOMAIN, DB_*, CF_* (see Step 2)
SSH_PORT=22 bash scripts/setup-vps.sh # change SSH_PORT if you use a custom port
```

The script installs nginx, PHP 8.3-FPM, MariaDB and WP-CLI; tunes the kernel; adds 2 GB
swap; enables `fail2ban`; writes nginx FastCGI cache + PHP pool configs; unpacks WordPress
into `/var/www/html`; and opens 80/443 to the world (`WEB_OPEN=1`) so you can test before
Cloudflare DNS is live.

Lock 80/443 to Cloudflare after cut-over:

```bash
WEB_OPEN=0 bash scripts/setup-vps.sh
```

Until then, reach the origin by IP (expect a cert warning) or add a hosts-file entry.

## Step 2 — Configure (5 min)

Fill in `.env`: `SITE_DOMAIN` (e.g. `www.example.com`), a strong `DB_PASSWORD`,
`DB_TABLE_PREFIX` **exactly as in your old `wp-config.php`**, `CF_ZONE_ID`, `CF_API_TOKEN`.
Leave `PHP_MAX_CHILDREN` and `DB_BUFFER_POOL` at defaults unless the box is smaller
(see doc 04). `GCS_BUCKET=tr724-backup` is already the default.

Put the Cloudflare Origin CA certificate in `config/nginx/certs/origin.pem` and the key in
`config/nginx/certs/origin.key` (see `config/nginx/certs/README.md`), then re-run
`scripts/setup-vps.sh` (or copy them to `/etc/nginx/certs/` and `nginx -s reload`).
If you skip this for now, nginx uses a self-signed pair and Cloudflare must run in "Full"
mode instead of "Full (strict)".

**Do not copy your old `wp-config.php`.** The setup script writes one with the right
constants (URLs, cache purge, salts).

Quick check from the VPS itself:

```bash
curl -sk -o /dev/null -w "%{http_code} cache=%header{x-fastcgi-cache}\n" \
  -H "Host: $SITE_DOMAIN" https://127.0.0.1/
```
You'll get a 302/200 to the WordPress installer at this point — expected; the DB is empty.
A second curl of a renderable page should show `HIT`.

## Step 3 — Confirm bucket access

If you used [doc 00](00-create-gce-vm.md) / `scripts/create-gce-vm.sh`, IAM is already
on the service account. On the VPS:

```bash
scripts/gcs.sh check
# expect: ok — list succeeded.
```

Skip `grant-vm`. Only run it for a VM that was created without `cloud-platform` scopes
([doc 00 §7](00-create-gce-vm.md#7-existing-vm)).

If `check` returns 403, from a laptop:

```bash
scripts/gcs.sh grant-sa
# or, VM already exists:
scripts/gcs.sh grant-vm VM_NAME ZONE
```

## Step 4 — Pull the backup from GCS

```bash
# on the VPS
scripts/pull-gcs-backup.sh --check     # lists the dump and a sample of wp-content
scripts/pull-gcs-backup.sh             # DB + wp-content -> ./import/
# or:  make pull-gcs
```

Writes `import/wp_tr724.sql` and `import/wp-content/`. Re-run is safe (`gcloud storage rsync`
only copies what changed). Pull does **not** import into MariaDB or `/var/www/html`.

## Step 5 — Import your database (5–30 min depending on size)

```bash
cd /opt/news-wp
scripts/import-db.sh import/wp_tr724.sql old-domain.com
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

## Step 6 — Import wp-content (uploads, themes, plugins)

```bash
scripts/import-wp-content.sh import/wp-content
# uploads only:  scripts/import-wp-content.sh import/wp-content --uploads-only
```

Copies `uploads/`, `themes/`, `plugins/`, `languages/` into `/var/www/html/wp-content/`
and fixes ownership (`www-data`). It deliberately skips old caching drop-ins
(`object-cache.php`, `advanced-cache.php`), old cache directories and `mu-plugins/`
(ours are installed from `wp/mu-plugins/`).

For a very large `uploads/` tree, `pull-gcs-backup.sh` already used `gcloud storage rsync`
(resumable). After import, ownership is `www-data`.

## Step 7 — Post-import (2 min)

```bash
scripts/post-import.sh
```

Deactivates page-cache plugins that would fight nginx (WP Rocket, W3TC, WP Super Cache,
LiteSpeed, Nginx Helper, the Cloudflare plugin, Redis Object Cache, …), removes stale
drop-ins, flushes rewrites, lists cron events, purges + warms, and does a smoke test.
Expected last lines: `200 X-FastCGI-Cache=HIT`.

Then log in at `https://SITE_DOMAIN/wp-login.php` (once DNS is switched, or via IP + a
hosts-file entry) and check:
- Settings → Permalinks: click Save once (harmless, ensures rewrite rules).
- Admin bar: a **Purge cache** button is present for editors and admins.

## Step 8 — Cloudflare (10 min)

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

Then lock the origin firewall:

```bash
WEB_OPEN=0 bash scripts/setup-vps.sh
```

## Step 9 — Verify the publish → purge flow

1. Edit any article in wp-admin and save.
2. `grep news-cache-warmer /var/log/nginx/access.log | tail` shows the warmer re-fetching the
   article, homepage, feed, category and author pages a second after the save.
3. `curl -sI https://SITE_DOMAIN/that-article/ | grep cf-cache-status` → `MISS` (edge purged),
   then `HIT` on the next request.

If Cloudflare purge fails, `grep news-cache /var/log/syslog` (or PHP-FPM syslog) shows the
API error (usually token permissions or wrong zone ID).

## Step 10 — Go-live checklist

- [ ] `scripts/cache-stats.sh` shows > 90 % HIT on HTML after a few hours of traffic
- [ ] `free -h` / `ps -ylC php-fpm8.3 --sort:rss` — FPM well below RAM budget; MariaDB buffer pool fits
- [ ] `scripts/backup.sh` ran once manually; `backups/db/*.sql.zst` exists; optional `GCS_BUCKET` / `BACKUP_RCLONE_REMOTE` set
- [ ] Cloudflare Origin CA cert in place, SSL mode Full (strict), 80/443 locked to Cloudflare
- [ ] Old server kept read-only for a week as a fallback
- [ ] Uptime monitor pointed at `https://SITE_DOMAIN/-/health` (nginx-only, no PHP)

---

## Day-to-day operations

| Task | Command |
|------|---------|
| Status / logs | `systemctl status nginx php8.3-fpm mariadb` · `journalctl -u nginx -u php8.3-fpm -f` · `tail -f /var/log/nginx/access.log` |
| WP-CLI | `scripts/wp.sh plugin list` (or `make wp ARGS="plugin list"`) |
| Purge everything (nginx + Cloudflare) | `make purge` or the admin-bar button |
| Purge specific URLs | `scripts/wp.sh news-cache purge https://SITE_DOMAIN/some/url/` |
| Cache statistics | `make stats` |
| Origin load test (k6 runbook) | [doc 09](09-load-test-results.md) · `make loadtest-urls` · `make loadtest-observe` |
| Reload nginx after config edits | `make reload-nginx` (as root) |
| Apply PHP / pool config edits | re-run `scripts/setup-vps.sh` or `systemctl reload php8.3-fpm` |
| WordPress core / plugin updates | wp-admin as usual, or `scripts/wp.sh core update && scripts/wp.sh plugin update --all` |
| Backup now | `make backup` |
| GCS upload / download | `make gcs ARGS="check"` · `smoke` · `upload FILE` · `download PATH` |
| Pull site backup from GCS | `make pull-gcs` · `scripts/pull-gcs-backup.sh --check` |
| Grant VM access to the bucket | from a laptop: `scripts/gcs.sh grant-vm VM_NAME ZONE` |
| Restore DB | `zstd -dc backups/db/FILE.sql.zst \| mysql "$DB_NAME"` |

### Where the knobs are

| Want to change… | Edit |
|-----------------|------|
| Page TTL in nginx / Cloudflare | `wp/mu-plugins/cache-control.php` (`TTL_*` constants) |
| Which URLs are purged on publish | `urls_for_post()` in `wp/mu-plugins/cache-purge.php`, or hook `news_cache_purge_urls` |
| Cookie / path bypass rules | `map` blocks in `config/nginx/http.conf` |
| PHP workers | `config/php/pool-www.conf` (`pm.max_children`) or `.env` `PHP_MAX_CHILDREN` |
| DB memory | `.env` → `DB_BUFFER_POOL` |
| Upload size limit | `client_max_body_size` (`config/nginx/http.conf`) and `upload_max_filesize` (`config/php/conf.d/zz-wp.ini`) |

### Things that will bite you

- **Cache purge by file deletion + `open_file_cache`** = nginx keeps serving deleted files.
  `open_file_cache` is enabled only in the static-assets location for this reason. Don't add it globally.
- **A plugin sets a cookie on anonymous pages** → nginx never caches them (`Set-Cookie` responses are
  not cached, by design). Symptom: `MISS` ratio climbs in `make stats`. Find the plugin, fix or remove it.
- **Query-string variants** (`?utm_source=…`) share the cache entry in nginx and, with the Cache Rule
  above, at Cloudflare too. Any *other* query string creates its own cache entry.
- **Plugin updates from wp-admin** are picked up within 60 s (`opcache.revalidate_freq = 60`).
  To force it: `systemctl reload php8.3-fpm`.
- **Newspaper / tagDiv + PHP JIT** hangs `wp-admin/load-styles.php`. JIT is disabled in the pool config.
- **Table prefix mismatch** → white screen / "install" page after import. `import-db.sh` refuses to run
  if the dump's prefix doesn't match `.env`.
- **`ERROR 1062 Duplicate entry '0' for key PRIMARY`** on `wp_actionscheduler_*` during import.
  phpMyAdmin dumps add indexes after INSERTs; those queue tables often have several `id=0`
  rows and abort the rest of the dump. `import-db.sh` skips Action Scheduler row data
  (tables are still created empty). Set `IMPORT_ACTION_SCHEDULER=1` to keep the queue.
- **Loopback to the public hostname.** Setup adds `127.0.0.1 SITE_DOMAIN` to `/etc/hosts` so wp-cron
  and Site Health do not hairpin out through Cloudflare.
