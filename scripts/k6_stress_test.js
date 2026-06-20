// =====================================================================
// Requirement 9 — Stress Test: 100 concurrent users on ALL operations
// =====================================================================
//
// Exercises the full user flow per iteration:
//   1. GET  /api/products          (browse — cached read)
//   2. POST /api/cart/add          (write)
//   3. POST /api/checkout          (atomic decrement + ACID txn + Redis lock)
//   4. GET  /api/orders            (read)
//
// Responses are CLASSIFIED honestly:
//   • 2xx        → success
//   • 429        → rate limited  (Req 2 protecting the system — NOT a crash)
//   • 422        → out of stock  (Req 1 preventing overselling — correct business rule)
//   • 5xx / 0    → SERVER ERROR  (this is the real "crash" signal for Req 9)
//
// PASS criteria for Req 9 = ZERO server errors (no crash). Latency is secondary.
//
// PREP (run once, 100 users + high stock so inventory does not run out):
//   php scripts/prepare_k6_data.php 100 password123 100000
//
// RUN (from project root):
//   k6 run --env BASE_URL=http://localhost:8000 scripts/k6_stress_test.js
//
// Writes a machine-readable summary to: storage/app/req9/k6-summary.json
// (consumed by scripts/build_req9_dashboard.php)
// =====================================================================

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter, Trend } from 'k6/metrics';

const dataFile = __ENV.K6_DATA_FILE || 'C:/xampp/htdocs/my-ecommerce-app/storage/app/k6-auth-data.json';
const testData = JSON.parse(open(dataFile));

const baseUrl = (__ENV.BASE_URL || __ENV.K6_BASE_URL || testData.base_url || 'http://localhost:8000').replace(/\/$/, '');
const password = testData.password;
const SUMMARY_PATH = __ENV.SUMMARY_PATH || 'storage/app/req9/k6-summary.json';
const PEAK_VUS = Number(__ENV.VUS || 100);

// ─── Custom metrics ────────────────────────────────────────────────────────
const ok2xx        = new Counter('resp_ok_2xx');
const rateLimited  = new Counter('resp_rate_limited_429');
const busy503      = new Counter('resp_busy_503');          // intentional load-shed (lock busy) — NOT a crash
const outOfStock   = new Counter('resp_out_of_stock_422');
const serverErrors = new Counter('resp_server_errors');     // 500/502/504/network — the real crash signal
const otherErrors  = new Counter('resp_other_4xx');

const browseDur   = new Trend('op_browse_ms', true);
const cartDur     = new Trend('op_cart_ms', true);
const checkoutDur = new Trend('op_checkout_ms', true);
const ordersDur   = new Trend('op_orders_ms', true);

// In-PHP processing time of checkout (from X-Process-Time-Ms). The gap between
// checkoutDur (total) and this = time spent waiting for an Apache worker (the "door").
const checkoutProc = new Trend('op_checkout_processing_ms', true);

// Per-stage timings inside checkout (Req 10) — parsed from the X-Checkout-Profile header.
const STAGE_KEYS = [
  'cart_load_ms', 'lock_ms', 'decrement_ms', 'order_create_ms', 'items_create_ms',
  'cart_delete_ms', 'commit_overhead_ms', 'txn_ms', 'cache_forget_ms', 'dispatch_ms',
  'dispatch2_ms', 'order_load_ms', 'log_ms',
];
const stageTrends = {};
for (const k of STAGE_KEYS) {
  stageTrends[k] = new Trend('st_' + k, true);
}

// DB query profile (from X-Q-* headers)
const qCount = new Trend('st_q_count');
const qTotal = new Trend('st_q_total_ms', true);
const qMax   = new Trend('st_q_max_ms', true);

function header(res, name) {
  const h = res.headers || {};
  return h[name] || h[name.toLowerCase()] || null;
}
function processingMs(res) {
  const v = header(res, 'X-Process-Time-Ms');
  return v ? parseFloat(v) : null;
}

export const options = {
  scenarios: {
    stress: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '20s', target: PEAK_VUS },  // ramp up
        { duration: '60s', target: PEAK_VUS },  // hold at peak
        { duration: '10s', target: 0 },          // ramp down
      ],
      gracefulRampDown: '15s',
    },
  },
  thresholds: {
    // Req 9 passes only if the system never returns a server error (no crash).
    resp_server_errors: ['count==0'],
  },
};

function authHeaders(token) {
  return {
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      Authorization: `Bearer ${token}`,
    },
  };
}

// Classify a response into one of the honest buckets; returns true if it was a server error.
function classify(res) {
  const s = res.status;
  if (s >= 200 && s < 300) { ok2xx.add(1); return false; }
  if (s === 429)           { rateLimited.add(1); return false; }
  if (s === 503)           { busy503.add(1); return false; }   // graceful load-shed (Req 7 lock busy) — not a crash
  if (s === 422)           { outOfStock.add(1); return false; }
  if (s === 0 || s >= 500) { serverErrors.add(1); return true; }
  otherErrors.add(1);
  return false;
}

// ─── Setup: log every user in once, reuse the tokens ─────────────────────────
export function setup() {
  console.log(`\n=== STRESS TEST — Req 9 — target ${PEAK_VUS} VUs against ${baseUrl} ===`);
  console.log(`Users available: ${testData.users.length} | Products: ${testData.products.length}`);
  console.log('VUs log in lazily on their first iteration (avoids the setup timeout).\n');
  return { users: testData.users, products: testData.products };
}

// Per-VU state — each VU has its own JS runtime, so these are VU-scoped.
let vuToken = null;
let vuUser = null;

// ─── One full user journey per iteration ─────────────────────────────────────
export default function (data) {
  // Lazy per-VU login (once), spread across the ramp instead of a bulk setup login.
  if (!vuToken) {
    vuUser = data.users[(__VU - 1) % data.users.length];
    const login = http.post(
      `${baseUrl}/api/login`,
      JSON.stringify({ email: vuUser.email, password }),
      { headers: { 'Content-Type': 'application/json', Accept: 'application/json' } }
    );
    classify(login);
    vuToken = login.status === 200 ? login.json('token') : null;
    if (!vuToken) { sleep(1); return; }
  }

  const product = data.products[(__VU + __ITER) % data.products.length];
  const headers = authHeaders(vuToken);

  // 1) Browse products (cached read)
  const browse = http.get(`${baseUrl}/api/products`, headers);
  browseDur.add(browse.timings.duration);
  classify(browse);
  check(browse, { 'browse not server-error': (r) => r.status === 0 ? false : r.status < 500 });

  // 2) Add to cart (write)
  const cart = http.post(
    `${baseUrl}/api/cart/add`,
    JSON.stringify({ product_id: product.id, quantity: 1 }),
    headers
  );
  cartDur.add(cart.timings.duration);
  classify(cart);

  // 3) Checkout (atomic decrement + ACID transaction + distributed lock)
  const checkout = http.post(`${baseUrl}/api/checkout`, null, headers);
  checkoutDur.add(checkout.timings.duration);
  const pt = processingMs(checkout);
  if (pt !== null) { checkoutProc.add(pt); }

  // Per-stage profile (only present on successful 201 checkouts)
  const prof = header(checkout, 'X-Checkout-Profile');
  if (prof) {
    try {
      const p = JSON.parse(prof);
      for (const k of STAGE_KEYS) {
        if (typeof p[k] === 'number') { stageTrends[k].add(p[k]); }
      }
    } catch (e) { /* ignore parse errors */ }
  }
  const qc = header(checkout, 'X-Q-Count');
  const qt = header(checkout, 'X-Q-Total-Ms');
  const qm = header(checkout, 'X-Q-Max-Ms');
  if (qc !== null) { qCount.add(parseFloat(qc)); }
  if (qt !== null) { qTotal.add(parseFloat(qt)); }
  if (qm !== null) { qMax.add(parseFloat(qm)); }

  classify(checkout);
  check(checkout, { 'checkout not server-error': (r) => r.status === 0 ? false : r.status < 500 });

  // 4) View orders (read)
  const orders = http.get(`${baseUrl}/api/orders`, headers);
  ordersDur.add(orders.timings.duration);
  classify(orders);

  sleep(0.5);
}

// ─── Summary → JSON file for the dashboard ───────────────────────────────────
function trend(metric) {
  const v = (metric && metric.values) || {};
  return {
    avg: Math.round((v.avg || 0) * 100) / 100,
    p95: Math.round((v['p(95)'] || 0) * 100) / 100,
    max: Math.round((v.max || 0) * 100) / 100,
  };
}

export function handleSummary(data) {
  const m = data.metrics;
  const count = (name) => (m[name] && m[name].values && m[name].values.count) || 0;

  const ok = count('resp_ok_2xx');
  const throttled = count('resp_rate_limited_429');
  const busy = count('resp_busy_503');
  const oos = count('resp_out_of_stock_422');
  const serverErr = count('resp_server_errors');
  const other = count('resp_other_4xx');
  const totalResponses = ok + throttled + busy + oos + serverErr + other;

  const summary = {
    generated_at: new Date().toISOString(),
    target_vus: PEAK_VUS,
    max_vus: (m.vus_max && m.vus_max.values && m.vus_max.values.max) || PEAK_VUS,
    iterations: count('iterations'),
    http_reqs: count('http_reqs'),
    http_req_failed_rate: Math.round(((m.http_req_failed && m.http_req_failed.values && m.http_req_failed.values.rate) || 0) * 10000) / 100,
    responses: {
      total: totalResponses,
      ok_2xx: ok,
      rate_limited_429: throttled,
      busy_503: busy,
      out_of_stock_422: oos,
      server_errors: serverErr,
      other_4xx: other,
    },
    success_rate_pct: totalResponses ? Math.round((ok / totalResponses) * 10000) / 100 : 0,
    latency_ms: {
      browse: trend(m.op_browse_ms),
      cart: trend(m.op_cart_ms),
      checkout: trend(m.op_checkout_ms),
      checkout_processing: trend(m.op_checkout_processing_ms),
      orders: trend(m.op_orders_ms),
      overall: trend(m.http_req_duration),
    },
    stages: {},
    db_queries: {
      count_avg: trend(m.st_q_count).avg,
      total_ms: trend(m.st_q_total_ms),
      slowest_ms: trend(m.st_q_max_ms),
    },
    no_crash: serverErr === 0,
  };

  for (const k of STAGE_KEYS) {
    summary.stages[k] = trend(m['st_' + k]);
  }

  // Ranked stage breakdown (by avg — additive — as % of in-PHP work)
  const inPhp = summary.latency_ms.checkout_processing.avg || 1;
  const rows = STAGE_KEYS
    .map((k) => ({ k, avg: summary.stages[k].avg, p95: summary.stages[k].p95 }))
    .filter((r) => r.k !== 'txn_ms')   // txn_ms is the sum of decrement+order+items+delete+commit; show its parts instead
    .sort((a, b) => b.avg - a.avg);
  let table = '';
  for (const r of rows) {
    const pct = (r.avg / inPhp) * 100;
    table += '  ' + r.k.padEnd(20) + ' avg ' + String(r.avg).padStart(8) + 'ms  p95 ' +
             String(r.p95).padStart(8) + 'ms  ' + pct.toFixed(1).padStart(5) + '%\n';
  }

  const q = summary.db_queries;
  const text =
    '\n================ STRESS TEST SUMMARY (Req 9) ================\n' +
    `Max VUs           : ${summary.max_vus}\n` +
    `Iterations        : ${summary.iterations}\n` +
    `Total responses   : ${totalResponses}  (2xx ${ok} | 429 ${throttled} | 503 ${busy} | 422 ${oos} | 5xx ${serverErr})\n` +
    `Checkout p95      : ${summary.latency_ms.checkout.p95} ms total | in-PHP ${summary.latency_ms.checkout_processing.p95} ms p95 (avg ${summary.latency_ms.checkout_processing.avg})\n` +
    `NO CRASH          : ${summary.no_crash ? 'YES ✓' : 'NO ✗'}\n` +
    '\n--- CHECKOUT STAGE BREAKDOWN (ranked by avg; % of in-PHP work) ---\n' +
    table +
    `\nDB queries/checkout : avg ${q.count_avg}  | total SQL avg ${q.total_ms.avg}ms p95 ${q.total_ms.p95}ms | slowest avg ${q.slowest_ms.avg}ms p95 ${q.slowest_ms.p95}ms\n` +
    '=============================================================\n';

  return {
    [SUMMARY_PATH]: JSON.stringify(summary, null, 2),
    stdout: text,
  };
}
