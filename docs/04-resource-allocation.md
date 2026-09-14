# 04 — Resource Allocation (8 vCPU / 32 GB RAM)

> The authoritative values live in `config/php/pool-www.conf`,
> `config/mariadb/zz-tuning.cnf`, `.env` (`DB_BUFFER_POOL`, `PHP_MAX_CHILDREN`),
> and `config/nginx/http.conf`. This page explains *why* they are what they are.

Budgeting up front prevents the classic single-server failure: every service configured as
if it owned the whole box, then the OOM-killer picks a victim during the first traffic spike.

## Memory budget

| Service | Allocation | Notes |
|---------|-----------:|-------|
| MariaDB | **10 GB** | `innodb_buffer_pool_size = 8G` + ~2 GB for connections, temp tables, log buffer |
| PHP-FPM | **~2–4 GB** | 20 children × ~80–120 MB (Newspaper admin pages are heavier; 512 MB cap) |
| OPcache | 0.3 GB | 256 MB code (JIT off) |
| Nginx | 0.5 GB | Workers + `keys_zone` 100 MB |
| OS + page cache | **~17 GB** | Nginx cache files, DB data files, PHP files all live here — this is *not* "wasted" |
| **Total committed** | **~15 GB** | Leaves ~17 GB headroom / page cache. No swap dependency. |

Rules:
- Keep committed memory under ~60 % of RAM. The page cache is what makes the disk-backed
  Nginx cache and MariaDB fast; starving it hurts more than a slightly bigger buffer pool helps.
- Configure **2 GB swap** with `vm.swappiness=10` as an emergency buffer only.

## CPU budget

| Service | Cores (soft) | How it's enforced |
|---------|-------------:|-------------------|
| Nginx | auto | `worker_processes auto;` — cache hits are cheap; a couple of workers saturate a 1 Gbps link |
| PHP-FPM | up to ~4 | `pm.max_children` sized so all children busy ≈ a few cores |
| MariaDB | up to 4 | `innodb_read_io_threads=4`, `innodb_write_io_threads=4`; mostly idle thanks to page cache |
| Cron / backups / warming | leftovers | Run under `nice 10 ionice -c3` |

Cores are oversubscribed on purpose: under normal load Nginx serves 95 %+ of requests and
PHP/DB idle; under a cache-miss storm PHP takes the cores.

## PHP-FPM pool sizing

Empirical formula: `max_children = available_RAM_for_pool / avg_process_size`.
WordPress with a typical news theme: **60–120 MB per child** (measure with
`ps -ylC php-fpm8.3 --sort:rss`). Plan for 120 MB with Newspaper.

```ini
; /etc/php/8.3/fpm/pool.d/www.conf
pm = dynamic
pm.max_children = 20          ; override with PHP_MAX_CHILDREN
pm.start_servers = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 8
pm.max_requests = 500
request_terminate_timeout = 60s
listen = /run/php/php8.3-fpm.sock
php_admin_value[memory_limit] = 512M
```

One pool serves cache misses *and* editors. 20 children at ~200 ms/render ≈ **100 renders/s**
theoretical. Our target is < 20/s. Raise `PHP_MAX_CHILDREN` only if a purge-storm listen
queue is sustained (doc 09).

`pm = dynamic` keeps idle memory small. Cache hits never fork a child.

## MariaDB

```ini
[mysqld]
innodb_buffer_pool_size        = 8G
innodb_log_file_size           = 1G
innodb_flush_log_at_trx_commit = 2    # news site: 1 s of durability risk is acceptable
innodb_flush_method            = O_DIRECT
innodb_io_capacity             = 2000 # NVMe
innodb_io_capacity_max         = 4000
max_connections                = 80   # FPM children + cron + slack
table_open_cache               = 4000
tmp_table_size                 = 256M
max_heap_table_size            = 256M
query_cache_type               = 0
slow_query_log                 = 1
long_query_time                = 1
```
A WordPress news DB with 100k posts is typically 2–6 GB; an 8 GB buffer pool keeps the entire
working set in memory. If the DB grows past ~10 GB we revisit (and archive old `postmeta`).

## Nginx

```
worker_processes auto;
events { worker_connections 4096; multi_accept on; }
keepalive_requests 1000;
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
Applied by `scripts/setup-vps.sh`.

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
3. 20 PHP children are barely utilised. MariaDB barely notices.

Even if Cloudflare HTML caching were completely disabled, 300 req/s at Nginx with a 95 % hit
ratio = 15 renders/s — still well inside capacity. The architecture has roughly **two orders of
magnitude** of headroom on the described box, as long as cache bypass stays rare.
