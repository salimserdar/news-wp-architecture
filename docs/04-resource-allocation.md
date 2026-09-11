# 04 — Resource Allocation (8 vCPU / 32 GB RAM)

> The authoritative values live in `docker-compose.yml`, `.env`, `config/php/pool-*.conf`,
> `config/mariadb/zz-tuning.cnf` and `config/redis/redis.conf`. This page explains *why* they are what they are.

Budgeting up front prevents the classic single-server failure: every service configured as
if it owned the whole box, then the OOM-killer picks a victim during the first traffic spike.

## Memory budget

| Service | Allocation | Notes |
|---------|-----------:|-------|
| MariaDB | **10 GB** | `innodb_buffer_pool_size = 8G` + ~2 GB for connections, temp tables, log buffer |
| PHP-FPM `www` (public) pool | **4 GB** | 48 children × ~80 MB worst case |
| PHP-FPM `admin` pool | **1.5 GB** | 12 children × ~120 MB (admin pages are heavier) |
| OPcache | 0.4 GB | 256 MB code + 128 MB JIT (shared, counted once) |
| Redis | **2 GB** | `maxmemory 2gb` + overhead; LRU eviction |
| Nginx | 0.5 GB | Workers + `keys_zone` 100 MB |
| OS + page cache | **~13 GB** | Nginx cache files, DB data files, PHP files all live here — this is *not* "wasted" |
| **Total committed** | **~19 GB** | Leaves ~13 GB headroom / page cache. No swap dependency. |

Rules:
- Keep committed memory under ~60 % of RAM. The page cache is what makes the disk-backed
  Nginx cache and MariaDB fast; starving it hurts more than a slightly bigger buffer pool helps.
- Configure **2 GB swap** with `vm.swappiness=10` as an emergency buffer only.
- `vm.overcommit_memory=1` (Redis requirement for background operations).

## CPU budget

| Service | Cores (soft) | How it's enforced |
|---------|-------------:|-------------------|
| Nginx | 2 | `worker_processes 2; worker_connections 8192;` — Nginx serving cache hits is very cheap; 2 workers saturate a 1 Gbps link |
| PHP-FPM public | up to 5 | `pm.max_children` sized so all children busy ≈ 5 cores |
| PHP-FPM admin | up to 2 | Shared with public under contention — admin is bursty |
| MariaDB | up to 4 | `innodb_read_io_threads=4`, `innodb_write_io_threads=4`; mostly idle thanks to Redis |
| Redis | 1 | Single-threaded core loop; `io-threads 2` for network |
| Cron / backups / warming | leftovers | Run under `nice 10 ionice -c3` |

Cores are oversubscribed on purpose: under normal load Nginx serves 95 %+ of requests and
PHP/DB idle; under a cache-miss storm PHP takes the cores. Per-container `cpus`/`memory` limits
(`.env`: `NGINX_CPUS`, `PHP_CPUS`, `DB_MEM`, ...) enforce the split; see `docker-compose.yml`.

## PHP-FPM pool sizing

Empirical formula: `max_children = available_RAM_for_pool / avg_process_size`.
WordPress with a typical news theme + Redis: **60–80 MB per child** (measure with
`ps -ylC php-fpm8.3 --sort:rss`). Plan for 80 MB.

```ini
; /etc/php/8.3/fpm/pool.d/www.conf   (public traffic, cache misses)
pm = static                ; predictable memory, no fork latency under spikes
pm.max_children = 48
pm.max_requests = 1000     ; recycle to contain leaks
request_terminate_timeout = 30s
listen = 9000                       ; container "php"
listen.backlog = 4096

; /etc/php/8.3/fpm/pool.d/admin.conf (wp-admin, logged-in, REST writes)
pm = dynamic
pm.max_children = 12
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 4
request_terminate_timeout = 300s   ; imports, media processing
listen = 9000                       ; container "php-admin"
php_admin_value[memory_limit] = 512M
```

Why 48 static? A cache-miss render at ~120 ms means 48 children ≈ **400 renders/s**
theoretical, ~200/s realistic. Our target is < 20/s. The headroom is for purge storms and
Cloudflare "purge everything" events — 48 children absorb them without queueing.

`pm = static` for the public pool: forking under a spike is exactly when we can't afford it,
and the memory is budgeted anyway.

## MariaDB

```ini
[mysqld]
innodb_buffer_pool_size        = 8G
innodb_buffer_pool_instances   = 8
innodb_log_file_size           = 1G
innodb_flush_log_at_trx_commit = 2    # news site: 1 s of durability risk is acceptable
innodb_flush_method            = O_DIRECT
innodb_io_capacity             = 2000 # NVMe
innodb_io_capacity_max         = 4000
max_connections                = 150  # 48 + 12 FPM children + cron + slack
table_open_cache               = 4000
tmp_table_size                 = 256M
max_heap_table_size            = 256M
query_cache_type               = 0    # off; Redis does this job better
slow_query_log                 = 1
long_query_time                = 1
```
A WordPress news DB with 100k posts is typically 2–6 GB; an 8 GB buffer pool keeps the entire
working set in memory. If the DB grows past ~10 GB we revisit (and archive old `postmeta`).

## Redis

```
maxmemory 2gb
maxmemory-policy allkeys-lru
save ""
appendonly no
port 6379                 # not published on the host
io-threads 2
```

## Nginx

```
worker_processes 2;
worker_rlimit_nofile 65535;
events { worker_connections 8192; use epoll; multi_accept on; }
keepalive_timeout 30;
keepalive_requests 1000;
open_file_cache max=20000 inactive=60s;
```
Cloudflare keeps long-lived connections to the origin, so the concurrent connection count at
Nginx is far lower than the reader count.

## Kernel / limits

```
net.core.somaxconn = 65535
net.ipv4.tcp_max_syn_backlog = 65535
net.ipv4.ip_local_port_range = 1024 65535
net.ipv4.tcp_tw_reuse = 1
fs.file-max = 2097152
vm.swappiness = 10
vm.overcommit_memory = 1
```
Applied by `scripts/setup-vps.sh`; containers inherit the Docker daemon's default `nofile` limit (1M).

## Disk

- **To confirm:** size and type. Assumed NVMe, ≥ 100 GB.
- Rough usage: OS 10 GB · WordPress + uploads 20–100 GB (grows with media!) · DB 5–10 GB ·
  Nginx cache 4 GB · logs 5 GB · local backup staging 10 GB.
- Uploads are the growth risk. Offloading media to **Cloudflare R2** (S3-compatible, zero egress
  fees) is the natural escape valve — see doc 05, Q6.

## What happens in a 10× spike (sanity check)

Say normal is 30 HTML req/s and a story goes viral: 300 req/s HTML + ~3 000 req/s static.

1. Cloudflare serves ~99 % of static and ~85 % of HTML at the edge. Origin sees ~45 HTML req/s.
2. Nginx serves ~95 % of those from FastCGI cache. PHP sees **~2–3 renders/s**.
3. 48 PHP children are ~1 % utilised. MariaDB barely notices thanks to Redis.

Even if Cloudflare HTML caching were completely disabled, 300 req/s at Nginx with a 95 % hit
ratio = 15 renders/s — still well inside capacity. The architecture has roughly **two orders of
magnitude** of headroom on the described box, as long as cache bypass stays rare.
