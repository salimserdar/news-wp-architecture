// Shared k6 helpers for origin-direct HTML load tests.
//
// Required env (generator machine, never the origin VPS):
//   HOST    SITE_DOMAIN, e.g. www.example.com
//   ORIGIN  origin IP or URL, e.g. 203.0.113.10 or https://203.0.113.10
// Optional:
//   URLS    path to urls.json, relative to this script dir or absolute
//           (default ../urls.json → loadtest/urls.json)

import http from 'k6/http';
import { check } from 'k6';
import { Counter, Rate } from 'k6/metrics';

export const cacheHitRatio = new Rate('cache_hit_ratio');
export const cacheBypassRatio = new Rate('cache_bypass_ratio');
export const cacheMissRatio = new Rate('cache_miss_ratio');
export const cacheStatus = new Counter('cache_status');

export function env(name, fallback) {
  const v = __ENV[name];
  if (v === undefined || v === '') {
    if (fallback !== undefined) return fallback;
    throw new Error(`missing -e ${name}=...`);
  }
  return v;
}

export function originIp() {
  return env('ORIGIN').replace(/^https?:\/\//, '').replace(/\/.*$/, '');
}

export function siteHost() {
  return env('HOST');
}

export function baseUrl() {
  return `https://${siteHost()}`;
}

/** k6 options fragment: pin HOST to the origin IP and skip Origin CA / self-signed verify. */
export function originTls() {
  return {
    insecureSkipTLSVerify: true,
    hosts: { [siteHost()]: originIp() },
    discardResponseBodies: true,
  };
}

export function loadUrls() {
  const path = env('URLS', '../urls.json');
  const data = JSON.parse(open(path));
  const hot = [data.home, ...(data.hot || []), data.feed].filter(Boolean);
  const longtail = data.longtail || [];
  if (hot.length < 2) {
    throw new Error(`${path} needs home + hot article URLs — run scripts/loadtest-urls.sh on the VPS`);
  }
  return { home: data.home, feed: data.feed, hot, longtail, editorPostId: data.editorPostId };
}

export function pathOf(absolute) {
  const afterScheme = absolute.indexOf('://');
  const fromHost = afterScheme === -1 ? absolute : absolute.slice(afterScheme + 3);
  const slash = fromHost.indexOf('/');
  return slash === -1 ? '/' : fromHost.slice(slash);
}

function cacheHeader(res) {
  return String(res.headers['X-FastCGI-Cache'] || res.headers['X-Fastcgi-Cache'] || '').toUpperCase();
}

export function recordCache(res) {
  const status = cacheHeader(res) || 'NONE';
  cacheStatus.add(1, { status });
  cacheHitRatio.add(status === 'HIT');
  cacheBypassRatio.add(status === 'BYPASS');
  cacheMissRatio.add(status === 'MISS' || status === 'EXPIRED');
}

export function htmlHeaders() {
  return {
    'User-Agent': 'news-wp-k6/1.0',
    Accept: 'text/html,application/xhtml+xml',
  };
}

/** One HTML GET against the origin. Records cache-status metrics. */
export function getHtml(absoluteUrl) {
  const url = baseUrl() + pathOf(absoluteUrl);
  const res = http.get(url, {
    headers: htmlHeaders(),
    tags: { name: pathOf(absoluteUrl) || '/' },
    timeout: '30s',
  });
  recordCache(res);
  check(res, {
    'status 200': (r) => r.status === 200,
    'not 5xx': (r) => r.status < 500,
  });
  return res;
}

/** Warm URLs once in setup() without polluting cache_* rates. */
export function warmUrls(urls) {
  const reqs = urls.map((u) => ['GET', baseUrl() + pathOf(u), null, { headers: htmlHeaders(), tags: { name: 'warmup' } }]);
  http.batch(reqs);
}

export function pick(list) {
  return list[Math.floor(Math.random() * list.length)];
}

/** ~80% homepage + hot set, ~20% long-tail (falls back to hot if long-tail is empty). */
export function pickMixed(urls) {
  if (urls.longtail.length > 0 && Math.random() < 0.2) return pick(urls.longtail);
  return pick(urls.hot);
}

export function abortingFailureThreshold(rate = 0.01) {
  return [{ threshold: `rate<${rate}`, abortOnFail: true, delayAbortEval: '15s' }];
}

/**
 * Constant-arrival-rate steps tagged with rps=N so SLO thresholds can target 500
 * without failing the whole run when 1000/2000 are used to find the ceiling.
 */
export function htmlRateScenarios(stages, exec) {
  const scenarios = {};
  let start = 0;
  for (const s of stages) {
    const name = `rps_${s.rate}`;
    scenarios[name] = {
      executor: 'constant-arrival-rate',
      exec,
      rate: s.rate,
      timeUnit: '1s',
      duration: s.duration,
      startTime: `${start}s`,
      preAllocatedVUs: s.preAllocatedVUs || Math.min(400, Math.max(20, Math.ceil(s.rate * 0.1))),
      maxVUs: s.maxVUs || Math.min(1500, Math.max(80, s.rate)),
      tags: { rps: String(s.rate) },
      gracefulStop: '10s',
    };
    const m = String(s.duration).match(/^(\d+)(s|m)$/);
    start += m ? Number(m[1]) * (m[2] === 'm' ? 60 : 1) : 60;
  }
  return scenarios;
}

export const SLO_RAMP = [
  { rate: 50, duration: '30s' },
  { rate: 200, duration: '1m' },
  { rate: 500, duration: '1m' },
  { rate: 1000, duration: '1m' },
  { rate: 2000, duration: '1m' },
];
