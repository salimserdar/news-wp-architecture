// Mixed / Pareto HTML traffic: ~80% homepage + hot articles, ~20% long-tail.
// Same ramp as hit-storm; HIT ratio is allowed to be lower because long-tail cold entries miss once.
//
//   k6 run -e HOST=www.example.com -e ORIGIN=203.0.113.10 loadtest/k6/mixed.js

import { originTls, loadUrls, getHtml, warmUrls, pickMixed, htmlRateScenarios, SLO_RAMP, abortingFailureThreshold } from './lib.js';

const urls = loadUrls();

export const options = {
  ...originTls(),
  scenarios: htmlRateScenarios(SLO_RAMP, 'mixed'),
  thresholds: {
    http_req_failed: abortingFailureThreshold(0.01),
    'http_req_failed{rps:500}': ['rate<0.001'],
    'http_req_waiting{rps:500}': ['p(95)<100'],
    cache_hit_ratio: ['rate>0.90'],
    cache_bypass_ratio: ['rate<0.001'],
  },
};

export function setup() {
  warmUrls(urls.hot);
}

export function mixed() {
  getHtml(pickMixed(urls));
}
