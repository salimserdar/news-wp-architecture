// Scenario C — readers on the hot set at 500 HTML req/s while a few VUs save a DRAFT
// via REST (Application Password). Do not publish. Keep editor VUs tiny so we
// test reader HIT ratio under a little write load, not REST rate limits.
//
//   k6 run -e HOST=www.example.com -e ORIGIN=203.0.113.10 \
//     -e EDITOR_USER=loadtest -e EDITOR_PASS='xxxx xxxx xxxx xxxx' \
//     -e EDITOR_POST_ID=123 loadtest/k6/editor-storm.js

import http from 'k6/http';
import { check, sleep } from 'k6';
import encoding from 'k6/encoding';
import {
  originTls,
  loadUrls,
  getHtml,
  warmUrls,
  pick,
  env,
  baseUrl,
  abortingFailureThreshold,
} from './lib.js';

const urls = loadUrls();
const editorUser = env('EDITOR_USER');
const editorPass = env('EDITOR_PASS');
const editorPostId = env('EDITOR_POST_ID', String(urls.editorPostId || ''));

export const options = {
  ...originTls(),
  scenarios: {
    readers: {
      executor: 'constant-arrival-rate',
      exec: 'reader',
      rate: 500,
      timeUnit: '1s',
      duration: '2m',
      preAllocatedVUs: 80,
      maxVUs: 600,
      tags: { rps: '500', role: 'reader' },
      gracefulStop: '10s',
    },
    editors: {
      executor: 'constant-vus',
      exec: 'editor',
      vus: 3,
      duration: '2m',
      tags: { role: 'editor' },
      gracefulStop: '10s',
    },
  },
  thresholds: {
    http_req_failed: abortingFailureThreshold(0.01),
    'http_req_failed{role:reader}': ['rate<0.001'],
    'http_req_waiting{role:reader}': ['p(95)<50'],
    'http_req_failed{role:editor}': ['rate<0.01'],
    cache_hit_ratio: ['rate>0.99'],
    cache_bypass_ratio: ['rate<0.01'],
  },
};

export function setup() {
  if (!editorPostId || editorPostId === '0') {
    throw new Error('set EDITOR_POST_ID or generate a draft with scripts/loadtest-urls.sh --create-draft');
  }
  warmUrls(urls.hot);
  return { postId: editorPostId };
}

export function reader() {
  getHtml(pick(urls.hot));
}

export function editor(data) {
  const auth = encoding.b64encode(`${editorUser}:${editorPass}`);
  const res = http.patch(
    `${baseUrl()}/wp-json/wp/v2/posts/${data.postId}`,
    JSON.stringify({
      content: `k6 loadtest ping ${Date.now()}`,
      status: 'draft',
    }),
    {
      headers: {
        Authorization: `Basic ${auth}`,
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'User-Agent': 'news-wp-k6/1.0',
      },
      tags: { name: '/wp-json/wp/v2/posts/{id}' },
      timeout: '30s',
    },
  );
  check(res, {
    'editor 200': (r) => r.status === 200,
    'editor not 429': (r) => r.status !== 429,
  });
  sleep(2);
}
