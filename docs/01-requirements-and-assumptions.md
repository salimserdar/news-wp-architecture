# 01 — Requirements & Assumptions

## Hard constraints

| Constraint | Value |
|------------|-------|
| Hosting | Single VPS (no horizontal scaling, no managed DB) |
| CPU | 8 vCPU |
| RAM | 32 GB |
| Disk | Assumed NVMe SSD (**to confirm** — size and IOPS matter for DB and cache) |
| CMS | WordPress (latest, PHP 8.3) |
| Edge | Cloudflare (plan tier **to confirm**: Free / Pro / Business) |

## What "high traffic" means here

A news site has a very specific traffic shape that drives every design decision:

1. **Read-heavy, ~99%+ anonymous.** Almost nobody is logged in except editors.
   → Full-page caching is extremely effective.
2. **Spiky.** A breaking story or a viral share can multiply traffic 10–50× within minutes.
   → The cache must absorb spikes; the origin must degrade gracefully (serve stale, not 502).
3. **Hot-set is small.** At any moment, a handful of articles + the homepage get most hits.
   → Even a small cache with short TTLs yields very high hit ratios.
4. **Freshness matters, but not to the second.** Editors expect a published/updated article
   to be visible within seconds, not milliseconds.
   → Purge-on-publish plus short TTLs (60–300 s) is acceptable.
5. **Constant publishing.** Dozens to hundreds of posts/updates per day.
   → Invalidation must be targeted (article + homepage + category), not "flush everything".

## Working targets (to validate with you)

| Metric | Target |
|--------|--------|
| Sustained | ~5–10 M pageviews / month (≈ 2–4 req/s average on HTML) |
| Peak | 2 000–5 000 concurrent readers, bursts of 500+ HTML req/s |
| Cache hit ratio (Cloudflare + Nginx combined) | > 95 % of HTML, > 99 % of static |
| Origin PHP renders | < 20 req/s under peak (everything else served from cache) |
| TTFB (cached, from edge) | < 100 ms |
| TTFB (cache miss, origin) | < 500 ms |
| Availability during traffic spike | Serve stale content rather than errors |

With this design, a single 8-core box can serve these numbers comfortably; the real
ceiling is how many **uncached** requests hit PHP, which we control via cache rules.

## Assumptions (please correct any that are wrong)

- Editors: a small team (< 50 concurrent users in wp-admin).
- Comments: either disabled, or acceptable to load via JavaScript after page render.
- No per-user personalisation on public pages (no "Hi, Salim" in the header).
- Ads / analytics are client-side JavaScript (they don't affect server caching).
- Media: images uploaded through WordPress; video embedded from third parties (YouTube etc.), not self-hosted.
- Search: native WordPress search is acceptable initially (can move to Elasticsearch/Meilisearch later if needed).
- Single language / single site (not WordPress Multisite).
- Newsletter, push notifications, paywall: **out of scope** unless you say otherwise.

## Non-goals

- Multi-server / high availability across machines (single VPS by definition).
- Zero-downtime failover of the database.
- Headless / decoupled front end.
