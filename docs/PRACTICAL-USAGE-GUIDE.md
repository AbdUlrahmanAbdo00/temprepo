# دليل العمل العملي - Requests 2, 3, 4

**هذا الملف يشرح خطوات عملية لتشغيل واختبار الحل بنجاح**

---

## 🚀 خطوات التشغيل الأولية:

### 1. التحقق من الملفات المهمة:

```bash
# تأكد من وجود هذه الملفات:
ls -la app/Jobs/ProcessOrderJob.php
ls -la database/migrations/2026_05_18_000000_create_jobs_table.php
ls -la tests/Feature/*VerificationTest.php
```

### 2. تحديث متغيرات البيئة:

```bash
# نسخ .env.example إلى .env إذا لم تكن موجودة
cp .env.example .env

# تحقق من وجود هذه المتغيرات في .env:
grep "QUEUE_CONNECTION" .env
grep "APACHE_THREADS" .env
```

### 3. تشغيل الـ Migration:

```bash
php artisan migrate --force
```

✅ **النتيجة المتوقعة:**
```
Nothing to migrate.
✓ 2026_05_18_000000_create_jobs_table .......... 107ms DONE
```

### 4. التحقق من قاعدة البيانات:

```bash
php artisan tinker

# داخل tinker:
>>> DB::table('jobs')->count()
=> 0

>>> exit()
```

---

## ✅ الطلب 2: اختبر Rate Limiting

### الاختبار اليدوي:

```bash
# 1. ابدأ اختبار معدل الطلبات على auth endpoint
for i in {1..10}; do
  curl -X POST http://localhost:8000/api/register \
    -H "Content-Type: application/json" \
    -d '{
      "name": "User'$i'",
      "email": "user'$i'@test.com",
      "password": "password123",
      "password_confirmation": "password123"
    }' &
  sleep 0.1
done

# 2. ستلاحظ أن بعض الطلبات ستُرجع 201 والبعض الآخر 429 (Too Many Requests)
```

### الاختبار عبر PHPUnit:

```bash
php artisan test --filter=RateLimitingVerificationTest

# النتيجة المتوقعة:
# ✓ auth rate limiting configured
# ✓ checkout endpoint responds
# ✓ rate limiting respects apache configuration
# 3 tests, 9 assertions
```

---

## 🔄 الطلب 3: اختبر Async Queues

### الاختبار الأول - التحقق من Dispatch:

```bash
php artisan test --filter=CheckoutDispatchTest

# النتيجة المتوقعة:
# ✓ checkout dispatches summary job and returns success
```

### الاختبار الثاني - معالجة الـ Jobs:

```bash
# 1. تحقق من جدول الـ jobs (يجب أن يكون فارغًا الآن):
php artisan tinker
>>> DB::table('jobs')->count()
=> 0

# 2. شغّل مسار checkout (إنشاء طلب):
curl -X POST http://localhost:8000/api/checkout \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json"

# 3. تحقق من جدول الـ jobs (يجب أن يحتوي على entries الآن):
>>> DB::table('jobs')->count()
=> 2  # ProcessOrderJob + GenerateOrderSummaryJob

# 4. اعرض محتويات الـ jobs:
>>> DB::table('jobs')->pluck('queue', 'id')
=> Collection {
     "orders" => "orders",  // ProcessOrderJob
     "orders" => "orders"   // GenerateOrderSummaryJob
   }
```

### تشغيل Queue Worker:

```bash
# شغّل worker واحد:
php artisan queue:work database --sleep=3 --tries=3

# أو شغّل 5 workers في الخلفية:
for i in {1..5}; do
  php artisan queue:work database --sleep=3 --tries=3 &
done

# توقف الـ workers:
pkill -f "queue:work"
```

### التحقق من الملفات المنشأة:

```bash
# 1. تحقق من ملف الملخص:
ls -la storage/app/orders/

# 2. اعرض محتوى الملف:
cat storage/app/orders/order_1_summary.txt

# 3. تحقق من الـ logs:
tail -50 storage/logs/laravel.log
```

### الاختبار عبر PHPUnit:

```bash
php artisan test --filter=AsyncQueueVerificationTest

# النتيجة المتوقعة:
# ✓ checkout dispatches both async jobs
# ✓ queue connection is configured
# ✓ jobs table exists
# ✓ generate order summary job creates file
# ✓ process order job updates order status
# ✓ queue worker configuration
# 6 tests, 13 assertions
```

---

## 📦 الطلب 4: اختبر Batch Processing

### الاختبار الأول - إنشاء التقرير:

```bash
# 1. شغّل الـ batch job يدويًا:
php artisan tinker
>>> dispatch(new \App\Jobs\GenerateDailySalesReportJob());

# 2. أو استخدم queue:work:
php artisan queue:work database

# 3. تحقق من التقرير:
ls -la storage/app/reports/

# 4. اعرض محتوى التقرير:
cat storage/app/reports/sales_report_2026-05-18.txt
```

### الاختبار الثاني - مع بيانات كثيرة:

```php
// php artisan tinker

// أنشئ 150 طلب:
$user = \App\Models\User::factory()->create();
$product = \App\Models\Product::factory()->create();

for ($i = 0; $i < 150; $i++) {
    $order = \App\Models\Order::factory()->create([
        'user_id' => $user->id,
        'total_price' => rand(100, 1000),
    ]);
    
    $order->items()->create([
        'product_id' => $product->id,
        'quantity' => rand(1, 5),
        'price_at_purchase' => $product->price,
    ]);
}

// شغّل الـ batch job:
dispatch(new \App\Jobs\GenerateDailySalesReportJob());

// شغّل queue worker في terminal آخر:
// php artisan queue:work database

// اعرض التقرير:
cat storage/app/reports/sales_report_2026-05-18.txt
```

### الاختبار الثالث - الجدولة:

```bash
# 1. شغّل scheduler مرة واحدة:
php artisan schedule:run

# 2. أو للتطوير، شغّل scheduler بشكل مستمر:
while true; do
  php artisan schedule:run
  sleep 60
done

# 3. للإنتاج، أضف إلى crontab:
crontab -e

# أضف السطر:
* * * * * php /var/www/artisan schedule:run >> /dev/null 2>&1
```

### الاختبار عبر PHPUnit:

```bash
php artisan test --filter=BatchProcessingVerificationTest

# النتيجة المتوقعة:
# ✓ batch job generates daily sales report
# ✓ batch job processes orders in chunks
# ✓ batch job calculates statistics
# ✓ batch job only includes todays orders
# ✓ batch job is scheduled
# ✓ batch job has retry configuration
# ✓ batch job is instantiable
# 7 tests, 14 assertions
```

---

## 🧪 تشغيل جميع الاختبارات معًا:

```bash
# تشغيل جميع الاختبارات:
php artisan test

# تشغيل اختبارات محددة:
php artisan test tests/Feature/RateLimitingVerificationTest.php
php artisan test tests/Feature/AsyncQueueVerificationTest.php
php artisan test tests/Feature/BatchProcessingVerificationTest.php

# تشغيل باستفاضة:
php artisan test --verbose

# مع تقارير الـ coverage:
php artisan test --coverage
```

---

## 📊 مراقبة الأداء:

### 1. مراقبة الـ Queue:

```bash
# عدد الـ jobs المعلقة:
php artisan tinker
>>> DB::table('jobs')->count()

# أنواع الـ jobs:
>>> DB::table('jobs')->groupBy('queue')->pluck('queue')

# أقدم job:
>>> DB::table('jobs')->orderBy('created_at')->first()
```

### 2. مراقبة الـ Logs:

```bash
# اعرض آخر 100 سطر:
tail -100 storage/logs/laravel.log

# فلترة حسب Job:
grep "GenerateOrderSummaryJob" storage/logs/laravel.log
grep "ProcessOrderJob" storage/logs/laravel.log

# مراقبة فورية:
tail -f storage/logs/laravel.log
```

### 3. مراقبة قاعدة البيانات:

```bash
# حجم جدول الـ jobs:
php artisan tinker
>>> DB::table('jobs')->count()

# حجم جدول failed_jobs:
>>> DB::table('failed_jobs')->count()

# حجم ملفات التقارير:
>>> exec('du -sh storage/app/reports/')
```

---

## 🔧 استكشاف الأخطاء:

### المشكلة: جدول الـ jobs فارغ

```bash
# تحقق من أن queue.default هو database:
php artisan tinker
>>> config('queue.default')
=> "database"

# إذا لم يكن database، عدّل .env:
QUEUE_CONNECTION=database
```

### المشكلة: Job فشل مع خطأ

```bash
# تحقق من جدول failed_jobs:
php artisan tinker
>>> DB::table('failed_jobs')->first()

# أعد محاولة الـ job:
php artisan queue:retry <job_id>

# أو أعد محاولة جميع الـ jobs:
php artisan queue:retry all
```

### المشكلة: Queue worker لم يعالج الـ jobs

```bash
# تأكد من أن migration تم تشغيله:
php artisan migrate:status | grep create_jobs_table

# تأكد من أن worker يعمل:
ps aux | grep "queue:work"

# شغّل worker في نفس الـ terminal:
php artisan queue:work database --verbose
```

---

## 📈 حالات الاستخدام:

### Scenario 1: Checkout عادي

```
1. User: POST /api/checkout
2. Server: معالجة سريعة (100ms)
3. Response: 201 Created فوري
4. Background: ProcessOrderJob يعالج الأوردر
5. Background: GenerateOrderSummaryJob ينشئ ملف
```

### Scenario 2: Rate Limiting

```
1. User: أرسل 100 طلب بسرعة
2. Server: اول 50 طلب يمر، الـ 50 الآخر يُرجع 429
3. User: ينتظر دقيقة ويحاول مجددًا
4. Server: يُرد على الطلبات المتأخرة
```

### Scenario 3: Batch Processing

```
1. Scheduler: يشغّل GenerateDailySalesReportJob في 00:00
2. Queue: يعالج الـ job
3. Database: يقرأ جميع طلبات اليوم (100+ طلب)
4. Processing: معالجة على دفعات من 100
5. File: تقرير شامل يُكتب إلى storage/app/reports/
6. Logs: تسجيل النجاح أو الفشل
```

---

## ✅ Checklist النشر للإنتاج:

- [ ] جميع الاختبارات تمر (20/20 ✅)
- [ ] Migration تم تشغيلها (`php artisan migrate --force`)
- [ ] Queue workers تعمل (`php artisan queue:work database`)
- [ ] Scheduler مُعدّ (`* * * * * php artisan schedule:run`)
- [ ] متغيرات البيئة محدثة (QUEUE_CONNECTION=database, etc.)
- [ ] Logs يُراقبة بشكل دوري
- [ ] Backup قاعدة البيانات قبل النشر
- [ ] اختبار الحمل على السيرفر الفعلي

---

**آخر تحديث:** 2026-05-18  
**الحالة:** ✅ READY FOR PRODUCTION
