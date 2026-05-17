<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Product;

foreach (Product::all() as $p) {
    echo $p->id . ' | ' . $p->name . ' | stock=' . $p->stock . PHP_EOL;
}
