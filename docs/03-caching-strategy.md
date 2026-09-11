# 03 — Caching Strategy

Five cache layers, from the reader inward. Each one exists to shield the next.

| Layer | Where | What it caches | TTL | Hit target |
|-------|-------|----------------|-----|------------|
| 1. Browser | Reader's device | Static assets | 1 year (fingerprinted URLs) | — |
| 2. Cloudflare edge | ~300 PoPs | Static assets, HTML for anonymous users | Static: 1 year · HTML: 60–300 s | > 80 % HTML, > 99 % static |
| 3. Nginx FastCGI | Origin | Full HTML pages for anonymous users | 5–15 min + serve-stale | > 95 % of what reaches origin |
| 4. Redis object cache | Origin | WP queries, options, transients, post objects | Until invalidated by WP | > 90 % |
| 5. OPcache | PHP-FPM | Compiled PHP bytecode | Until deploy | ~100 % |

Combined effect: for every 1 000 HTML requests during a spike, roughly **≤ 5 reach PHP**.

---

## Layer 2 — Cloudflare edge

### Static assets
- Default Cloudflare behaviour already caches CSS/JS/images/fonts.
- Cache Rule: `/wp-content/*` and `/wp-includes/*` → **Edge TTL 1 year**, ignore query string
  except `ver`. WordPress appends `?ver=` to enqueued assets, so it self-busts on updates.
- Browser TTL: 1 year via `Cache-Control: public, max-age=31536000, immutable` set by Nginx.

### HTML (the important decision — see doc 05, Q1)

Recommended: **Cache Everything with cookie bypass + purge-on-publish.**

Cache Rule (in order):
1. **Bypass** if any of:
   - Cookie contains `wordpress_logged_in_`, `wp-postpass_`, `comment_author_`, `woocommerce_*`
   - URI path starts with `/wp-admin`, `/wp-login.php`, `/wp-json`, `/xmlrpc.php`, `/wp-cron.php`
   - Request method is not GET/HEAD
   - Query string is present (except tracking params — strip `utm_*`, `fbclid`, `gclid` via
     "Ignore query string" list so they don't fragment the cache)
2. **Cache everything else**: Edge TTL **120 s**, Browser TTL **0** (`no-cache` to the browser so
   readers always revalidate with the edge — a stale page in the browser is worse than a
   short round-trip to Cloudflare).

Tiered Cache (Smart Tiered Caching) should be **on** — it makes edge PoPs fetch from one
upper-tier PoP instead of all hitting the origin at once.

Alternative: **Cloudflare APO** (Automatic Platform Optimization for WordPress). It does the
above automatically and handles purge through the official Cloudflare plugin. Costs $5/mo on
Free, included on Pro+. Less control, less setup. Either is fine; see doc 05.

### Purge
On `publish_post`, `edit_post`, `delete_post`, `transition_comment_status`:
- Purge by URL (fast, precise): the post URL, homepage, feed, the post's category/tag/author
  archives, and the AMP/`/page/2/` variants if used.
- Purge-everything is reserved for theme deploys and emergencies (it will cause a spike of
  origin misses — the Nginx layer absorbs that).

Implemented via the official **Cloudflare WordPress plugin** (has "auto purge on update") or a
small custom plugin calling the `purge_cache` API with `files: [...]`. Recommendation: custom
20-line plugin so it purges exactly the URLs we want and can also purge the Nginx cache in the
same hook.

---

## Layer 3 — Nginx FastCGI full-page cache

This is the layer that makes the origin survive Cloudflare misses and purge storms.

### Storage
```
fastcgi_cache_path /var/cache/nginx/wp
    levels=1:2
    keys_zone=WORDPRESS:100m      # ~800k keys
    max_size=4g
    inactive=24h
    use_temp_path=off;
```
- On NVMe disk, not tmpfs: the OS page cache keeps hot files in RAM anyway, and a disk-backed
  cache survives Nginx restarts (tmpfs is an option if disk IOPS turn out poor).
- `inactive=24h` so rarely-read old articles remain servable as stale fallback.

### Cache key
```
fastcgi_cache_key "$scheme$request_method$host$request_uri";
```
Mobile/desktop use the same responsive HTML, so **no** device-based key variation
(if the theme serves different HTML per device, we add a `$mobile` variable — avoid this).

### Bypass rules (`$skip_cache = 1` when any match)
- Method not GET/HEAD
- Cookies: `wordpress_logged_in_*`, `wp-postpass_*`, `comment_author_*`, `wordpress_no_cache`
- URI: `/wp-admin/`, `/wp-login.php`, `/wp-json/`, `/xmlrpc.php`, `/wp-cron.php`, `/feed/`
  (feeds get their own short cache), `sitemap*.xml` (short cache), `/preview=true`, `?p=`
- Query string present — **except** tracking params, which Nginx strips before building the
  key (`utm_*`, `fbclid`, `gclid`, `mc_cid`, etc.) so `?utm_source=twitter` shares the cache
  with the clean URL.

### TTLs
```
fastcgi_cache_valid 200 301 302  10m;
fastcgi_cache_valid 404          1m;
fastcgi_cache_valid any          0;
```
Homepage and category pages effectively refresh via purge, not TTL. TTL is just the safety net.

### Resilience directives (the part most setups skip)
```
fastcgi_cache_lock on;                 # one render per key at a time
fastcgi_cache_lock_timeout 5s;
fastcgi_cache_use_stale error timeout invalid_header updating
                        http_500 http_503 http_429;   # fastcgi has no http_502/504
fastcgi_cache_background_update on;    # stale-while-revalidate
fastcgi_cache_min_uses 1;
fastcgi_ignore_headers Cache-Control Expires Set-Cookie;
```
`use_stale ... updating` + `background_update` means a reader never waits for PHP if *any*
copy exists. `use_stale error/5xx` means a PHP or DB outage does not take the public site down.

### Response headers
- `X-FastCGI-Cache: HIT|MISS|BYPASS|STALE|UPDATING` for debugging and monitoring.
- Strip `Set-Cookie` from cacheable responses (WordPress sometimes sets test cookies).

### Purge
Nginx open-source has no purge module built in. Options:
1. `ngx_cache_purge` module (requires a custom nginx build) + Nginx Helper plugin.
2. **Compute the MD5 of the cache key and delete the file** — *implemented* in
   `wp/mu-plugins/cache-purge.php`. Zero dependencies; works because nginx workers and
   PHP-FPM run as the same uid on a shared volume, and because `open_file_cache` is
   disabled for cached responses (otherwise nginx keeps serving a deleted file).
3. Short TTL only (no purge) — editors wait up to 10 min. Not acceptable for news.

Purge fan-out on publish mirrors the Cloudflare list: article URL, homepage, category, tag,
author, day archive, feed. Order: **purge Nginx first, then Cloudflare, then warm** so an
edge miss re-fills from fresh origin content. Verified locally: article updated → nginx file
gone → warmer re-renders → next reader gets `HIT` with the new title.

### Microcaching for "uncacheable" pages
Search results (`/?s=`) and paginated archives get a **1 s – 10 s** cache. Even 1 second
collapses a burst of 500 identical requests into 1 PHP render.

---

## Layer 4 — Redis object cache

- Plugin: **Redis Object Cache** (free, by Till Krüss) with the `object-cache.php` drop-in.
  Object Cache Pro (paid) if we want relay/async and better analytics — optional.
- Connection over the private compose network (`redis:6379`); the port is not published on the host.
- `maxmemory 2gb`, `maxmemory-policy allkeys-lru`, persistence **off** (`save ""`,
  `appendonly no`) — it's a cache; cold start just means a few seconds of DB load.
- Use `igbinary` serializer + `zstd`/`lz4` compression (phpredis) to fit more in memory.
- Separate Redis **database index** or key prefix per environment (staging vs production).

What it saves: WordPress does ~50–200 SQL queries per uncached page (options, term
relationships, post meta, menus). With Redis, ~90 % of those never reach MariaDB, so
cache-miss renders drop from ~300–600 ms to ~80–150 ms.

Also used for: transients (`set_transient` → Redis instead of `wp_options`), which matters on
news sites where plugins store "trending posts" data every few minutes.

---

## Layer 5 — OPcache (+ JIT)

```
opcache.enable=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=32
opcache.max_accelerated_files=50000
opcache.validate_timestamps=0     # production: reset on deploy (opcache_reset / FPM reload)
opcache.jit=tracing
opcache.jit_buffer_size=128M
```
`validate_timestamps=0` means PHP never stat()s files — a measurable win under load. The
deploy script reloads PHP-FPM to pick up code changes.

---

## Handling dynamic elements without breaking the page cache

| Element | Approach |
|---------|----------|
| "Most read" / trending list | Rendered into the page (updated on purge every few minutes). It doesn't need to be per-request. |
| Comment count / comments | Load via JS from a REST endpoint with its own 30 s microcache, or disable comments. |
| Live blog / breaking ticker | JS polls a small JSON endpoint (`/wp-json/site/v1/ticker`) microcached for 5–10 s. |
| Weather, currency, sports scores | Same pattern: JSON endpoint, short microcache, JS renders. |
| Ads, analytics, A/B tests | Client-side only; never vary server HTML per user. |
| Logged-in admin bar | Editors bypass cache entirely, so they see it; readers never do. |
| Nonces in public HTML | Avoid. A cached nonce expires after 12–24 h and breaks forms. Fetch nonces via REST if needed. |

---

## Cache warming

After a purge, the first reader pays for the render. For the homepage and top sections we
don't want that reader to be a real person:
- The purge hook fires an async `curl` (or `wp` cron event) to re-fetch the homepage and the
  purged URLs a second later, re-filling Nginx and Cloudflare.
- A cron job every 5 min warms the top ~50 URLs from the sitemap / analytics.

---

## Observability of the cache

Track daily (see doc 07):
- Cloudflare: cache hit ratio for HTML content type, origin requests/s, bandwidth saved.
- Nginx: count of `X-FastCGI-Cache` values from the access log (`HIT/MISS/BYPASS/STALE`).
  A rising BYPASS ratio usually means a plugin started setting cookies on anonymous users.
- Redis: `INFO stats` → `keyspace_hits / (hits+misses)`, evicted keys (increase memory if > 0).
- MariaDB: queries/s vs PHP renders/s — should be low and flat during traffic spikes.
