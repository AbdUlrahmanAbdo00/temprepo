import http from 'k6/http';
import { check, sleep } from 'k6';

const dataFile = __ENV.K6_DATA_FILE || 'C:/xampp/htdocs/my-ecommerce-app/storage/app/k6-auth-data.json';
const testData = JSON.parse(open(dataFile));
const baseUrl = __ENV.BASE_URL || __ENV.K6_BASE_URL || testData.base_url || 'http://localhost';
const password = testData.password;

export const options = {
  vus: Number(__ENV.VUS || 5),
  iterations: Number(__ENV.ITERATIONS || 20),
  thresholds: {
    http_req_duration: ['p(95)<1000'],
  },
};

function jsonHeaders(token) {
  return {
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      Authorization: `Bearer ${token}`,
    },
  };
}

export function setup() {
  const usersWithTokens = testData.users.map((user) => {
    const loginResponse = http.post(
      `${baseUrl}/api/login`,
      JSON.stringify({
        email: user.email,
        password,
      }),
      {
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
      }
    );

    check(loginResponse, {
      'login succeeded': (response) => response.status === 200,
    });

    return {
      ...user,
      token: loginResponse.json('token'),
    };
  });

  return {
    baseUrl,
    users: usersWithTokens,
  };
}

export default function (setupData) {
  const user = setupData.users[(__VU - 1) % setupData.users.length];

  const checkoutResponse = http.post(
    `${setupData.baseUrl}/api/checkout`,
    null,
    jsonHeaders(user.token)
  );

  check(checkoutResponse, {
    'checkout succeeded': (response) => response.status === 201,
  });

  sleep(1);
}