<?php

/**
 * Requirement 9 — Dashboard Builder
 *
 * Reads the REAL outputs produced by the stress run and renders a single,
 * self-contained HTML dashboard (no external CDN — inline CSS + inline SVG charts).
 *
 * Inputs (any missing input degrades gracefully to an "awaiting data" panel):
 *   storage/app/req9/k6-summary.json   ← from scripts/k6_stress_test.js
 *   storage/app/req9/resources.json    ← from scripts/req9_resource_sampler.php
 *   storage/app/req9/integrity.json    ← from scripts/req9_integrity_check.php
 *
 * Output:
 *   storage/app/req9/req9-dashboard.html   (open directly in a browser)
 *
 * Run AFTER the stress test + integrity check:
 *   php scripts/build_req9_dashboard.php
 */

$dir = __DIR__ . '/../storage/app/req9';

function loadJson(string $path): ?array
{
    if (! is_file($path)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

$k6        = loadJson("$dir/k6-summary.json");
$resources = loadJson("$dir/resources.json");
$integrity = loadJson("$dir/integrity.json");

function g($arr, array $path, $default = null)
{
    foreach ($path as $key) {
        if (! is_array($arr) || ! array_key_exists($key, $arr)) {
            return $default;
        }
        $arr = $arr[$key];
    }
    return $arr;
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** Build an inline SVG area chart from a list of numbers. */
function svgArea(array $values, string $color, string $unitLabel): string
{
    $w = 620; $h = 170; $pad = 28;
    if (count($values) < 2) {
        return '<div class="empty">لا توجد عيّنات كافية للرسم</div>';
    }
    $max = max($values);
    $max = $max <= 0 ? 1 : $max;
    $n = count($values);
    $plotW = $w - $pad * 2;
    $plotH = $h - $pad * 2;

    $pts = [];
    foreach ($values as $i => $v) {
        $x = $pad + ($plotW * $i / ($n - 1));
        $y = $pad + $plotH - ($plotH * ($v / $max));
        $pts[] = round($x, 1) . ',' . round($y, 1);
    }
    $line = implode(' ', $pts);
    $axisBottom = $pad + $plotH;
    $plotRight = $pad + $plotW;
    $area = "$pad,$axisBottom " . $line . " $plotRight,$axisBottom";
    $maxLabel = rtrim(rtrim(number_format($max, 1), '0'), '.');
    $baseTextY = $h - 14;

    return <<<SVG
<svg viewBox="0 0 $w $h" class="chart">
  <line x1="$pad" y1="$pad" x2="$pad" y2="$axisBottom" />
  <line x1="$pad" y1="$axisBottom" x2="{$plotRight}" y2="$axisBottom" />
  <polygon points="$area" fill="$color" fill-opacity="0.15" stroke="none"></polygon>
  <polyline points="$line" fill="none" stroke="$color" stroke-width="2"></polyline>
  <text x="$pad" y="18" class="axis">{$maxLabel} {$unitLabel}</text>
  <text x="$pad" y="$baseTextY" class="axis">0</text>
</svg>
SVG;
}

// ── Compute headline status ─────────────────────────────────────────────────
$noCrash       = $k6 ? (bool) g($k6, ['no_crash'], false) : null;
$integrityPass = $integrity ? (bool) g($integrity, ['all_pass'], false) : null;

if ($k6 === null && $integrity === null) {
    $badge = ['AWAITING DATA', 'warn'];
} elseif ($noCrash && $integrityPass) {
    $badge = ['PASSED', 'pass'];
} elseif ($noCrash === false || $integrityPass === false) {
    $badge = ['FAILED', 'fail'];
} else {
    $badge = ['PARTIAL', 'warn'];
}

// ── KPI values ───────────────────────────────────────────────────────────────
$maxVus     = $k6 ? g($k6, ['max_vus'], '—') : '—';
$serverErr  = $k6 ? (int) g($k6, ['responses', 'server_errors'], 0) : null;
$successPct = $k6 ? g($k6, ['success_rate_pct'], 0) : null;
$checkoutP95 = $k6 ? g($k6, ['latency_ms', 'checkout', 'p95'], 0) : null;

$serverErrClass = $serverErr === null ? 'warn' : ($serverErr === 0 ? 'pass' : 'fail');
$successClass   = $successPct === null ? 'warn' : ($successPct >= 90 ? 'pass' : ($successPct >= 70 ? 'warn' : 'fail'));

// ── Response breakdown ────────────────────────────────────────────────────────
$resp = $k6 ? g($k6, ['responses'], []) : [];

// ── Latency per operation ──────────────────────────────────────────────────────
$ops = ['browse' => 'تصفّح المنتجات', 'cart' => 'إضافة للسلة', 'checkout' => 'إتمام الشراء', 'orders' => 'عرض الطلبات'];

// ── Resource series ────────────────────────────────────────────────────────────
$cpuSeries = $resources ? array_column(g($resources, ['samples'], []), 'cpu_pct') : [];
$ramSeries = $resources ? array_column(g($resources, ['samples'], []), 'ram_mb') : [];

// ── Build HTML ─────────────────────────────────────────────────────────────────
$badgeText = e($badge[0]);
$badgeClass = $badge[1];
$generatedAt = $k6 ? e((string) g($k6, ['generated_at'], '')) : date('c');

ob_start();
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Stress Test — Requirement 9</title>
<style>
  :root{
    --bg:#0f172a; --card:#1e293b; --card2:#172033; --txt:#e2e8f0; --muted:#94a3b8;
    --pass:#22c55e; --fail:#ef4444; --warn:#f59e0b; --blue:#3b82f6; --line:#334155;
  }
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--txt);font-family:"Segoe UI",Tahoma,sans-serif;padding:24px;line-height:1.5}
  .wrap{max-width:1100px;margin:0 auto}
  header{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px;border-bottom:1px solid var(--line);padding-bottom:16px;margin-bottom:24px}
  header h1{margin:0;font-size:22px}
  header p{margin:4px 0 0;color:var(--muted);font-size:14px}
  .badge{font-size:22px;font-weight:800;padding:10px 24px;border-radius:10px;letter-spacing:1px}
  .badge.pass{background:rgba(34,197,94,.15);color:var(--pass);border:1px solid var(--pass)}
  .badge.fail{background:rgba(239,68,68,.15);color:var(--fail);border:1px solid var(--fail)}
  .badge.warn{background:rgba(245,158,11,.15);color:var(--warn);border:1px solid var(--warn)}
  h2{font-size:15px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin:28px 0 12px}
  .grid{display:grid;gap:16px}
  .kpis{grid-template-columns:repeat(4,1fr)}
  .two{grid-template-columns:1fr 1fr}
  .card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:18px}
  .kpi .num{font-size:34px;font-weight:800;line-height:1}
  .kpi .lbl{color:var(--muted);font-size:13px;margin-top:8px}
  .kpi.pass .num{color:var(--pass)} .kpi.fail .num{color:var(--fail)}
  .kpi.warn .num{color:var(--warn)} .kpi.blue .num{color:var(--blue)}
  .bars{display:flex;flex-direction:column;gap:10px}
  .bar-row{display:grid;grid-template-columns:130px 1fr 90px;align-items:center;gap:10px;font-size:13px}
  .bar-track{background:var(--card2);border-radius:6px;height:22px;overflow:hidden}
  .bar-fill{height:100%;border-radius:6px}
  table{width:100%;border-collapse:collapse;font-size:14px}
  th,td{text-align:right;padding:10px 12px;border-bottom:1px solid var(--line)}
  th{color:var(--muted);font-weight:600}
  .tag{font-weight:700;padding:2px 10px;border-radius:20px;font-size:12px}
  .tag.ok{background:rgba(34,197,94,.15);color:var(--pass)}
  .tag.no{background:rgba(239,68,68,.15);color:var(--fail)}
  .chart{width:100%;height:auto;background:var(--card2);border-radius:8px}
  .chart line{stroke:var(--line);stroke-width:1}
  .chart .axis{fill:var(--muted);font-size:11px}
  .stat{display:flex;gap:20px;margin-top:10px;color:var(--muted);font-size:13px}
  .stat b{color:var(--txt)}
  .empty{color:var(--muted);padding:30px;text-align:center}
  footer{margin-top:30px;padding-top:16px;border-top:1px solid var(--line);color:var(--muted);font-size:13px}
  .note{font-size:12px;color:var(--muted);margin-top:6px}
</style>
</head>
<body>
<div class="wrap">

  <header>
    <div>
      <h1>اختبار الاستقرار تحت الضغط — الطلب التاسع</h1>
      <p>محرك التجارة الإلكترونية عالي الأداء · 100 مستخدم متزامن على جميع العمليات</p>
    </div>
    <div class="badge <?= $badgeClass ?>"><?= $badgeText ?></div>
  </header>

  <h2>المؤشرات الرئيسية</h2>
  <div class="grid kpis">
    <div class="card kpi blue"><div class="num"><?= e((string) $maxVus) ?></div><div class="lbl">أقصى مستخدمين متزامنين (Max VUs)</div></div>
    <div class="card kpi <?= $serverErrClass ?>"><div class="num"><?= $serverErr === null ? '—' : (int) $serverErr ?></div><div class="lbl">أخطاء الخادم (5xx) — مؤشر الانهيار</div></div>
    <div class="card kpi <?= $successClass ?>"><div class="num"><?= $successPct === null ? '—' : e((string) $successPct) . '%' ?></div><div class="lbl">نسبة الردود الناجحة (2xx)</div></div>
    <div class="card kpi blue"><div class="num"><?= $checkoutP95 === null ? '—' : e((string) round($checkoutP95)) ?><?= $checkoutP95 === null ? '' : ' ms' ?></div><div class="lbl">زمن الشراء p95</div></div>
  </div>

  <h2>تصنيف الردود (تفسير صادق)</h2>
  <div class="card">
    <?php if ($k6): ?>
    <div class="bars">
      <?php
        $total = max(1, (int) g($resp, ['total'], 1));
        $rows = [
          ['ok_2xx', 'ناجح (2xx)', 'var(--pass)'],
          ['rate_limited_429', 'محدود بالمعدّل 429 — الطلب 2 يحمي النظام', 'var(--warn)'],
          ['busy_503', 'مشغول 503 — الطلب 7 يخفّف الحمل بلباقة', 'var(--warn)'],
          ['out_of_stock_422', 'نفاد مخزون 422 — الطلب 1 يمنع البيع الزائد', 'var(--blue)'],
          ['server_errors', 'خطأ خادم 5xx — انهيار', 'var(--fail)'],
        ];
        foreach ($rows as [$key, $label, $color]):
          $v = (int) g($resp, [$key], 0);
          $pct = round($v / $total * 100, 1);
      ?>
      <div class="bar-row">
        <span><?= e($label) ?></span>
        <span class="bar-track"><span class="bar-fill" style="width:<?= max(1, $pct) ?>%;background:<?= $color ?>"></span></span>
        <span><?= number_format($v) ?> (<?= $pct ?>%)</span>
      </div>
      <?php endforeach; ?>
    </div>
    <p class="note">429 و 422 سلوك مقصود وصحيح (حماية وموارد)، وليست أعطالاً. مؤشر الفشل الحقيقي هو 5xx فقط.</p>
    <?php else: ?>
      <div class="empty">شغّل سكربت الضغط أولاً: <code>k6 run scripts/k6_stress_test.js</code></div>
    <?php endif; ?>
  </div>

  <h2>زمن الاستجابة لكل عملية (ms)</h2>
  <div class="card">
    <?php if ($k6): ?>
    <div class="bars">
      <?php
        $maxLat = 1;
        foreach ($ops as $k => $_) { $maxLat = max($maxLat, (float) g($k6, ['latency_ms', $k, 'p95'], 0)); }
        foreach ($ops as $k => $label):
          $avg = (float) g($k6, ['latency_ms', $k, 'avg'], 0);
          $p95 = (float) g($k6, ['latency_ms', $k, 'p95'], 0);
      ?>
      <div class="bar-row">
        <span><?= e($label) ?></span>
        <span class="bar-track"><span class="bar-fill" style="width:<?= max(1, round($p95 / $maxLat * 100)) ?>%;background:var(--blue)"></span></span>
        <span>avg <?= round($avg) ?> · p95 <?= round($p95) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
      <div class="empty">بانتظار بيانات k6</div>
    <?php endif; ?>
  </div>

  <h2>موارد الخادم عبر الزمن</h2>
  <div class="grid two">
    <div class="card">
      <strong>استهلاك المعالج (CPU %)</strong>
      <?php
        echo $cpuSeries ? svgArea($cpuSeries, 'var(--warn)', '%') : '<div class="empty">شغّل req9_resource_sampler.php أثناء الاختبار</div>';
      ?>
      <?php if ($resources): ?>
      <div class="stat"><span>المتوسط: <b><?= e((string) g($resources, ['summary','avg_cpu_pct'], 0)) ?>%</b></span><span>الذروة: <b><?= e((string) g($resources, ['summary','max_cpu_pct'], 0)) ?>%</b></span></div>
      <?php endif; ?>
    </div>
    <div class="card">
      <strong>استهلاك الذاكرة (RAM MB)</strong>
      <?php echo $ramSeries ? svgArea($ramSeries, 'var(--blue)', 'MB') : '<div class="empty">بانتظار عيّنات الموارد</div>'; ?>
      <?php if ($resources): ?>
      <div class="stat"><span>المتوسط: <b><?= e((string) g($resources, ['summary','avg_ram_mb'], 0)) ?> MB</b></span><span>الذروة: <b><?= e((string) g($resources, ['summary','max_ram_mb'], 0)) ?> MB</b></span></div>
      <?php endif; ?>
    </div>
  </div>

  <h2>فحص سلامة البيانات (الأهم — إثبات "لا فقدان بيانات")</h2>
  <div class="card">
    <?php if ($integrity): ?>
    <table>
      <thead><tr><th>الفحص</th><th>التفصيل</th><th>النتيجة</th><th>الحالة</th></tr></thead>
      <tbody>
      <?php foreach (g($integrity, ['checks'], []) as $c): ?>
        <tr>
          <td><?= e((string) g($c, ['name'], '')) ?></td>
          <td style="color:var(--muted)"><?= e((string) g($c, ['detail'], '')) ?></td>
          <td><?= e((string) g($c, ['value'], '')) ?></td>
          <td><span class="tag <?= g($c, ['pass'], false) ? 'ok' : 'no' ?>"><?= g($c, ['pass'], false) ? '✓ PASS' : '✗ FAIL' ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="note">الطلبات قبل الاختبار: <?= e((string) g($integrity, ['orders_before'], '—')) ?> · بعد الاختبار: <?= e((string) g($integrity, ['orders_now'], '—')) ?></p>
    <?php else: ?>
      <div class="empty">شغّل: <code>php scripts/req9_integrity_check.php --baseline</code> قبل الاختبار، ثم <code>php scripts/req9_integrity_check.php</code> بعده</div>
    <?php endif; ?>
  </div>

  <footer>
    <?php if ($k6 && $noCrash && $integrityPass): ?>
      ✅ خدم النظام <?= e((string) $maxVus) ?> مستخدماً متزامناً دون أي خطأ خادم (5xx)، ودون فقدان بيانات،
      بذروة معالج <?= e((string) g($resources, ['summary','max_cpu_pct'], '—')) ?>% وذاكرة <?= e((string) g($resources, ['summary','max_ram_mb'], '—')) ?> MB.
    <?php else: ?>
      هذه نتائج مرحلية. أكمل تشغيل خط الأنابيب الثلاثي (الموارد + k6 + فحص السلامة) ثم أعد توليد الداشبورد.
    <?php endif; ?>
    <div class="note">تاريخ التوليد: <?= $generatedAt ?> · ملف مستقل بلا اعتماد على شبكة خارجية.</div>
  </footer>

</div>
</body>
</html>
<?php
$html = ob_get_clean();
$outPath = "$dir/req9-dashboard.html";
file_put_contents($outPath, $html);

echo "Dashboard written → storage/app/req9/req9-dashboard.html\n";
echo "Inputs present: "
    . 'k6=' . ($k6 ? 'yes' : 'no') . ', '
    . 'resources=' . ($resources ? 'yes' : 'no') . ', '
    . 'integrity=' . ($integrity ? 'yes' : 'no') . "\n";
echo "Status: {$badge[0]}\n";
