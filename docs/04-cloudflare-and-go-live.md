# 04 — Cloudflare and go-live

Point the domain at the VPS from [03](03-import-the-site.md), turn on edge
caching, then lock the origin firewall. Day-to-day commands are in
[05 — Operations](05-operations.md).

## Cloudflare (10 min)

In the dashboard for the zone:

1. **DNS**: `A` record for `SITE_DOMAIN` (and the apex, if used) → VPS IP,
   **proxied** (orange cloud). Lower the old record's TTL a day ahead if you can.
2. **SSL/TLS → Overview**: *Full (strict)* (or *Full* if using the self-signed
   fallback). **Edge Certificates**: Always Use HTTPS on, HSTS on (after you have
   confirmed HTTPS works), Minimum TLS 1.2. Optional: **Origin Server →
   Authenticated Origin Pulls** on, then uncomment the two lines in
   `config/nginx/snippets/tls.conf` and `make reload-nginx`.
3. **Caching → Configuration**: Caching level Standard; **Tiered Cache** (Smart)
   on. **Browser Cache TTL**: *Respect Existing Headers*.
4. **Caching → Cache Rules** — create two rules. When several rules match, **the
   last matching rule wins** for each setting, so the bypass rule must come
   *after* the cache-everything rule:

   **Rule 1 — "Cache everything, honour origin TTL"**

   - When: `(http.host eq "SITE_DOMAIN")`
   - Then: Cache eligibility **Eligible for cache**; Edge TTL **Use cache-control
     header if present, bypass cache if not**; Browser TTL **Respect origin**;
     Cache key → Query string → **Ignore** the following:
     `utm_source utm_medium utm_campaign utm_term utm_content fbclid gclid gclsrc dclid msclkid mc_cid mc_eid _ga _gl igshid yclid twclid ttclid ref source`
     (or "Ignore all query strings except" `s p page_id preview` on Business+).

   **Rule 2 — "Bypass: logged-in / admin / dynamic"** (placed below Rule 1)

   - When: `(http.cookie contains "wordpress_logged_in_") or (http.cookie contains "wp-postpass_") or (http.cookie contains "comment_author_") or (starts_with(http.request.uri.path, "/wp-admin")) or (http.request.uri.path eq "/wp-login.php") or (http.request.uri.path eq "/xmlrpc.php") or (http.request.uri.path eq "/wp-cron.php") or (http.request.method ne "GET" and http.request.method ne "HEAD")`
   - Then: Cache eligibility **Bypass cache**.

   Rule 1 makes Cloudflare use the `s-maxage` our mu-plugin sends: 120 s for
   pages, 10 s for search/REST, 300 s for feeds; static files get a year
   (`max-age=31536000, immutable` from nginx). Rule 2 guarantees editors never
   receive a cached page.
5. **Security → WAF**: enable the Cloudflare Managed Ruleset; add a rate-limiting
   rule on `/wp-login.php` (for example 5 requests / minute / IP → block 10 min).
   Bot Fight Mode on.
6. **Speed → Optimization**: Brotli on, Early Hints on. Rocket Loader **off**
   (it breaks themes).

Verify from your laptop after DNS propagates:

```bash
curl -sI https://SITE_DOMAIN/ | grep -iE 'cf-cache-status|x-fastcgi-cache|cache-control'
# 1st: cf-cache-status: MISS   x-fastcgi-cache: HIT     (Cloudflare missed, nginx hit)
# 2nd: cf-cache-status: HIT                              (served from the edge)
```

Then lock the origin firewall to Cloudflare IP ranges:

```bash
WEB_OPEN=0 bash scripts/setup-vps.sh
```

## Verify publish → purge

1. Edit any article in wp-admin and save.
2. `grep news-cache-warmer /var/log/nginx/access.log | tail` shows the warmer
   re-fetching the article, homepage, feed, category, and author pages a second
   after the save.
3. `curl -sI https://SITE_DOMAIN/that-article/ | grep cf-cache-status` → `MISS`
   (edge purged), then `HIT` on the next request.

If Cloudflare purge fails, `grep news-cache /var/log/syslog` (or the PHP-FPM
syslog) shows the API error (usually token permissions or the wrong zone ID).

## Go-live checklist

- [ ] `scripts/cache-stats.sh` shows > 90 % HIT on HTML after a few hours of traffic
- [ ] `free -h` and `ps -ylC php-fpm8.3 --sort:rss` — FPM well below the RAM budget; MariaDB buffer pool fits
- [ ] `scripts/backup.sh` ran once manually; `backups/db/*.sql.zst` exists; `BACKUP_RCLONE_REMOTE` set if you want offsite copies ([05](05-operations.md))
- [ ] Cloudflare Origin CA certificate in place, SSL mode Full (strict), 80/443 locked to Cloudflare
- [ ] Old server kept read-only for a week as a fallback
- [ ] Uptime monitor pointed at `https://SITE_DOMAIN/-/health` (nginx-only, no PHP)

Next, when you want numbers: [06 — Load test](06-load-test.md). Day-to-day
commands: [05 — Operations](05-operations.md).
