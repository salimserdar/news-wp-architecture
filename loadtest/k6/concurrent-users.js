// Prove concurrent *browsers*: each VU is one user who loads an HTML page then waits.
// THINK (seconds between clicks) defaults to 10. 1000 VUs × 10s ≈ 100 HTML req/s.
//
// Uses every URL in loadtest/urls.json (home + hot + longtail).
//
//   k6 run -e HOST=www.turkishnote.com -e ORIGIN=CF_OR_VPS_IP loadtest/k6/concurrent-users.js
//   k6 run -e HOST=... -e ORIGIN=... -e THINK=15 loadtest/k6/concurrent-users.js

import { sleep } from 'k6';
import {
  originTls,
  loadUrls,
  allPages,
  getHtml,
  warmUrls,
  pick,
  abortingFailureThreshold,
  env,
} from './lib.js';

const urls = loadUrls();
const pages = allPages(urls);
const think = Number(env('THINK', '10'));

export const options = {
  ...originTls(),
  scenarios: {
    browsers: {
      executor: 'ramping-vus',
      exec: 'browse',
      gracefulRampDown: '10s',
      gracefulStop: '10s',
      stages: [
        { duration: '20s', target: 100 },
        { duration: '30s', target: 100 },
        { duration: '20s', target: 250 },
        { duration: '30s', target: 250 },
        { duration: '20s', target: 500 },
        { duration: '40s', target: 500 },
        { duration: '20s', target: 1000 },
        { duration: '45s', target: 1000 },
        { duration: '20s', target: 0 },
      ],
    },
  },
  thresholds: {
    http_req_failed: abortingFailureThreshold(0.01),
    http_req_waiting: ['p(95)<500'],
    cache_bypass_ratio: ['rate<0.01'],
  },
};

export function setup() {
  if (pages.length < 2) {
    throw new Error('need at least 2 URLs in loadtest/urls.json');
  }
  warmUrls(pages);
  return { pages, think };
}

export function browse(data) {
  getHtml(pick(data.pages));
  sleep(data.think);
}
