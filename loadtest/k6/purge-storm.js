// Scenario B — post-purge miss storm. Start this immediately after `make purge` on the VPS.
//
//   k6 run -e HOST=www.example.com -e ORIGIN=203.0.113.10 loadtest/k6/purge-storm.js
//
// Pass at 500 HTML req/s: 5xx < 0.5%, BYPASS ≈ 0, p95 TTFB < 500 ms (MISS then lock/stale/HIT).
// Do not require a 99% HIT ratio — the first wave is supposed to miss.

import { originTls, loadUrls, getHtml, pick, abortingFailureThreshold } from './lib.js';

const urls = loadUrls();

export const options = {
  ...originTls(),
  scenarios: {
    purge_storm: {
      executor: 'constant-arrival-rate',
      exec: 'storm',
      rate: 500,
      timeUnit: '1s',
      duration: '2m',
      preAllocatedVUs: 80,
      maxVUs: 800,
      tags: { rps: '500' },
      gracefulStop: '10s',
    },
  },
  thresholds: {
    http_req_failed: abortingFailureThreshold(0.01),
    'http_req_failed{rps:500}': ['rate<0.005'],
    'http_req_waiting{rps:500}': ['p(95)<500'],
    cache_bypass_ratio: ['rate<0.001'],
  },
};

export function storm() {
  getHtml(pick(urls.hot));
}
