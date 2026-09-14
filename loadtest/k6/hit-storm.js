// Scenario A — cache-hit storm on the hot set (homepage + ~20 articles + feed).
//
// On the VPS first: make warm && make loadtest-urls
// Then from a second machine:
//   k6 run -e HOST=www.example.com -e ORIGIN=203.0.113.10 loadtest/k6/hit-storm.js
//
// Pass at 500 HTML req/s: HIT ≥ 99%, p95 TTFB < 50 ms, 5xx < 0.1%. Abort if 5xx > 1%.

import { originTls, loadUrls, getHtml, warmUrls, pick, htmlRateScenarios, SLO_RAMP, abortingFailureThreshold } from './lib.js';

const urls = loadUrls();

export const options = {
  ...originTls(),
  scenarios: htmlRateScenarios(SLO_RAMP, 'hit'),
  thresholds: {
    http_req_failed: abortingFailureThreshold(0.01),
    'http_req_failed{rps:500}': ['rate<0.001'],
    'http_req_waiting{rps:500}': ['p(95)<50'],
    cache_hit_ratio: ['rate>0.99'],
    cache_bypass_ratio: ['rate<0.001'],
  },
};

export function setup() {
  warmUrls(urls.hot);
}

export function hit() {
  getHtml(pick(urls.hot));
}
