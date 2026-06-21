// =====================================================================
// Requirement 9 — Stress Test: 100 concurrent users on ALL operations
// =====================================================================
//
// Full user journey per iteration:
//   1. GET  /api/products   (browse — cached read)
//   2. POST /api/cart/add   (write)
//   3. POST /api/checkout   (atomic decrement + ACID txn + Redis lock)
//   4. GET  /api/orders     (read)
//
// Product tiers (set by StressTestSeeder):
//   abundant (70% weight) — stock=100,000 → always succeeds
//   scarce   (20% weight) — stock=20      → depletes mid-test → 422
//   hot      (10% weight) — stock=5       → depletes quickly  → 422
//
// Response classification (honest):
//   2xx  → success
//   429  → rate-limited      (Req 2 protecting the system — not a crash)
//   503  → lock busy         (Req 7 graceful load-shed; retried once)
//   422  → out of stock      (Req 1 preventing overselling — correct)
//   5xx  → SERVER ERROR      ← the real crash signal (must be 0)
//
// PASS criteria = ZERO server errors (5xx/network).
//
// PREP (run once):
//   php artisan db:seed --class=StressTestSeeder
//   php artisan config:cache
//
// RUN (from project root, on Apache — NOT artisan serve):
//   k6 run --env BASE_URL=http://localhost:8000 scripts/k6_stress_test.js
//
// Output: storage/app/req9/k6-summary.json
// =====================================================================

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter, Trend } from 'k6/metrics';

const dataFile = __ENV.K6_DATA_FILE || 'C:/xampp/htdocs/my-ecommerce-app/storage/app/k6-auth-data.json';
const testData = JSON.parse(open(dataFile));

const baseUrl  = (__ENV.BASE_URL || __ENV.K6_BASE_URL || testData.base_url || 'http://localhost:8000').replace(/\/$/, '');
const password = testData.password;
const SUMMARY_PATH = __ENV.SUMMARY_PATH || 'storage/app/req9/k6-summary.json';
const PEAK_VUS     = Number(__ENV.VUS || 100);
const REQUEST_TIMEOUT = '120s';

// ── Product tier arrays (module-level, read once from JSON) ────────────────
const abundantProducts = (testData.products || []).filter(p => p.type === 'abundant');
const scarceProducts   = (testData.products || []).filter(p => p.type === 'scarce');
const hotProducts      = (testData.products || []).filter(p => p.type === 'hot');

// Weighted deterministic product selection:
//   bucket = (vu*13 + iter*7) % 10
//   0      → hot     (10%)
//   1-2    → scarce  (20%)
//   3-9    → abundant(70%)
// Using prime multipliers avoids repeating patterns across VUs.
function pickProduct(vu, iter) {
  const bucket = ((vu * 13 + iter * 7) % 10 + 10) % 10;
  if (bucket < 1 && hotProducts.length > 0) {
    return hotProducts[(vu + iter * 3) % hotProducts.length];
  }
  if (bucket < 3 && scarceProducts.length > 0) {
    return scarceProducts[(vu * 2 + iter) % scarceProducts.length];
  }
  return abundantProducts[(vu + iter * 5) % abundantProducts.length];
}

// ─── Custom metrics ────────────────────────────────────────────────────────
const ok2xx           = new Counter('resp_ok_2xx');
const rateLimited     = new Counter('resp_rate_limited_429');
const busy503         = new Counter('resp_busy_503');
const outOfStock422   = new Counter('resp_out_of_stock_422');
const serverErrors    = new Counter('resp_server_errors');
const otherErrors     = new Counter('resp_other_4xx');
const retriedCheckout = new Counter('checkout_retried_503');  // 503 → retried once

const browseDur   = new Trend('op_browse_ms',   true);
const cartDur     = new Trend('op_cart_ms',     true);
const checkoutDur = new Trend('op_checkout_ms', true);
const ordersDur   = new Trend('op_orders_ms',   true);

const checkoutProc = new Trend('op_checkout_processing_ms', true);

const STAGE_KEYS = [
  'cart_load_ms', 'lock_ms', 'decrement_ms', 'order_create_ms', 'items_create_ms',
  'cart_delete_ms', 'commit_overhead_ms', 'txn_ms', 'cache_forget_ms', 'dispatch_ms',
  'dispatch2_ms', 'order_load_ms', 'log_ms',
];
const stageTrends = {};
for (const k of STAGE_KEYS) {
  stageTrends[k] = new Trend('st_' + k, true);
}

const qCount = new Trend('st_q_count');
const qTotal = new Trend('st_q_total_ms', true);
const qMax   = new Trend('st_q_max_ms',   true);

// ─── Helpers ───────────────────────────────────────────────────────────────
function header(res, name) {
  const h = res.headers || {};
  return h[name] || h[name.toLowerCase()] || null;
}
function processingMs(res) {
  const v = header(res, 'X-Process-Time-Ms');
  return v ? parseFloat(v) : null;
}

function authHeaders(token) {
  return {
    timeout: REQUEST_TIMEOUT,
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      Authorization: `Bearer ${token}`,
    },
  };
}

// Classify into honest buckets; returns true only on real server error.
function classify(res) {
  const s = res.status;
  if (s >= 200 && s < 300) { ok2xx.add(1);         return false; }
  if (s === 429)            { rateLimited.add(1);   return false; }
  if (s === 503)            { busy503.add(1);        return false; }
  if (s === 422)            { outOfStock422.add(1); return false; }
  if (s === 0 || s >= 500)  { serverErrors.add(1);  return true;  }
  otherErrors.add(1);
  return false;
}

// ─── k6 options ────────────────────────────────────────────────────────────
export const options = {
  scenarios: {
    stress: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '20s', target: PEAK_VUS },
        { duration: '60s', target: PEAK_VUS },
        { duration: '10s', target: 0 },
      ],
      gracefulRampDown: '15s',
    },
  },
  thresholds: {
    resp_server_errors: ['count==0'],
  },
};

// ─── Setup ─────────────────────────────────────────────────────────────────
export function setup() {
  console.log(`\n=== STRESS TEST — Req 9 — target ${PEAK_VUS} VUs against ${baseUrl} ===`);
  console.log(`Users: ${testData.users.length} | Products: ${testData.products.length} (abundant:${abundantProducts.length} scarce:${scarceProducts.length} hot:${hotProducts.length})`);
  console.log('VUs log in lazily on their first iteration.\n');
  return { users: testData.users };
}

// ─── Per-VU state ──────────────────────────────────────────────────────────
let vuToken = null;
let vuUser  = null;

// ─── One full user journey per iteration ───────────────────────────────────
export default function (data) {
  // Lazy per-VU login — runs once, spread across ramp-up.
  if (!vuToken) {
    vuUser = data.users[(__VU - 1) % data.users.length];
    const login = http.post(
      `${baseUrl}/api/login`,
      JSON.stringify({ email: vuUser.email, password }),
      { timeout: REQUEST_TIMEOUT, headers: { 'Content-Type': 'application/json', Accept: 'application/json' } }
    );
    classify(login);
    vuToken = login.status === 200 ? login.json('token') : null;
    if (!vuToken) { sleep(1); return; }
  }

  // Weighted product selection: 70% abundant / 20% scarce / 10% hot.
  const product = pickProduct(__VU, __ITER);
  const headers = authHeaders(vuToken);

  // 1) Browse products (cached read)
  const browse = http.get(`${baseUrl}/api/products`, headers);
  browseDur.add(browse.timings.duration);
  classify(browse);
  check(browse, { 'browse not server-error': (r) => r.status > 0 && r.status < 500 });

  // 2) Add to cart
  const cart = http.post(
    `${baseUrl}/api/cart/add`,
    JSON.stringify({ product_id: product.id, quantity: 1 }),
    headers
  );
  cartDur.add(cart.timings.duration);
  classify(cart);

  // 3) Checkout — with one retry on 503 (lock busy).
  //    A 503 means the Redis lock was held; waiting 200ms usually frees it.
  let checkout = http.post(`${baseUrl}/api/checkout`, null, headers);
  if (checkout.status === 503) {
    retriedCheckout.add(1);
    sleep(0.2);
    checkout = http.post(`${baseUrl}/api/checkout`, null, headers);
  }

  checkoutDur.add(checkout.timings.duration);
  const pt = processingMs(checkout);
  if (pt !== null) { checkoutProc.add(pt); }

  // Per-stage profile (only on 201)
  const prof = header(checkout, 'X-Checkout-Profile');
  if (prof) {
    try {
      const p = JSON.parse(prof);
      for (const k of STAGE_KEYS) {
        if (typeof p[k] === 'number') { stageTrends[k].add(p[k]); }
      }
    } catch (e) { /* ignore */ }
  }
  const qc = header(checkout, 'X-Q-Count');
  const qt = header(checkout, 'X-Q-Total-Ms');
  const qm = header(checkout, 'X-Q-Max-Ms');
  if (qc !== null) { qCount.add(parseFloat(qc)); }
  if (qt !== null) { qTotal.add(parseFloat(qt)); }
  if (qm !== null) { qMax.add(parseFloat(qm)); }

  classify(checkout);
  check(checkout, { 'checkout not server-error': (r) => r.status > 0 && r.status < 500 });

  // 4) View orders
  const orders = http.get(`${baseUrl}/api/orders`, headers);
  ordersDur.add(orders.timings.duration);
  classify(orders);

  sleep(0.5);
}

// ─── Summary ───────────────────────────────────────────────────────────────
function trend(metric) {
  const v = (metric && metric.values) || {};
  return {
    avg: Math.round((v.avg         || 0) * 100) / 100,
    p95: Math.round((v['p(95)']    || 0) * 100) / 100,
    max: Math.round((v.max         || 0) * 100) / 100,
  };
}

export function handleSummary(data) {
  const m     = data.metrics;
  const count = (name) => (m[name] && m[name].values && m[name].values.count) || 0;

  const ok        = count('resp_ok_2xx');
  const throttled = count('resp_rate_limited_429');
  const busy      = count('resp_busy_503');
  const oos       = count('resp_out_of_stock_422');
  const srvErr    = count('resp_server_errors');
  const other     = count('resp_other_4xx');
  const retried   = count('checkout_retried_503');
  const total     = ok + throttled + busy + oos + srvErr + other;

  const summary = {
    generated_at: new Date().toISOString(),
    target_vus:   PEAK_VUS,
    max_vus:      (m.vus_max && m.vus_max.values && m.vus_max.values.max) || PEAK_VUS,
    iterations:   count('iterations'),
    http_reqs:    count('http_reqs'),
    responses: {
      total:             total,
      ok_2xx:            ok,
      rate_limited_429:  throttled,
      busy_503:          busy,
      out_of_stock_422:  oos,
      server_errors:     srvErr,
      other_4xx:         other,
      checkout_retried:  retried,
    },
    success_rate_pct: total ? Math.round((ok / total) * 10000) / 100 : 0,
    latency_ms: {
      browse:              trend(m.op_browse_ms),
      cart:                trend(m.op_cart_ms),
      checkout:            trend(m.op_checkout_ms),
      checkout_processing: trend(m.op_checkout_processing_ms),
      orders:              trend(m.op_orders_ms),
      overall:             trend(m.http_req_duration),
    },
    stages: {},
    db_queries: {
      count_avg:  trend(m.st_q_count).avg,
      total_ms:   trend(m.st_q_total_ms),
      slowest_ms: trend(m.st_q_max_ms),
    },
    product_weights: { abundant: '70%', scarce: '20%', hot: '10%' },
    no_crash: srvErr === 0,
  };

  for (const k of STAGE_KEYS) {
    summary.stages[k] = trend(m['st_' + k]);
  }

  // Stage breakdown table
  const inPhp = summary.latency_ms.checkout_processing.avg || 1;
  const rows  = STAGE_KEYS
    .filter(k => k !== 'txn_ms')
    .map(k => ({ k, avg: summary.stages[k].avg, p95: summary.stages[k].p95 }))
    .sort((a, b) => b.avg - a.avg);
  let table = '';
  for (const r of rows) {
    const pct = (r.avg / inPhp) * 100;
    table += '  ' + r.k.padEnd(22) + ' avg ' + String(r.avg).padStart(8) + 'ms  p95 '
           + String(r.p95).padStart(8) + 'ms  ' + pct.toFixed(1).padStart(5) + '%\n';
  }

  const q    = summary.db_queries;
  const text =
    '\n================ STRESS TEST SUMMARY (Req 9) ================\n' +
    `Max VUs            : ${summary.max_vus}\n` +
    `Iterations         : ${summary.iterations}\n` +
    `Total responses    : ${total}  (2xx ${ok} | 429 ${throttled} | 503 ${busy} | 422 ${oos} | 5xx ${srvErr})\n` +
    `Checkout retried   : ${retried}  (503 → waited 200ms → retried)\n` +
    `Success rate       : ${summary.success_rate_pct}%\n` +
    `Checkout p95       : ${summary.latency_ms.checkout.p95}ms total | in-PHP ${summary.latency_ms.checkout_processing.p95}ms p95 (avg ${summary.latency_ms.checkout_processing.avg})\n` +
    `NO CRASH           : ${summary.no_crash ? 'YES ✓' : 'NO ✗'}\n` +
    '\n--- CHECKOUT STAGE BREAKDOWN (ranked by avg; % of in-PHP work) ---\n' +
    table +
    `\nDB queries/checkout : avg ${q.count_avg}  | total SQL avg ${q.total_ms.avg}ms p95 ${q.total_ms.p95}ms | slowest avg ${q.slowest_ms.avg}ms\n` +
    '=============================================================\n';

  return {
    [SUMMARY_PATH]: JSON.stringify(summary, null, 2),
    stdout: text,
  };
}
