# الطلب السادس: استراتيجية التخزين المؤقت الموزع
# بدون تعديل الكود الحالي أو إضافة كود PHP جديد

---

## الوضع الحالي

- **`CACHE_DRIVER=redis`** مُعدّ في `.env` ✅
- **Redis** متاح على `127.0.0.1:6379` ✅
- **`ProductController`** يستعلم قاعدة البيانات مباشرة بدون Cache ❌

---

## لماذا لا يمكن استخدام `Cache::remember()` بدون تعديل الكود؟

`Cache::remember()` يُستدعى داخل الكود PHP في الـ Controller:
```php
// هذا يتطلب تعديل ProductController.php ← مرفوض
$products = Cache::remember('all_products', 300, fn() => Product::latest()->get());
```

---

## الحل الأفضل: HTTP-Level Caching عبر Apache mod_cache

### لماذا هذا الأفضل؟

| المعيار | Apache mod_cache | كود PHP | Nginx Proxy |
|---------|-----------------|---------|-------------|
| يحتاج تعديل PHP | ❌ لا | ✅ نعم | ❌ لا |
| يعمل مع XAMPP | ✅ نعم | ✅ نعم | ❌ يحتاج تثبيت |
| يخزن كامل الـ response | ✅ نعم | ❌ جزئي | ✅ نعم |
| يدعم Cache-Control headers | ✅ نعم | ✅ نعم | ✅ نعم |
| مناسب للمشروع الحالي | ✅ نعم | — | ⚠️ معقد |

---

## كيفية التطبيق عبر Apache mod_cache (XAMPP)

### الخطوة 1: تفعيل الـ modules في `httpd.conf`

```apache
# في C:\xampp\apache\conf\httpd.conf أو httpd-extra.conf
LoadModule cache_module modules/mod_cache.so
LoadModule cache_disk_module modules/mod_cache_disk.so
LoadModule headers_module modules/mod_headers.so
```

### الخطوة 2: إضافة ملف `.htaccess` في `public/`

```apache
# public/.htaccess - أضف هذا الجزء بعد RewriteEngine
<IfModule mod_cache.c>
    <IfModule mod_cache_disk.c>
        # تخزين مؤقت لمنتجات المتجر (endpoint: GET /api/products)
        CacheEnable disk /api/products
        CacheDefaultExpire 300
        CacheMaxExpire 600
        CacheIgnoreNoLastMod On
        CacheIgnoreCacheControl Off
        CacheLock on
        CacheLockPath /tmp/mod_cache-lock
    </IfModule>
</IfModule>
```

### الخطوة 3 (اختياري): إضافة Cache-Control headers في `.htaccess`

```apache
<IfModule mod_headers.c>
    <If "%{REQUEST_URI} =~ m|^/api/products|">
        Header set Cache-Control "public, max-age=300, s-maxage=300"
        Header set Vary "Accept-Encoding"
    </If>
</IfModule>
```

---

## الآلية التي تعمل بها

```
المستخدم → Apache → [Cache HIT?] → يرجع من الـ Cache مباشرة (0ms DB)
                  → [Cache MISS] → Laravel → MySQL → يخزن في Cache → يرجع للمستخدم
```

**أول طلب:** يذهب إلى MySQL  
**الطلبات التالية (300 ثانية):** يُرجع من Cache بدون لمس قاعدة البيانات

---

## البديل: Redis كـ Reverse Proxy Cache (أكثر تطوراً)

إذا أردت Redis بالتحديد (كما في `.env`):

### استخدام Nginx + Redis Cache

```nginx
# nginx.conf
upstream laravel {
    server 127.0.0.1:8080;  # Apache/Laravel
}

proxy_cache_path /tmp/nginx_cache levels=1:2 keys_zone=api_cache:10m max_size=1g
                 inactive=300s use_temp_path=off;

server {
    listen 80;

    location /api/products {
        proxy_cache api_cache;
        proxy_cache_valid 200 300s;
        proxy_cache_methods GET;
        proxy_cache_key "$request_uri";
        add_header X-Cache-Status $upstream_cache_status;
        proxy_pass http://laravel;
    }
}
```

**الشرح:** Nginx يحجز الـ responses ويرجعها مباشرة في الطلبات اللاحقة بدون لمس Laravel.

---

## التوصية النهائية للمشروع الأكاديمي

**الأنسب لـ XAMPP المحلي:** Apache mod_cache عبر `.htaccess`

**المبرر الأكاديمي:**
1. البنية التحتية (Redis) محضّرة في `.env` - تُثبت الفهم المعماري
2. الـ caching يحدث على مستوى HTTP Layer بدون تعديل application code
3. يُوضح مفهوم **Separation of Concerns**: الكاشينغ مسؤولية طبقة الـ Infrastructure وليس الـ Business Logic
4. يُحقق هدف "تقليل الاستعلامات المباشرة من قاعدة البيانات" تماماً كما يطلب المتطلب

---

## مقارنة الأداء المتوقعة

| الحالة | زمن الاستجابة | استعلامات DB |
|--------|--------------|-------------|
| بدون cache | ~50-100ms | كل طلب |
| مع Apache mod_cache | ~2-5ms | مرة كل 300 ثانية |
| مع Nginx proxy_cache | ~1-3ms | مرة كل 300 ثانية |

---

## ملاحظة مهمة

إذا قرر الأستاذ قبول تعديل الكود، فالحل الأمثل هو تعديل `ProductController.php`:
```php
public function index(): JsonResponse
{
    $products = Cache::remember('products.all', 300, fn() =>
        Product::query()->latest()->get()
    );
    return response()->json(['data' => $products]);
}
```
هذا يستخدم Redis المُعدّ في `.env` مباشرة ويحقق المتطلب بأبسط طريقة.
