# 06 — Implementation Roadmap

Docker Compose was chosen (doc 05), so phases 1–5 collapsed into the repo contents
plus the procedure in **doc 08**. This page tracks what exists and what remains.

## Done (in this repo)

| Phase | Deliverable | Where |
|-------|-------------|-------|
| Base server | Docker install, sysctl, swap, fail2ban, Cloudflare-only firewall (incl. `DOCKER-USER` chain), weekly IP refresh, nightly backup cron | `scripts/setup-vps.sh`, `scripts/cloudflare-ips.sh` |
| Data layer | MariaDB 11.4 tuned (buffer pool via `.env`), Redis 7 LRU cache | `config/mariadb/`, `config/redis/`, `docker-compose.yml` |
| PHP + WordPress | PHP 8.3-FPM image with igbinary/redis/imagick, two pools (public static 48, admin dynamic 12), OPcache/JIT, env-driven `wp-config.php`, system-cron replacement | `config/php/`, `docker-compose.yml` (`php`, `php-admin`, `cron`) |
| nginx + FastCGI cache | Cache zone, key with tracking-param stripping, cookie/path/method bypass maps, lock + stale-while-revalidate + stale-on-error, pool routing, static immutable headers, hardening, rate limits, Cloudflare real IP, TLS with self-signed fallback | `config/nginx/` |
| Cache control & purge | Per-page-type TTLs via `X-Accel-Expires`/`s-maxage`; purge-on-publish (nginx files + Cloudflare API), warmer, admin-bar button, `wp news-cache` CLI | `wp/mu-plugins/` |
| Migration tooling | DB import with MySQL-8 → MariaDB fixes and domain rewrite; wp-content import; post-import cleanup (deactivate old cache plugins, enable Redis Object Cache) | `scripts/import-db.sh`, `scripts/import-wp-content.sh`, `scripts/post-import.sh` |
| Ops tooling | backup (DB zstd + uploads rsync + optional rclone offsite), cache statistics, WP-CLI wrapper, Makefile | `scripts/`, `Makefile` |
| Local verification | Full stack booted; HIT/MISS/BYPASS matrix, purge → warm → fresh HIT, pool routing, PhpRedis + igbinary confirmed | see doc 08 "Things that will bite you" for the two bugs found and fixed |

## Next (on the VPS) — follow doc 08

- [ ] Step 1–3: prepare VPS, `.env`, Origin CA cert, `docker compose up -d --build`
- [ ] Step 4–6: import DB (with old-domain rewrite), import wp-content, `scripts/post-import.sh`
- [ ] Step 7: Cloudflare DNS, SSL Full (strict), Cache Rules, WAF, rate limit
- [ ] Step 8: verify publish → purge → edge MISS → HIT
- [ ] Step 9: go-live checklist

## After go-live

### Phase 7 — Load test & tune (½ day)
- [ ] `k6`/`wrk` from another machine: (a) cache-hit storm on 20 URLs, (b) `make purge` then storm,
      (c) editors saving posts during the storm
- [ ] Watch `make stats`, `docker stats`, `docker compose logs php | grep slow`
- [ ] Tune `pm.max_children`, `DB_BUFFER_POOL`, TTLs from evidence; record results in `docs/09-load-test-results.md`

### Phase 8 — Observability (½ day)
- [ ] Netdata (host install, sees Docker containers) or Prometheus exporters + Grafana Cloud
- [ ] Alerts per doc 07 (BYPASS ratio, FPM saturation, 5xx, disk, OOM)
- [ ] External uptime check on `/-/health`

### Later options
- Media offload to Cloudflare R2 (doc 05 Q6) once `uploads/` grows past ~50 GB
- Staging compose project (Q12)
- Authenticated Origin Pulls (two lines in `config/nginx/snippets/tls.conf`)
- Meilisearch if native search becomes a hotspot
