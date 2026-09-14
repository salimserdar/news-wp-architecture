# High-Traffic WordPress News Site — Architecture Docs

Target: a WordPress news site that must survive traffic spikes (breaking news, social
virality) on a **single VPS with 8 vCPU / 32 GB RAM**, with Cloudflare in front, running
as a **Docker Compose** stack.

The guiding principle is simple: **the origin should almost never render a page.**
Every layer exists to keep requests away from PHP and MySQL.

```
Reader ──> Cloudflare (edge cache, WAF, TLS)
             └──> nginx (FastCGI full-page cache)
                    ├──> php        (public FPM pool)  ──> Redis (object cache) ──> MariaDB
                    └──> php-admin  (editor FPM pool)  ──> Redis ──> MariaDB
```

## Documents

| # | Document | What it covers |
|---|----------|----------------|
| 01 | [Requirements & Assumptions](01-requirements-and-assumptions.md) | Traffic goals, constraints, what "high traffic" means for us |
| 02 | [Architecture Overview](02-architecture-overview.md) | Layers, request flow, component responsibilities |
| 03 | [Caching Strategy](03-caching-strategy.md) | The five cache layers, TTLs, bypass rules, **invalidation** |
| 04 | [Resource Allocation](04-resource-allocation.md) | How 8 CPU / 32 GB is divided between services |
| 05 | [Alternatives & Decisions](05-alternatives-and-decisions.md) | Options considered, **decision log**, remaining open questions |
| 06 | [Implementation Roadmap](06-implementation-roadmap.md) | Phases; what is done, what is next |
| 07 | [Operations](07-operations.md) | Monitoring, backups, security hardening, deploy flow |
| **08** | [**Docker Implementation Guide**](08-docker-implementation-guide.md) | **Step-by-step: VPS → running site with your imported DB and wp-content** |
| 09 | [Load test results](09-load-test-results.md) | Origin k6 runbook, SLOs, empty tables to fill after Phase 7 |

## Status

- [x] Architecture written and decisions taken (doc 05)
- [x] Stack implemented: `docker-compose.yml`, nginx/PHP/MariaDB/Redis configs, mu-plugins, scripts
- [x] Verified locally: cache HIT/MISS/BYPASS rules, tracking-param stripping, pool routing,
      purge-on-publish → warm → fresh HIT, Redis object cache (PhpRedis + igbinary)
- [ ] Deployed to the VPS, DB + wp-content imported (follow doc 08)
- [ ] Cloudflare configured (doc 08, step 7)
- [ ] Load test on the VPS (doc 06 phase 7, runbook in [doc 09](09-load-test-results.md))
