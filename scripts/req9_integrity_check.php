<?php

/**
 * Requirement 9 — Data Integrity Verification (the "no data loss" proof)
 *
 * Run in TWO steps around the stress test:
 *
 *   1) BEFORE the test — capture a baseline of stock + units sold:
 *        php scripts/req9_integrity_check.php --baseline
 *
 *   2) AFTER the test — verify integrity and write the report:
 *        php scripts/req9_integrity_check.php
 *
 * The gold-standard check: for every product, the DROP in stock must EXACTLY
 * equal the UNITS SOLD via orders during the test. If they match → no lost
 * updates and no overselling (every decrement is backed by a real order).
 *
 * Writes: storage/app/req9/integrity.json   (consumed by the dashboard)
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Product;
use Illuminate\Support\Facades\DB;

$baselinePath  = storage_path('app/req9/stock-baseline.json');
$integrityPath = storage_path('app/req9/integrity.json');
$isBaseline    = in_array('--baseline', $argv, true);

/** Sum of units sold per product (from order_items), keyed by product_id. */
function unitsSoldPerProduct(): array
{
    return DB::table('order_items')
        ->select('product_id', DB::raw('SUM(quantity) as sold'))
        ->groupBy('product_id')
        ->pluck('sold', 'product_id')
        ->map(fn ($v) => (int) $v)
        ->toArray();
}

/** Current stock per product, keyed by product_id. */
function stockPerProduct(): array
{
    return Product::query()->pluck('stock', 'id')->map(fn ($v) => (int) $v)->toArray();
}

if ($isBaseline) {
    $baseline = [
        'captured_at' => date('Y-m-d H:i:s'),
        'stock'       => stockPerProduct(),
        'sold'        => unitsSoldPerProduct(),
        'orders'      => (int) DB::table('orders')->count(),
        'order_items' => (int) DB::table('order_items')->count(),
    ];

    file_put_contents($baselinePath, json_encode($baseline, JSON_PRETTY_PRINT));
    echo "Baseline captured → {$baselinePath}\n";
    echo "Products: " . count($baseline['stock']) . " | Orders: {$baseline['orders']}\n";
    return;
}

// ── Post-test verification ───────────────────────────────────────────────────
$baseline = is_file($baselinePath)
    ? json_decode(file_get_contents($baselinePath), true)
    : null;

$currentStock = stockPerProduct();
$currentSold  = unitsSoldPerProduct();

// Check 1 — no negative stock anywhere
$negativeStock = Product::query()->where('stock', '<', 0)->count();

// Check 2 — no overselling / no lost updates: stock drop == units sold (per product)
$mismatches = [];
$comparedProducts = 0;
if ($baseline && isset($baseline['stock'])) {
    foreach ($baseline['stock'] as $productId => $startStock) {
        if (! array_key_exists($productId, $currentStock)) {
            continue;
        }
        $comparedProducts++;

        $stockDrop = $startStock - $currentStock[$productId];                       // how much stock fell
        $soldStart = $baseline['sold'][$productId] ?? 0;
        $soldDelta = ($currentSold[$productId] ?? 0) - $soldStart;                  // units sold during the test

        if ($stockDrop !== $soldDelta) {
            $mismatches[] = [
                'product_id' => (int) $productId,
                'stock_drop' => $stockDrop,
                'units_sold' => $soldDelta,
            ];
        }
    }
}

// Check 3 — every order's total_price equals the sum of its item subtotals
$totalMismatchRows = DB::table('orders')
    ->leftJoin('order_items', 'orders.id', '=', 'order_items.order_id')
    ->select('orders.id', 'orders.total_price', DB::raw('SUM(order_items.quantity * order_items.price_at_purchase) as computed'))
    ->groupBy('orders.id', 'orders.total_price')
    ->havingRaw('ABS(orders.total_price - COALESCE(SUM(order_items.quantity * order_items.price_at_purchase), 0)) > 0.01')
    ->get();
$orderTotalMismatches = $totalMismatchRows->count();

// Check 4 — no orphan order_items (item pointing at a missing order)
$orphanItems = DB::table('order_items')
    ->leftJoin('orders', 'order_items.order_id', '=', 'orders.id')
    ->whereNull('orders.id')
    ->count();

$checks = [
    [
        'name'   => 'No negative stock',
        'detail' => 'products with stock < 0',
        'value'  => $negativeStock,
        'pass'   => $negativeStock === 0,
    ],
    [
        'name'   => 'No overselling / lost updates',
        'detail' => $baseline
            ? "stock drop == units sold across {$comparedProducts} products"
            : 'baseline missing — run with --baseline before the test',
        'value'  => $baseline ? (count($mismatches) . ' mismatch(es)') : 'N/A',
        'pass'   => $baseline ? (count($mismatches) === 0) : false,
    ],
    [
        'name'   => 'Order totals consistent',
        'detail' => 'total_price == sum(quantity * price_at_purchase)',
        'value'  => $orderTotalMismatches . ' mismatch(es)',
        'pass'   => $orderTotalMismatches === 0,
    ],
    [
        'name'   => 'No orphan order items',
        'detail' => 'order_items pointing at a missing order',
        'value'  => $orphanItems,
        'pass'   => $orphanItems === 0,
    ],
];

$report = [
    'generated_at' => date('Y-m-d H:i:s'),
    'has_baseline' => (bool) $baseline,
    'orders_now'   => (int) DB::table('orders')->count(),
    'orders_before' => $baseline['orders'] ?? null,
    'checks'       => $checks,
    'mismatches'   => $mismatches,
    'all_pass'     => array_reduce($checks, fn ($carry, $c) => $carry && $c['pass'], true),
];

file_put_contents($integrityPath, json_encode($report, JSON_PRETTY_PRINT));

echo "\nIntegrity report → {$integrityPath}\n";
foreach ($checks as $c) {
    echo ($c['pass'] ? '  [PASS] ' : '  [FAIL] ') . $c['name'] . ' (' . $c['value'] . ")\n";
}
echo "\nALL PASS: " . ($report['all_pass'] ? 'YES' : 'NO') . "\n";
