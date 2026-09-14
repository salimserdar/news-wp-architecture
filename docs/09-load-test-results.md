# 09 — Origin load test (k6)

Harness for Phase 7 in [doc 06](06-implementation-roadmap.md). This test hits the
**origin VPS directly** (Cloudflare bypassed) so the numbers are nginx FastCGI + PHP-FPM
+ MariaDB — not the edge cache.

Do **not** run k6 on the origin. Do **not** hammer `/wp-login.php` (5 r/m) as the main
RPS source.

Scripts:

| Path | Where it runs |
|------|----------------|
| `loadtest/k6/*.js` | **Generator** (second VM, same region as the VPS) |
| `scripts/loadtest-urls.sh` / `make loadtest-urls` | **VPS** |
| `scripts/loadtest-observe.sh` / `make loadtest-observe` | **VPS** |
| `make warm` / `make purge` / `make stats` | **VPS** |

Fill in the results tables at the bottom after a run. Do not change `pm.max_children`,
`DB_BUFFER_POOL`, or TTLs until those tables have numbers.

## Pass / fail (from [doc 01](01-requirements-and-assumptions.md))

| Scenario | Load | Must hold |
|----------|------|-----------|
| A. Cache-hit storm (`hit-storm.js`) | Ramp to **500 HTML req/s**, then 1000 / 2000 to find the ceiling | `X-FastCGI-Cache: HIT` ≥ 99%, p95 TTFB **< 50 ms** at 500 rps, HTTP 5xx **< 0.1%**, FPM almost idle |
| B. Post-purge storm (`purge-storm.js`) | `make purge` then 500 req/s for 2 min | 5xx **< 0.5%**, no sustained FPM listen queue, p95 TTFB **< 500 ms**, BYPASS ≈ 0 |
| C. Editors during storm (`editor-storm.js`) | 500 HTML req/s readers + 3 draft saves | Readers stay on HIT; FPM absorbs the writes |
| Mixed (`mixed.js`) | Same ramp, 80% hot / 20% long-tail | 5xx **< 0.1%** at 500 rps, HIT ≥ 90%, p95 **< 100 ms** |
| Concurrent browsers (`realistic-users.js`) | Ramp VUs with think time; homepage-heavy mix | 5xx **< 1%**, p95 TTFB **< 2 s** |
| SLO peak | 500+ HTML req/s once warm | Origin PHP **< 20 renders/s** |

Stop the run if 5xx > 1% (k6 aborts) or the box swaps / OOM. Record the first RPS where
p95 or errors break — that is the real ceiling.

---

## 1. Generator machine

A second VM in the **same region** as the origin (4 vCPU is enough for ~2k HTTP/2 RPS).

Install [k6](https://grafana.com/docs/k6/latest/set-up/install-k6/). On Ubuntu:

```bash
sudo gpg -k
sudo gpg --no-default-keyring --keyring /usr/share/keyrings/k6-archive-keyring.gpg \
  --keyserver hkp://keyserver.ubuntu.com:80 \
  --recv-keys C5AD17C747E3415A3642D57D77C6C491D6DFD2A9
echo "deb [signed-by=/usr/share/keyrings/k6-archive-keyring.gpg] https://dl.k6.io/deb stable main" \
  | sudo tee /etc/apt/sources.list.d/k6.list
sudo apt-get update && sudo apt-get install k6
```

Clone this repo (or copy `loadtest/` + `loadtest/urls.json`). All `k6 run` commands below
assume **cwd = repo root**.

Note the generator's public IPv4: `curl -4 -s ifconfig.me`

## 2. Open the origin firewall (temporary)

If 80/443 are locked to Cloudflare (`WEB_OPEN=0`), allow the generator:

```bash
# on the VPS
ufw allow from LOADGEN.IP to any port 443 proto tcp comment 'k6-generator'
ufw status numbered | head
```

Remove the rule when finished (`ufw delete <number>`).

If `WEB_OPEN=1` (ports open to the world), skip this step.

## 3. Smoke curl (must be HIT before any storm)

```bash
# from the generator — pin HOST to the origin IP, skip Origin CA / self-signed verify
curl -sk --resolve $SITE_DOMAIN:443:ORIGIN_IP \
  -o /dev/null -w "%{http_code} ttfb=%{time_starttransfer} cache=%header{x-fastcgi-cache}\n" \
  https://$SITE_DOMAIN/
```

On the VPS, if the homepage is cold: `make warm` then curl again.

Expect `200` and `HIT`. If you see `BYPASS`, a plugin is setting cookies on anonymous traffic
— fix that before load testing or the PHP children will melt.

## 4. Export URLs (VPS)

```bash
make loadtest-urls
# optional, for scenario C:
make loadtest-urls ARGS="--create-draft"
scripts/wp.sh user application-password create EDITOR_USER k6
```

Copy `loadtest/urls.json` to the generator next to this repo layout. k6 loads
`../urls.json` from `loadtest/k6/` by default (that is `loadtest/urls.json`).
Override with an absolute path: `-e URLS=/path/to/urls.json`. Shape is in
`loadtest/urls.example.json`.

Create a throwaway editor (or use an existing one) with an Application Password. Keep the
fixture post **draft**. Do not publish spam.

## 5. Observe (VPS) + run k6 (generator)

On the VPS, in a dedicated shell:

```bash
sudo make loadtest-observe
# CSV → loadtest/results/observe-*.csv  (HIT/MISS/BYPASS, FPM, MariaDB, load)
```

On the generator (replace HOST / ORIGIN):

```bash
export HOST=www.example.com ORIGIN=ORIGIN_IP   # IP or https://IP

# A — cache-hit storm (50 → 200 → 500 → 1000 → 2000 HTML req/s)
k6 run -e HOST="$HOST" -e ORIGIN="$ORIGIN" loadtest/k6/hit-storm.js

# B — immediately after `make purge` on the VPS
k6 run -e HOST="$HOST" -e ORIGIN="$ORIGIN" loadtest/k6/purge-storm.js

# Mixed Pareto (optional, after A)
k6 run -e HOST="$HOST" -e ORIGIN="$ORIGIN" loadtest/k6/mixed.js

# Concurrent browsers with think time (homepage-heavy)
k6 run -e HOST="$HOST" -e ORIGIN="$ORIGIN" loadtest/k6/realistic-users.js

# C — readers at 500 rps + 3 VUs PATCHing the draft
k6 run -e HOST="$HOST" -e ORIGIN="$ORIGIN" \
  -e EDITOR_USER=EDITOR_USER \
  -e EDITOR_PASS='xxxx xxxx xxxx xxxx' \
  -e EDITOR_POST_ID=123 \
  loadtest/k6/editor-storm.js
```

k6 sends `Host: $HOST` to `ORIGIN` with TLS verify off (Origin CA or nginx self-signed).
HTML requests use `User-Agent: news-wp-k6/1.0`.

Scenario C authenticates with an Application Password. Editor VUs sleep 2 s between writes.

## 6. Cleanup

```bash
# VPS
ufw status numbered            # delete the k6-generator rule
# optional: scripts/wp.sh post delete EDITOR_POST_ID --force
```

Paste k6 summaries + a few CSV rows into the tables below.

---

## Run record

| Field | Value |
|-------|-------|
| Date | |
| Origin VPS (provider, region, 8 vCPU / 32 GB confirmed?) | |
| Disk (NVMe? size?) | |
| Generator VM | |
| k6 version (`k6 version`) | |
| `SITE_DOMAIN` | |
| WordPress / theme / plugin count | |
| Notes (BYPASS plugins, anything unusual) | |

### A — hit-storm

| Stage (rps) | p50 TTFB | p95 TTFB | p99 TTFB | 5xx % | HIT % | FPM active | php RSS | nginx CPU % | pass? |
|-------------|----------|----------|----------|-------|-------|------------|---------|-------------|-------|
| 50 | | | | | | | | | |
| 200 | | | | | | | | | |
| **500 (SLO)** | | | | | | | | | |
| 1000 | | | | | | | | | |
| 2000 | | | | | | | | | |

Ceiling (first stage that fails p95 / errors):

### B — purge-storm (500 rps × 2 min after `make purge`)

| Metric | Value |
|--------|-------|
| 5xx % | |
| p95 TTFB | |
| HIT / MISS / STALE / UPDATING mix | |
| Peak FPM active / listen queue | |
| Time until HIT dominates | |
| pass? | |

### C — editor-storm

| Metric | Value |
|--------|-------|
| Reader p95 TTFB / HIT % / 5xx % | |
| Peak FPM active | |
| Editor HTTP 200 vs 429 | |
| pass? | |

### Mixed (optional)

| Stage | p95 TTFB | HIT % | 5xx % | pass? |
|-------|----------|-------|-------|-------|
| 500 | | | | |

## What we would tune (only from evidence)

| Knob | File | Observed problem that would justify changing it |
|------|------|--------------------------------------------------|
| `pm.max_children` | `config/php/pool-www.conf` / `PHP_MAX_CHILDREN` | Sustained listen queue on purge-storm, HIT storm still idle |
| `DB_BUFFER_POOL` | `.env` | `Threads_running` high on misses |
| Page TTL | `wp/mu-plugins/cache-control.php` | Miss storm lasts longer than lock/stale can hide |
| BYPASS | plugins / nginx maps | Anonymous `BYPASS` > ~1% — do not raise PHP, fix cookies |

## k6 metrics cheat sheet

- `http_req_waiting` = TTFB
- `cache_hit_ratio` / `cache_bypass_ratio` = custom rates from `X-FastCGI-Cache`
- `http_req_failed` = non-2xx/3xx (5xx included). Abort if rate > 1%
- Thresholds tagged `{rps:500}` are the SLO; 1000/2000 are ceiling-finding and do not abort on p95
