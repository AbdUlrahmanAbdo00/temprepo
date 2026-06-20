<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;

$userCount = max(1, (int) ($argv[1] ?? 10));
$defaultPassword = $argv[2] ?? 'password123';
$productStock = max(100, (int) ($argv[3] ?? 1000));
$productCount = max(1, (int) ($argv[4] ?? 200));
$outputFile = $argv[5] ?? storage_path('app/k6-auth-data.json');

// Generate many products with HIGH stock so 100 VUs spread across them — this
// removes the artificial lock contention that 6 products caused (Req 9 realism).
$products = [];

for ($i = 1; $i <= $productCount; $i++) {
    $product = Product::query()->updateOrCreate(
        ['name' => "k6 product {$i}"],
        [
            'price' => 50 + ($i % 50) * 5,
            'stock' => $productStock,
            'description' => "k6 stress-test product #{$i}.",
        ]
    );

    $products[] = [
        'id' => $product->id,
        'name' => $product->name,
        'price' => (float) $product->price,
    ];
}

$users = [];

for ($index = 1; $index <= $userCount; $index++) {
    $email = sprintf('k6-user-%d@test.com', $index);

    $user = User::query()->updateOrCreate(
        ['email' => $email],
        [
            'name' => sprintf('K6 User %d', $index),
            'password' => $defaultPassword,
        ]
    );

    CartItem::query()
        ->where('user_id', $user->id)
        ->delete();

    $users[] = [
        'id' => $user->id,
        'name' => $user->name,
        'email' => $user->email,
    ];
}

$payload = [
    'base_url' => env('K6_BASE_URL', 'http://my-ecommerce-app.test'),
    'password' => $defaultPassword,
    'products' => $products,
    'users' => $users,
];

file_put_contents($outputFile, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "Prepared {$userCount} users for k6.\n";
echo "Prepared " . count($products) . " products.\n";
echo "Output: {$outputFile}\n";