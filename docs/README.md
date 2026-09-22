# High-Traffic WordPress News Site — Architecture Docs

Target: a WordPress news site that must survive traffic spikes (breaking news, social
virality) on a **single Ubuntu 24.04 VPS**, with Cloudflare in front, running
**native packages** (nginx, PHP-FPM, MariaDB).

The guiding principle is simple: **the origin should almost never render a page.**
Every layer exists to keep requests away from PHP and MySQL.

```
Reader ──> Cloudflare (edge cache, WAF, TLS)
             └──> nginx (FastCGI full-page cache)
                    └──> PHP-FPM  ──> MariaDB
```

## Contents

1. [Start here](#start-here)
2. [Documents](#documents)
3. [Status](#status)

## Start here

| Order | Do this |
|-------|---------|
| 1 | [**00 — Create the GCE VM**](00-create-gce-vm.md) — `gcloud` from your laptop; **grant `gs://tr724-backup` before the VM exists** |
| 2 | [**08 — Implementation guide**](08-implementation-guide.md) — nginx/PHP/MariaDB, pull backup, import, Cloudflare |

## Documents

| # | Document | What it covers |
|---|----------|----------------|
| **00** | [**Create the GCE VM**](00-create-gce-vm.md) | `gcloud` one-shot: bucket IAM first, then Ubuntu instance |
| 01 | [Requirements & Assumptions](01-requirements-and-assumptions.md) | Traffic goals, constraints, what "high traffic" means for us |
| 02 | [Architecture Overview](02-architecture-overview.md) | Layers, request flow, component responsibilities |
| 03 | [Caching Strategy](03-caching-strategy.md) | Cache layers, TTLs, bypass rules, **invalidation** |
| 04 | [Resource Allocation](04-resource-allocation.md) | How CPU / RAM is divided; 2 vCPU / 8–16 GB vs 8 / 32 GB |
| 05 | [Alternatives & Decisions](05-alternatives-and-decisions.md) | Options considered, **decision log**, remaining open questions |
| 06 | [Implementation Roadmap](06-implementation-roadmap.md) | Phases; what is done, what is next |
| 07 | [Operations](07-operations.md) | Monitoring, backups, security hardening, deploy flow |
| **08** | [**Implementation Guide**](08-implementation-guide.md) | **Step-by-step on the VPS: stack, import, Cloudflare** |
| 09 | [Load test results](09-load-test-results.md) | Origin k6 runbook, SLOs, empty tables to fill after Phase 7 |
| 10 | [R2 media offload](10-r2-media-offload.md) | Later: copy `uploads/` to Cloudflare R2, keep public URLs |

## Status

- [x] Architecture written and decisions taken (doc 05)
- [x] Native stack: nginx FastCGI cache, PHP-FPM, MariaDB, mu-plugins, scripts
- [ ] GCE VM created with bucket access ([doc 00](00-create-gce-vm.md))
- [ ] Deployed, DB + wp-content imported ([doc 08](08-implementation-guide.md))
- [ ] Cloudflare configured (doc 08)
- [ ] Load test on the VPS (doc 06 phase 7, runbook in [doc 09](09-load-test-results.md))
