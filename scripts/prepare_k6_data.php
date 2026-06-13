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
$outputFile = $argv[4] ?? storage_path('app/k6-auth-data.json');

$productSeeds = [
    ['name' => 'k6 electronics', 'price' => 299.99, 'description' => 'Electronics benchmark product.'],
    ['name' => 'k6 fashion', 'price' => 79.50, 'description' => 'Fashion benchmark product.'],
    ['name' => 'k6 home', 'price' => 45.25, 'description' => 'Home benchmark product.'],
    ['name' => 'k6 sports', 'price' => 120.00, 'description' => 'Sports benchmark product.'],
    ['name' => 'k6 books', 'price' => 25.00, 'description' => 'Books benchmark product.'],
    ['name' => 'k6 grocery', 'price' => 15.75, 'description' => 'Grocery benchmark product.'],
];

$products = [];

foreach ($productSeeds as $seed) {
    $product = Product::query()->updateOrCreate(
        ['name' => $seed['name']],
        [
            'price' => $seed['price'],
            'stock' => $productStock,
            'description' => $seed['description'],
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