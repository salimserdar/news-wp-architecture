# High-Traffic WordPress News Site — Architecture Docs

Target: a WordPress news site that must survive traffic spikes (breaking news, social
virality) on a **single Ubuntu 24.04 VPS with 8 vCPU / 32 GB RAM**, with Cloudflare
in front, running **native packages** (nginx, PHP-FPM, MariaDB).

The guiding principle is simple: **the origin should almost never render a page.**
Every layer exists to keep requests away from PHP and MySQL.

```
Reader ──> Cloudflare (edge cache, WAF, TLS)
             └──> nginx (FastCGI full-page cache)
                    └──> PHP-FPM  ──> MariaDB
```

## Documents

| # | Document | What it covers |
|---|----------|----------------|
| 01 | [Requirements & Assumptions](01-requirements-and-assumptions.md) | Traffic goals, constraints, what "high traffic" means for us |
| 02 | [Architecture Overview](02-architecture-overview.md) | Layers, request flow, component responsibilities |
| 03 | [Caching Strategy](03-caching-strategy.md) | Cache layers, TTLs, bypass rules, **invalidation** |
| 04 | [Resource Allocation](04-resource-allocation.md) | How 8 CPU / 32 GB is divided between services |
| 05 | [Alternatives & Decisions](05-alternatives-and-decisions.md) | Options considered, **decision log**, remaining open questions |
| 06 | [Implementation Roadmap](06-implementation-roadmap.md) | Phases; what is done, what is next |
| 07 | [Operations](07-operations.md) | Monitoring, backups, security hardening, deploy flow |
| **08** | [**Implementation Guide**](08-implementation-guide.md) | **Step-by-step: VPS → running site with your imported DB and wp-content** |
| 09 | [Load test results](09-load-test-results.md) | Origin k6 runbook, SLOs, empty tables to fill after Phase 7 |

## Status

- [x] Architecture written and decisions taken (doc 05)
- [x] Native stack: nginx FastCGI cache, PHP-FPM, MariaDB, mu-plugins, scripts
- [ ] Deployed to the VPS, DB + wp-content imported (follow doc 08)
- [ ] Cloudflare configured (doc 08)
- [ ] Load test on the VPS (doc 06 phase 7, runbook in [doc 09](09-load-test-results.md))
