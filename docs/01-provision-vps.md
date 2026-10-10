# 01 — Provision the VPS

Any **Ubuntu 24.04 LTS** VPS. The provider does not matter. This page is only the
machine: size, SSH, and which ports are open. The stack is
[02 — Install and configure](02-install-and-configure.md).

## What to order

Cached HTML barely uses CPU. RAM is what matters (MariaDB buffer pool, PHP
children, page cache). Why those numbers look like this:
[Resource allocation](reference/resources.md).

| vCPU | RAM | Disk | Use |
|-----:|----:|------|-----|
| 2 | 16 GB | NVMe, ≥ 100 GB | Production origin with FastCGI cache |
| 2 | 8 GB | NVMe, ≥ 100 GB | Staging or modest traffic. Set `DB_BUFFER_POOL=1G` and `PHP_MAX_CHILDREN=6` in `.env` |
| 8 | 32 GB | NVMe, ≥ 200 GB | Headroom for the traffic targets in [Requirements](reference/requirements.md) |

Uploads are the part that grows. If the existing media tree is already large,
size the disk for it (OS ~10 GB, database ~5–10 GB, nginx cache 4 GB, plus
`uploads/`). Moving media to R2 later is [07](07-media-offload.md).

## Before you SSH

- Ubuntu 24.04 LTS, root SSH or a user with sudo.
- A public IPv4.
- The provider firewall allows TCP **22**, **80**, and **443**.
- Leave 80/443 open to the world until Cloudflare DNS is live
  (`scripts/setup-vps.sh` does this with `WEB_OPEN=1`). After cut-over,
  [04](04-cloudflare-and-go-live.md) locks them to Cloudflare IP ranges
  (`WEB_OPEN=0`).
- MariaDB listens on localhost only. Do not publish 3306.

## SSH

```bash
ssh root@VPS_IP
```

Then follow [02 — Install and configure](02-install-and-configure.md).
