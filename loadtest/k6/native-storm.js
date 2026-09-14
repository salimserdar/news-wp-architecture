// Native VPS (no FastCGI cache, no Redis): every HTML request hits PHP-FPM.
// Default pool is ~20 children — 500 HTML req/s will 502. This ramp finds the
// uncached ceiling instead of assuming Docker+cache SLOs.
//
//   k6 run -e HOST=www.turkishnote.com -e ORIGIN=CF_OR_VPS_IP loadtest/k6/native-storm.js
//
// Abort if 5xx/connect failures > 1%. Pass: 5xx < 0.5% at 30 rps, p95 TTFB < 1500 ms.

import { originTls, loadUrls, getHtml, warmUrls, pick, htmlRateScenarios, abortingFailureThreshold } from './lib.js';

const urls = loadUrls();

const NATIVE_RAMP = [
  { rate: 5, duration: '30s' },
  { rate: 15, duration: '30s' },
  { rate: 30, duration: '45s' },
  { rate: 50, duration: '45s' },
];

export const options = {
  ...originTls(),
  scenarios: htmlRateScenarios(NATIVE_RAMP, 'hit'),
  thresholds: {
    http_req_failed: abortingFailureThreshold(0.01),
    'http_req_failed{rps:30}': ['rate<0.005'],
    'http_req_waiting{rps:30}': ['p(95)<1500'],
    'http_req_failed{rps:50}': [{ threshold: 'rate<0.01', abortOnFail: false }],
  },
};

export function setup() {
  warmUrls(urls.hot);
}

export function hit() {
  getHtml(pick(urls.hot));
}
