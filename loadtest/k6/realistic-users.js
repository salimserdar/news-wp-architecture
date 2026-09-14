// Realistic concurrent readers: homepage-heavy + weighted articles.
// Each VU is one browser: load HTML, then wait THINK seconds (default 20).
//
// Mix (override with -e HOME_SHARE=0.4):
//   ~40% homepage
//   ~60% articles, sampled by urls.json `weighted` (view counts 1–7)
//
//   k6 run -e HOST=www.turkishnote.com -e ORIGIN=CF_OR_VPS_IP loadtest/k6/realistic-users.js
//   k6 run -e HOST=... -e ORIGIN=... -e THINK=15 -e HOME_SHARE=0.4 loadtest/k6/realistic-users.js

import { sleep } from 'k6';
import {
  originTls,
  loadUrls,
  allPages,
  getHtml,
  warmUrls,
  pickNews,
  abortingFailureThreshold,
  env,
} from './lib.js';

const urls = loadUrls();
const think = Number(env('THINK', '20'));

export const options = {
  ...originTls(),
  setupTimeout: '2m',
  scenarios: {
    readers: {
      executor: 'ramping-vus',
      exec: 'browse',
      gracefulRampDown: '20s',
      gracefulStop: '20s',
      stages: [
        { duration: '15s', target: 500 },
        { duration: '15s', target: 500 },
        { duration: '15s', target: 1000 },
        { duration: '20s', target: 1000 },
        { duration: '20s', target: 2000 },
        { duration: '20s', target: 2000 },
        { duration: '40s', target: 5000 },
        { duration: '60s', target: 5000 },
        { duration: '20s', target: 0 },
      ],
    },
  },
  thresholds: {
    http_req_failed: abortingFailureThreshold(0.01),
    http_req_waiting: ['p(95)<2000'],
    cf_hit_ratio: ['rate>0.80'],
    cache_bypass_ratio: ['rate<0.01'],
  },
};

export function setup() {
  const pages = allPages(urls);
  if (pages.length < 2) {
    throw new Error('need at least 2 URLs in loadtest/urls.json');
  }
  if (env('SKIP_WARM', '0') !== '1') {
    warmUrls(pages);
  }
  return { urls, think, pages: pages.length };
}

export function browse(data) {
  getHtml(pickNews(data.urls));
  sleep(data.think);
}
