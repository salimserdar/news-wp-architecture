# 06 — Implementation Roadmap

Native packages were chosen (doc 05), so phases 1–5 collapsed into the repo contents
plus the procedure in **doc 08**. This page tracks what exists and what remains.

## Done (in this repo)

| Phase | Deliverable | Where |
|-------|-------------|-------|
| Base server | sysctl, swap, fail2ban, Cloudflare-aware firewall, weekly IP refresh, nightly backup cron | `scripts/setup-vps.sh`, `scripts/cloudflare-ips.sh` |
| Data layer | MariaDB tuned (buffer pool via `.env` / setup script) | `config/mariadb/` |
| PHP + WordPress | PHP 8.3-FPM (one pool), OPcache, wp-config constants, system-cron replacement | `config/php/`, `scripts/setup-vps.sh` |
| nginx + FastCGI cache | Cache zone, key with tracking-param stripping, cookie/path/method bypass maps, lock + stale-while-revalidate + stale-on-error, static immutable headers, hardening, rate limits, Cloudflare real IP, TLS with self-signed fallback | `config/nginx/` |
| Cache control & purge | Per-page-type TTLs via `X-Accel-Expires`/`s-maxage`; purge-on-publish (nginx files + Cloudflare API), warmer, admin-bar button, `wp news-cache` CLI | `wp/mu-plugins/` |
| Migration tooling | GCS pull of `gs://tr724-backup`; DB import with MySQL-8 → MariaDB fixes and domain rewrite; wp-content import; post-import cleanup | `scripts/pull-gcs-backup.sh`, `scripts/import-db.sh`, `scripts/import-wp-content.sh`, `scripts/post-import.sh` |
| Ops tooling | backup (DB zstd + uploads rsync + GCS `gcloud storage` / rclone / gcsfuse), cache statistics, WP-CLI wrapper, Makefile | `scripts/`, `Makefile` |

## Next (on the VPS) — follow doc 08

- [ ] [Doc 00](00-create-gce-vm.md): `scripts/create-gce-vm.sh` (bucket IAM, then Ubuntu VM)
- [ ] Step 1–2: `.env`, Origin CA cert, `sudo bash scripts/setup-vps.sh`
- [ ] Step 3: `scripts/gcs.sh check` on the VPS (skip `grant-vm` if you used doc 00)
- [ ] Step 4: `scripts/pull-gcs-backup.sh`
- [ ] Step 5–7: import DB, import wp-content, `scripts/post-import.sh`
- [ ] Step 8: Cloudflare DNS, SSL Full (strict), Cache Rules, WAF, rate limit
- [ ] Step 9: verify publish → purge → edge MISS → HIT
- [ ] Step 10: go-live checklist

## After go-live

### Phase 7 — Load test & tune (½ day)
Harness is in `loadtest/k6/` + `scripts/loadtest-urls.sh` / `scripts/loadtest-observe.sh`.
Runbook and empty results tables: **[doc 09](09-load-test-results.md)**. Origin-direct only
(k6 on a second machine, Cloudflare bypassed).

- [ ] Generator VM in the same region; temporarily allow its IP on ufw; smoke curl is `HIT`
- [ ] (a) `hit-storm.js` (b) `make purge` then `purge-storm.js` (c) `editor-storm.js` draft saves
- [ ] Watch `make loadtest-observe` (and `make stats` / `journalctl -u php8.3-fpm`)
- [ ] Record numbers in doc 09; tune `pm.max_children`, `DB_BUFFER_POOL`, TTLs only from evidence

### Phase 8 — Observability (½ day)
- [ ] Netdata (host install) or Prometheus exporters + Grafana Cloud
- [ ] Alerts per doc 07 (BYPASS ratio, FPM saturation, 5xx, disk, OOM)
- [ ] External uptime check on `/-/health`

### Later options
- Media offload to Cloudflare R2 once `uploads/` grows past ~50 GB: **[doc 10](10-r2-media-offload.md)** (decision: doc 05 Q6)
- Staging vhost (Q12)
- Authenticated Origin Pulls (two lines in `config/nginx/snippets/tls.conf`)
- Meilisearch if native search becomes a hotspot
- Redis object cache if miss TTFB is SQL-bound (doc 05 Q3)
