/**
 * Requirement 6 — Distributed Caching (Redis) — k6 Benchmark
 *
 * Measures the real HTTP response-time difference between:
 *   • Cold run  → CACHE_DRIVER=array  (every request hits the DB)
 *   • Warm run  → CACHE_DRIVER=redis  (only the first request per key hits the DB)
 *
 * HOW TO RUN:
 *
 *   Step 1 — WITHOUT cache (set in .env: CACHE_DRIVER=array)
 *     php artisan config:clear
 *     k6 run scripts/k6_cache_benchmark.js 2>&1 | tee before_cache.txt
 *
 *   Step 2 — WITH Redis cache (set in .env: CACHE_DRIVER=redis)
 *     php artisan config:clear
 *     k6 run scripts/k6_cache_benchmark.js 2>&1 | tee after_cache.txt
 *
 *   Compare before_cache.txt vs after_cache.txt for the before/after numbers.
 *
 * ENV overrides:
 *   BASE_URL      — default: http://localhost:8000  (Apache VirtualHost للمشروع)
 *   AUTH_EMAIL    — default: user1@test.com
 *   AUTH_PASSWORD — default: password123
 *   VUS           — default: 50
 *   DURATION      — default: 30s
 *
 * Product IDs are fetched automatically from GET /api/products during setup.
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend, Counter } from 'k6/metrics';

// ─── Custom Metrics ───────────────────────────────────────────────────────────
const listingDuration = new Trend('cache_listing_duration', true);   // ms
const productDuration  = new Trend('cache_product_duration',  true);  // ms
const successRate      = new Rate('cache_success_rate');
const requestsTotal    = new Counter('cache_requests_total');

// ─── Config ───────────────────────────────────────────────────────────────────
const BASE_URL  = (__ENV.BASE_URL      || 'http://localhost:8000').replace(/\/$/, '');
const EMAIL     = __ENV.AUTH_EMAIL     || 'user1@test.com';
const PASSWORD  = __ENV.AUTH_PASSWORD  || 'password123';
const VUS       = parseInt(__ENV.VUS      || '10', 10);
const DURATION  = __ENV.DURATION          || '30s';

// ─── k6 Options ───────────────────────────────────────────────────────────────
export const options = {
  stages: [
    { duration: '10s', target: VUS },   // ramp up
    { duration: DURATION, target: VUS }, // steady load
    { duration: '5s',  target: 0 },     // ramp down
  ],
  thresholds: {
    // These thresholds are intentionally loose — run TWICE and compare numbers
    http_req_failed:          ['rate<0.05'],
    cache_listing_duration:   ['p(95)<5000'],
    cache_product_duration:   ['p(95)<5000'],
    cache_success_rate:       ['rate>0.95'],
  },
};

// ─── Helpers ─────────────────────────────────────────────────────────────────
function jsonHeaders(token) {
  const h = { 'Accept': 'application/json', 'Content-Type': 'application/json' };
  if (token) h['Authorization'] = `Bearer ${token}`;
  return h;
}

function tryLogin(email, password) {
  const res = http.post(
    `${BASE_URL}/api/login`,
    JSON.stringify({ email, password }),
    { headers: jsonHeaders() }
  );
  const body = (() => { try { return res.json(); } catch { return null; } })();
  return (res.status === 200 && body && body.token) ? body.token : null;
}

function tryRegister(email, password) {
  const res = http.post(
    `${BASE_URL}/api/register`,
    JSON.stringify({ name: 'Cache Test User', email, password, password_confirmation: password }),
    { headers: jsonHeaders() }
  );
  const body = (() => { try { return res.json(); } catch { return null; } })();
  return (res.status === 201 && body && body.token) ? body.token : null;
}

// ─── Setup — runs once before all VUs start ───────────────────────────────────
export function setup() {
  console.log(`\n========================================`);
  console.log(`  CACHE BENCHMARK — Requirement 6`);
  console.log(`  Target : ${BASE_URL}`);
  console.log(`  VUs    : ${VUS}   Duration: ${DURATION}`);
  console.log(`========================================\n`);

  // Try to authenticate with the provided credentials
  let token = tryLogin(EMAIL, PASSWORD);

  // If login fails, register a fresh user
  if (!token) {
    console.log(`Login failed for ${EMAIL} — registering new user...`);
    const unique = `cache-bench-${Date.now()}@test.local`;
    token = tryRegister(unique, PASSWORD);
  }

  if (!token) {
    throw new Error('Could not obtain auth token. Make sure the server is running.');
  }

  // Fetch product list to get real IDs from DB
  const productsRes = http.get(`${BASE_URL}/api/products`, { headers: jsonHeaders(token) });
  let productIds = [1];
  try {
    const products = productsRes.json('data');
    if (Array.isArray(products) && products.length > 0) {
      productIds = products.map(p => p.id);
    }
  } catch (e) {
    console.log('Could not fetch product IDs from API, falling back to ID=1');
  }

  console.log(`Auth token obtained. Found ${productIds.length} products. Starting benchmark...\n`);
  return { token, productIds };
}

// ─── Default VU function ──────────────────────────────────────────────────────
export default function (data) {
  const headers = jsonHeaders(data.token);

  // ── 1. Products Listing (GET /api/products) ──────────────────────────────
  const listingRes = http.get(`${BASE_URL}/api/products`, { headers, tags: { endpoint: 'listing' } });

  const listingOk = check(listingRes, {
    'listing: status 200':       (r) => r.status === 200,
    'listing: has data field':   (r) => { try { return Array.isArray(r.json('data')); } catch { return false; } },
  });

  listingDuration.add(listingRes.timings.duration);
  successRate.add(listingOk ? 1 : 0);
  requestsTotal.add(1);

  sleep(0.1);

  // ── 2. Individual Product (GET /api/products/{id}) ───────────────────────
  // Pick a random product ID from the list fetched during setup
  const randomId = data.productIds[Math.floor(Math.random() * data.productIds.length)];
  const productRes = http.get(
    `${BASE_URL}/api/products/${randomId}`,
    { headers, tags: { endpoint: 'product' } }
  );

  const productOk = check(productRes, {
    'product: status 200':     (r) => r.status === 200,
    'product: has data field': (r) => { try { return !!r.json('data'); } catch { return false; } },
  });

  productDuration.add(productRes.timings.duration);
  successRate.add(productOk ? 1 : 0);
  requestsTotal.add(1);

  sleep(0.1);
}

// ─── Teardown — summary printed after all VUs finish ─────────────────────────
export function teardown() {
  console.log(`\n========================================`);
  console.log(`  Benchmark complete.`);
  console.log(`  Check the Trends above:`);
  console.log(`    cache_listing_duration → avg/p95 for GET /api/products`);
  console.log(`    cache_product_duration → avg/p95 for GET /api/products/{id}`);
  console.log(`\n  Run TWICE to compare:`);
  console.log(`    1st run  → CACHE_DRIVER=array  (no cache)`);
  console.log(`    2nd run  → CACHE_DRIVER=redis  (cache warm)`);
  console.log(`========================================\n`);
}
