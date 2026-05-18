# مراقبة موارد المشروع - Project Resource Monitor

## 📋 نظرة عامة

سكريبت متخصص لقياس استهلاك CPU و RAM **للمشروع فقط** (وليس النظام العام).

يراقب المكونات الثلاثة الرئيسية:
- 🔴 **PHP** (عمليات Laravel)
- 🟢 **MySQL** (قاعدة البيانات)
- 🔵 **Redis** (Cache و Queue)

---

## 🚀 الاستخدام

### 1️⃣ **عرض المراقبة النصية**
```bash
php scripts/project_resource_monitor.php
```

**الناتج:**
```
╔════════════════════════════════════════════════════════════╗
║       PROJECT RESOURCE MONITOR - Laravel E-Commerce        ║
╠════════════════════════════════════════════════════════════╣
║ 🔴 PHP (Laravel Process)                                   ║
║    Status: Running                                         ║
║    CPU: 2.3%                                              ║
║    RAM: 45 MB                                             ║
║    Processes: 2                                            ║
║                                                            ║
║ 🟢 MySQL (Database)                                       ║
║    Status: Running                                         ║
║    CPU: N/A                                               ║
║    RAM: 120 MB                                            ║
║                                                            ║
║ 🔵 Redis (Cache & Queue)                                  ║
║    Status: Running (Service)                              ║
║    CPU: N/A                                               ║
║    RAM: 15 MB                                             ║
║                                                            ║
╠════════════════════════════════════════════════════════════╣
║ 📊 TOTAL PROJECT RESOURCES                                ║
║    RAM Total: 180 MB                                       ║
║                                                            ║
║    RAM Status: ✅ NORMAL                                  ║
║                                                            ║
║ Last Updated: 2026-05-18 10:30:45                        ║
╚════════════════════════════════════════════════════════════╝
```

---

### 2️⃣ **تصدير بصيغة JSON**
```bash
php scripts/project_resource_monitor.php --json
```

**الناتج:**
```json
{
    "timestamp": "2026-05-18 10:30:45",
    "php": {
        "cpu": 2.3,
        "ram_mb": 45,
        "processes": 2,
        "status": "Running"
    },
    "mysql": {
        "cpu": "N/A",
        "ram_mb": 120,
        "status": "Running"
    },
    "redis": {
        "cpu": "N/A",
        "ram_mb": 15,
        "status": "Running (Service)"
    },
    "total": {
        "ram_mb": 180
    }
}
```

---

## 📊 شرح كل مكون

### 🔴 PHP (Laravel)
| الحقل | المعنى |
|------|-------|
| **Status** | هل عمليات PHP قيد التشغيل |
| **CPU** | استهلاك CPU من جميع عمليات PHP |
| **RAM** | إجمالي RAM المستهلك من PHP |
| **Processes** | عدد عمليات PHP المشغّلة |

**ملاحظة:** على Windows، قد يكون CPU = 0 إذا لم تكن PHP قيد التشغيل النشط

---

### 🟢 MySQL (Database)
| الحقل | المعنى |
|------|-------|
| **Status** | حالة خادم MySQL |
| **CPU** | عادة N/A (يحسب من خلال Performance Monitor) |
| **RAM** | RAM المستهلك من MySQL |

**متطلبات:** MySQL يجب أن يكون قيد التشغيل (من XAMPP)

---

### 🔵 Redis (Cache & Queue)
| الحقل | المعنى |
|------|-------|
| **Status** | Redis يمكن أن يكون Service أو Process |
| **CPU** | عادة N/A |
| **RAM** | RAM المستهلك من Redis |

**متطلبات:** Redis يجب أن يكون قيد التشغيل على 127.0.0.1:6379

---

## 🎯 حالات الاستخدام

### 1️⃣ **مراقبة أثناء التطوير**
اشغل السكريبت قبل وبعد اختبار API:
```bash
# قبل الاختبار
php scripts/project_resource_monitor.php

# اشغّل 100 طلب للـ API
ab -n 100 http://my-ecommerce-app.test/api/products

# بعد الاختبار
php scripts/project_resource_monitor.php
```

**الفائدة:** رصد تسريب الذاكرة (Memory Leaks)

---

### 2️⃣ **مراقبة دورية (Cron Job)**
```bash
# كل 5 دقائق
*/5 * * * * cd /c/projects/new/MY_STORE/my-ecommerce-app && php scripts/project_resource_monitor.php --json >> storage/logs/resource_monitor.json

# على Windows استخدم Task Scheduler
```

---

### 3️⃣ **دمج مع لوحة تحكم**
اقرأ JSON من السكريبت وعرضه على الويب:
```php
$output = shell_exec('php scripts/project_resource_monitor.php --json');
$metrics = json_decode($output, true);
// عرض البيانات في Dashboard
```

---

## 🟢 حالات الاستخدام الطبيعية

| المكون | الحالة الطبيعية | المنخفض | المرتفع |
|-------|---------------|--------|--------|
| **PHP RAM** | 50-150 MB | < 30 MB | > 300 MB 🔥 |
| **MySQL RAM** | 100-300 MB | < 50 MB | > 500 MB 🔥 |
| **Redis RAM** | 10-50 MB | < 5 MB | > 100 MB 🔥 |
| **إجمالي** | 200-400 MB | < 100 MB | > 800 MB 🔥 |

---

## ⚠️ استكشاف الأخطاء

### المشكلة: الحالات تظهر "Not running"

**السبب:** الخدمات لم تُبدأ
```bash
# بدء XAMPP
# 1. افتح XAMPP Control Panel
# 2. ابدأ Apache و MySQL و Redis

# أو من Terminal
net start Apache2.4
net start MySQL80
redis-server
```

---

### المشكلة: CPU يظهر "N/A" على Windows

**السبب:** Windows لا يدعم الوصول السهل لـ CPU لعملية محددة

**الحل:** استخدم `server_pressure_monitor.php` لقياس CPU العام:
```bash
php scripts/server_pressure_monitor.php
```

---

### المشكلة: Redis يظهر "Not running" لكنه يعمل

**السبب:** قد يكون Redis يعمل كـ Service في Windows

**الحل:** السكريبت يحاول الاتصال بـ Redis على 127.0.0.1:6379 ويعتبره "Running (Service)"

---

## 📈 أمثلة متقدمة

### مقارنة الاستخدام قبل وبعد

```bash
#!/bin/bash

echo "=== قبل بدء API Load ==="
php scripts/project_resource_monitor.php

echo ""
echo "=== جاري تشغيل الحمل... ==="
# شغّل أداة اختبار (Apache Bench, wrk, etc)

echo ""
echo "=== بعد انتهاء الحمل ==="
php scripts/project_resource_monitor.php
```

---

### إنشاء تنبيهات تلقائية

```php
<?php
$output = shell_exec('php scripts/project_resource_monitor.php --json');
$metrics = json_decode($output, true);

if ($metrics['total']['ram_mb'] > 500) {
    // إرسال تنبيه
    mail('admin@example.com', 'HIGH RAM USAGE!', 'RAM: ' . $metrics['total']['ram_mb'] . ' MB');
}
?>
```

---

## 🔗 السكريبتات ذات الصلة

| السكريبت | الوظيفة | المدى |
|---------|--------|------|
| `project_resource_monitor.php` | قياس موارد المشروع | **المشروع فقط** ✅ |
| `server_pressure_monitor.php` | قياس الضغط على النظام | **النظام العام كله** |
| `project_health_monitor.php` | فحص صحة المشروع | Database, Cache, Response Time |

---

## 📝 الملاحظات

✅ **ما يقيسه:**
- RAM المستخدم من كل مكون
- عدد عمليات PHP
- حالة الخدمات (Running / Not running)

❌ **ما لا يقيسه:**
- CPU على Windows (يتطلب Admin privileges)
- Number of connections (قد نضيفها لاحقاً)
- Query performance (استخدم Laravel Debugbar)
- Cache hit ratio (استخدم Redis CLI)

---

## 📚 الملفات ذات الصلة

- [Requirement 2 Solution](requirement-2-solution.md) - Resource Management & Rate Limiting
- [server_pressure_monitor.php](../scripts/server_pressure_monitor.php) - النظام العام
- [project_health_monitor.php](../scripts/project_health_monitor.php) - Health checks
