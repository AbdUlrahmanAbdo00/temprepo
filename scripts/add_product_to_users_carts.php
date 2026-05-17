<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\CartItem;
use App\Models\Product;

$productId = $argv[1] ?? 1;
$quantity = intval($argv[2] ?? 1);

$product = Product::find($productId);
if (! $product) {
    echo "Product id={$productId} not found.\n";
    exit(1);
}

$users = User::where('email', 'like', 'user%@test.com')->get();
if ($users->isEmpty()) {
    echo "No test users found.\n";
    exit(1);
}

foreach ($users as $user) {
    $item = CartItem::firstOrNew([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);

    $item->quantity = $quantity;
    $item->save();

    echo "Added product {$product->id} to {$user->email} (quantity={$item->quantity}).\n";
}

// Show summary
echo "\nCart summary for users:\n";
foreach ($users as $user) {
    $items = $user->cartItems()->with('product')->get();
    echo "- {$user->email}:\n";
    foreach ($items as $it) {
        echo "    * {$it->product->id} | {$it->product->name} | qty={$it->quantity}\n";
    }
}
