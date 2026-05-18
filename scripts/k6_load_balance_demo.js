import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter } from 'k6/metrics';

export const options = {
  vus: 20,
  duration: '10s',
  thresholds: {
    http_req_failed: ['rate<0.01'],
    http_req_duration: ['p(95)<1000'],
  },
};

const apacheHits = new Counter('apache_hits');
const artisanHits = new Counter('artisan_hits');

function detectServerType(payload) {
  const sapi = String(payload.php_sapi || '').toLowerCase();
  const software = String(payload.server_software || '').toLowerCase();

  if (sapi.includes('cli-server') || software.includes('artisan') || software.includes('php development server')) {
    return 'artisan';
  }

  return 'apache';
}

export default function () {
  const baseUrl = __ENV.BASE_URL || 'http://my-ecommerce-app.test';
  const response = http.get(`${baseUrl}/server-info`, {
    headers: {
      'Cache-Control': 'no-cache',
      'Pragma': 'no-cache',
    },
  });

  check(response, {
    'status is 200': (r) => r.status === 200,
    'response has php_sapi': (r) => !!r.json('php_sapi'),
  });

  const payload = response.json();
  const serverType = detectServerType(payload);
  const pid = payload.process_id ?? 'unknown';
  const sapi = payload.php_sapi ?? 'unknown';
  const port = payload.server_port ?? 'unknown';

  if (serverType === 'artisan') {
    artisanHits.add(1);
  } else {
    apacheHits.add(1);
  }

  console.log(`[VU ${__VU} | ITER ${__ITER}] ${serverType.toUpperCase()} | sapi=${sapi} | pid=${pid} | port=${port}`);
  sleep(0.2);
}

export function handleSummary(data) {
  return {
    stdout: [
      '\n================ LOAD BALANCING SUMMARY ================\n',
      `Requests: ${data.metrics.http_reqs.count}\n`,
      `Apache hits: ${data.metrics.apache_hits?.count || 0}\n`,
      `Artisan hits: ${data.metrics.artisan_hits?.count || 0}\n`,
      `Failed requests: ${data.metrics.http_req_failed?.passes || 0}\n`,
      '========================================================\n',
    ].join(''),
  };
}
