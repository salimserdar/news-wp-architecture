# 05 — Alternatives & Decisions

Each item states the options, the recommendation, and whether it is **settled** or
**open for discussion**.

## Decision log

| Date | Decision |
|------|----------|
| 2026-09-11 | **Docker Compose in production** (Q5 → B). Fastest path; the whole stack is `docker compose up`. Overhead mitigated: nginx workers and PHP share uid 82 so the purge plugin can delete cache files directly, resource limits per container, host networking not needed. |
| 2026-09-11 | **Existing site is migrated**: DB dump imported with `scripts/import-db.sh` (domain rewrite included), `wp-content` copied with `scripts/import-wp-content.sh`. Old caching plugins are deactivated by `scripts/post-import.sh`. |
| 2026-09-11 | **Cloudflare via Cache Rules + custom mu-plugin purge** (Q1 → A). Edge TTL follows the origin `s-maxage`; purge-by-URL on publish. |
| 2026-09-11 | Media stays on **local disk** for now (Q6 → A); served under the site domain. R2 offload remains a later option. |
| 2026-09-11 | **MariaDB 11.4** (Q4). |
| 2026-09-11 | Nginx purge implemented by **deleting cache files** from PHP (same uid, shared volume) instead of compiling `ngx_cache_purge` — no custom nginx build needed. `open_file_cache` is therefore restricted to static assets. |

Still open: Q7 (image optimisation beyond core WebP), Q8 (comments), Q12 (staging).

---

## Q1 — Cloudflare HTML caching: Cache Rules vs APO vs static-only  · settled → **A (Cache Rules)**

| Option | Pros | Cons |
|--------|------|------|
| **A. Cache Rules ("cache everything" + cookie bypass) + custom purge plugin** — *recommended* | Full control over TTL/bypass; free on any plan; purge exactly the URLs we want in the same hook as the Nginx purge | We own the edge cases (cookie list, query strings, mobile variants) |
| B. Cloudflare APO | Turn-key; handles bypass + purge; Cloudflare-maintained | $5/mo on Free plan; opaque behaviour; occasional stale-content reports; less tunable |
| C. Static-only at edge, all HTML served by Nginx | Simplest; zero risk of edge-stale pages | Every HTML request travels to the origin — fine for capacity, worse for global TTFB and DDoS exposure |

Recommendation: **A**, with C as the fallback if edge caching causes editorial confusion.
Question for you: which Cloudflare plan are we on? (Business unlocks custom cache keys,
bypass-cache-on-cookie is available on all plans via Cache Rules.)

## Q2 — Origin page cache: Nginx FastCGI vs Varnish vs WP plugin (WP Rocket, W3TC...)  · settled → **Nginx FastCGI**

| Option | Verdict |
|--------|---------|
| **Nginx FastCGI cache** | Zero extra processes, serves cache hits without touching PHP at all, mature stale/lock semantics, one config file. |
| Varnish | Excellent, but adds a hop and a process, needs TLS termination in front, and ESI is not needed when dynamic fragments are JS-loaded. Justified only for very complex fragment caching. |
| Plugin-based page cache | Still boots PHP + WordPress on every request (except with `advanced-cache.php` tricks). 10–50× slower than Nginx serving a file. Fine for small sites, wrong tool for spikes. |

## Q3 — Object cache: Redis vs Memcached vs none  · settled → **Redis**

Redis supports the data structures WordPress's cache API needs (`wp_cache_get_multiple`,
`flush_group`), has a first-class plugin, and can double as a rate-limit / queue store later.
Memcached is fine but has no advantage here. Valkey (Redis fork) is a drop-in if licensing
becomes a concern.

## Q4 — Database: MariaDB vs MySQL vs Percona  · settled → **MariaDB 11.4**

| Option | Notes |
|--------|-------|
| **MariaDB 11.4 LTS** — *recommended* | Ubuntu-native, WordPress officially supports it, slightly faster on WP workloads, simple. |
| MySQL 8.4 LTS | Also fine. Marginally better JSON functions (irrelevant for WP). |
| Percona Server | Best tooling (`xtrabackup`), overkill for a 5 GB DB. |

Pick MariaDB unless you have an existing preference or migration from MySQL 8 with
incompatible features (unlikely for WordPress).

## Q5 — Deployment model: native packages vs Docker Compose  · settled → **Docker Compose**

| Option | Pros | Cons |
|--------|------|------|
| A. Native packages (apt) + shell/Ansible provisioning | Best raw performance; unix sockets everywhere | Reproducibility depends on provisioning scripts; slower to get right |
| **B. Docker Compose** — *chosen* | Reproducible; one command to start; easy version bumps; identical stack locally and on the VPS | Bridge-network hop between nginx↔PHP↔DB (~1–3 % on cache misses, irrelevant for cache hits); resource limits must be set per container |

Chosen for speed of implementation. Mitigations built in: two FPM containers with
per-container CPU/memory limits (`.env`), nginx cache on a named volume shared with PHP,
log rotation via the json-file driver, `DOCKER-USER` firewall rules because Docker
bypasses ufw. See doc 08.

## Q6 — Media storage: local disk vs Cloudflare R2  · settled → **A (local disk), R2 later**

| Option | Notes |
|--------|-------|
| **A. Local uploads + Cloudflare caching** | Simplest. Cloudflare caches images at the edge so origin bandwidth is tiny. Disk grows forever; backups grow with it. |
| **B. Offload to Cloudflare R2** (S3-compatible, no egress fees) via a media-offload plugin | Disk stays small; backups faster; effectively unlimited media. Adds a plugin dependency and some setup. |

Recommendation: start with **A**, design the upload path so **B** can be switched on later
without URL changes (serve media from a `media.` or `cdn.` subdomain from day one).

## Q7 — Image optimisation: server-side vs Cloudflare  · **OPEN**

- Server-side: generate WebP/AVIF on upload (WordPress 6.x does WebP natively; AVIF via
  ImageMagick 7). Costs CPU on upload only.
- Cloudflare Polish (Pro+) or Image Resizing (paid): zero server CPU, automatic format negotiation.
- Recommendation: server-side WebP (free, built in) now; Polish if you're already on Pro.

## Q8 — Comments  · **OPEN**

Native comments are the number-one way news sites accidentally bypass their cache
(`comment_author_*` cookies) and get bot-flooded. Options: disabled / native but JS-loaded
/ third-party (Disqus, Hyvor). Need your product decision.

## Q9 — Search  · settled for now → **native, microcached**

Native WP search with a 10 s Nginx microcache. Revisit with Meilisearch if search volume or
relevance becomes a problem.

## Q10 — WP-Cron  · settled → **system cron**

`DISABLE_WP_CRON` in `wp-config.php`; `* * * * * wp cron event run --due-now` via WP-CLI.
Prevents random readers from triggering slow cron work in their request.

## Q11 — TLS between Cloudflare and origin  · settled → **Full (strict) with Cloudflare Origin CA cert**

15-year origin certificate, no Let's Encrypt renewals to babysit. Authenticated Origin Pulls
(mTLS) as an extra: only Cloudflare can complete a TLS handshake to the origin.

## Q12 — Staging environment  · **OPEN**

Same VPS (separate vhost, DB, PHP pool, Redis DB index — cheap, but shares resources) vs a
separate small VPS (clean, costs money). Recommendation: same VPS, resource-limited via
systemd, behind Cloudflare Access.

---

## Still to decide (not blocking go-live)

1. **Q7** — Image optimisation: core WebP is enabled in `perf-tweaks.php`; add Cloudflare Polish if on Pro.
2. **Q8** — Comments: off, native (JS-loaded), or third-party? Native comments set a
   `comment_author_*` cookie which bypasses both caches for that reader.
3. **Q12** — Staging: a second compose project on the same VPS (different ports, behind
   Cloudflare Access) is the cheap option.
4. Confirm assumptions in doc 01 (traffic targets, disk size, editor count).
