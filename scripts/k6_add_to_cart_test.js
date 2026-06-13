// import http from 'k6/http';
// import { check, sleep } from 'k6';

// const dataFile = __ENV.K6_DATA_FILE || 'C:/xampp/htdocs/my-ecommerce-app/storage/app/k6-auth-data.json';
// const testData = JSON.parse(open(dataFile));
// const baseUrl = __ENV.BASE_URL || __ENV.K6_BASE_URL || testData.base_url || 'http://localhost';
// const password = testData.password;
// const quantity = Number(__ENV.QUANTITY || 1);

// export const options = {
//   vus: Number(__ENV.VUS || 5),
//   iterations: Number(__ENV.ITERATIONS || 20),
//   thresholds: {
//     http_req_duration: ['p(95)<1000'],
//   },
// };

// function jsonHeaders(token) {
//   return {
//     headers: {
//       'Content-Type': 'application/json',
//       Accept: 'application/json',
//       Authorization: `Bearer ${token}`,
//     },
//   };
// }

// export function setup() {
//   const usersWithTokens = testData.users.map((user) => {
//     const loginResponse = http.post(
//       `${baseUrl}/api/login`,
//       JSON.stringify({
//         email: user.email,
//         password,
//       }),
//       {
//         headers: {
//           'Content-Type': 'application/json',
//           Accept: 'application/json',
//         },
//       }
//     );

//     check(loginResponse, {
//       'login succeeded': (response) => response.status === 200,
//     });

//     return {
//       ...user,
//       token: loginResponse.json('token'),
//     };
//   });

//   return {
//     baseUrl,
//     products: testData.products,
//     users: usersWithTokens,
//   };
// }

// export default function (setupData) {
//   const user = setupData.users[(__VU - 1) % setupData.users.length];
//   const product = setupData.products[(__VU + __ITER) % setupData.products.length];

//   const addToCartResponse = http.post(
//     `${setupData.baseUrl}/api/cart/add`,
//     JSON.stringify({
//       product_id: product.id,
//       quantity,
//     }),
//     jsonHeaders(user.token)
//   );

//   check(addToCartResponse, {
//     'cart add succeeded': (response) => response.status === 201,
//   });

//   sleep(1);
// }
import http from 'k6/http';
import { check, sleep } from 'k6';

const dataFile = __ENV.K6_DATA_FILE || 'C:/xampp/htdocs/my-ecommerce-app/storage/app/k6-auth-data.json';
const testData = JSON.parse(open(dataFile));
const baseUrl = __ENV.BASE_URL || __ENV.K6_BASE_URL || testData.base_url || 'http://localhost';
const password = testData.password;
const quantity = Number(__ENV.QUANTITY || 1);

// =========================================================================
// ⚙️ التعديل هنا: جعل الاختبار مستمراً زمنياً ليعطيك فرصة لمراقبة الـ Web Dashboard
// =========================================================================
export const options = {
  vus: Number(__ENV.VUS || 30),         // رفعنا عدد المستخدمين المتزامنين (Threads) لـ 30 لتوليد ضغط حقيقي يظهر بالمنحنيات
  duration: '1m',                      // الفحص سيستمر مجبراً لمدة دقيقة كاملة (60 ثانية) بدلاً من عدد لفات منتهية
  thresholds: {
    http_req_duration: ['p(95)<1000'], // العتبة المطلوبة: 95% من الطلبات يجب أن تكون تحت الـ 1 ثانية
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
    products: testData.products,
    users: usersWithTokens,
  };
}

export default function (setupData) {
  const user = setupData.users[(__VU - 1) % setupData.users.length];
  const product = setupData.products[(__VU + __ITER) % setupData.products.length];

  const addToCartResponse = http.post(
    `${setupData.baseUrl}/api/cart/add`,
    JSON.stringify({
      product_id: product.id,
      quantity,
    }),
    jsonHeaders(user.token)
  );

  check(addToCartResponse, {
    'cart add succeeded': (response) => response.status === 201 || response.status === 200,
  });

//   sleep(1); // وقت محاكاة حركة المستخدم البشري بين الطلبات
}