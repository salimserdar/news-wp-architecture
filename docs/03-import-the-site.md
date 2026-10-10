# 03 — Import the site

Copy the existing database dump and `wp-content` onto the VPS, then import them.
The stack from [02](02-install-and-configure.md) must already be installed.
Cloudflare comes after this, in [04](04-cloudflare-and-go-live.md).

## 1. Copy the backup onto the VPS

From the machine that holds the dump (replace `VPS_IP` and the local paths):

```bash
ssh root@VPS_IP 'mkdir -p /opt/news-wp/import'
scp dump.sql root@VPS_IP:/opt/news-wp/import/dump.sql
rsync -a --info=progress2 ./wp-content/ root@VPS_IP:/opt/news-wp/import/wp-content/
```

`import/` is git-ignored. Accepted dump names: `.sql`, `.sql.gz`, `.sql.zst`,
`.sql.bz2`. `wp-content` can be a directory or an archive
(`.tar.gz`, `.tgz`, `.tar.zst`, `.tar`, `.zip`) that contains a `wp-content/`
directory.

You need the old site's table prefix (already in `.env` as `DB_TABLE_PREFIX`)
and the old domain, if it is changing.

## 2. Import the database (5–30 min depending on size)

```bash
cd /opt/news-wp
scripts/import-db.sh import/dump.sql old-domain.com
```

What the script does:

1. Checks the dump's table prefix matches `DB_TABLE_PREFIX` in `.env`.
2. Drops any existing tables and streams the dump into MariaDB, fixing MySQL 8-only
   collations (`utf8mb4_0900_*`) and GTID statements on the fly.
3. Runs `wp search-replace` for `https://old-domain.com`, `http://old-domain.com`, and
   `//old-domain.com` → `https://SITE_DOMAIN` across all tables (serialized-data safe,
   GUIDs untouched).
4. Sets `home`/`siteurl`, flushes rewrites, runs `wp core update-db`, purges the cache.

If the old site and the new site use the **same domain**, omit the second argument.

## 3. Import wp-content (uploads, themes, plugins)

```bash
scripts/import-wp-content.sh import/wp-content
# uploads only:  scripts/import-wp-content.sh import/wp-content --uploads-only
```

Copies `uploads/`, `themes/`, `plugins/`, and `languages/` into
`/var/www/html/wp-content/` and fixes ownership (`www-data`). It deliberately
skips old caching drop-ins (`object-cache.php`, `advanced-cache.php`), old cache
directories, and `mu-plugins/` (ours are installed from `wp/mu-plugins/`).

`rsync` is resumable. Re-run the copy in step 1 if a large `uploads/` tree was
interrupted, then re-run the import. After import, ownership is `www-data`.

## 4. Post-import (2 min)

```bash
scripts/post-import.sh
```

Deactivates page-cache plugins that would fight nginx (WP Rocket, W3TC, WP Super
Cache, LiteSpeed, Nginx Helper, the Cloudflare plugin, Redis Object Cache, …),
removes stale drop-ins, flushes rewrites, lists cron events, purges and warms,
and does a smoke test. Expected last lines: `200 X-FastCGI-Cache=HIT`.

Then log in at `https://SITE_DOMAIN/wp-login.php` (once DNS is switched, or via
IP plus a hosts-file entry) and check:

- Settings → Permalinks: click Save once (harmless, ensures rewrite rules).
- Admin bar: a **Purge cache** button is present for editors and admins.

## Failures you will actually see

- **Table prefix mismatch** → white screen or the "install" page after import.
  `import-db.sh` refuses to run if the dump's prefix doesn't match `.env`.
- **`ERROR 1062 Duplicate entry '0' for key PRIMARY`** on `wp_actionscheduler_*`
  during import. phpMyAdmin dumps add indexes after INSERTs; those queue tables
  often have several `id=0` rows and abort the rest of the dump. `import-db.sh`
  skips Action Scheduler row data (tables are still created empty). Set
  `IMPORT_ACTION_SCHEDULER=1` to keep the queue.
- **Site returns 502 during `import-db.sh`.** The script stops PHP-FPM so
  `ALTER TABLE` can lock; nginx stays up. FPM is started again when the script
  exits (success or failure).
- **`ERROR 1146 Table 'wordpress.wp_posts' doesn't exist`** usually means a
  previous import was still running (or PHP was still querying) when this one
  dropped the database. The script refuses to start a second copy and kills
  other sessions on the database before it proceeds.
- **wp-admin is a white screen after login; login itself works.** PHP-FPM is
  usually busy (Yoast XML sitemaps plus a cold `wp_postmeta` after import).
  `post-import.sh` turns sitemaps off and deactivates the obsolete `rest-api`
  plugin (REST is in core). Re-enable sitemaps in SEO → General → Features once
  the page cache is warm.

Next: [04 — Cloudflare and go-live](04-cloudflare-and-go-live.md).
