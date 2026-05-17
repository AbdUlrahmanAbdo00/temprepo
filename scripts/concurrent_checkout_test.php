<?php

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Promise;

$client = new Client(['base_uri' => 'http://127.0.0.1:8000']);

$numRequests = intval($argv[1] ?? 100);
$productId = intval($argv[2] ?? 1);
$quantity = intval($argv[3] ?? 1);

echo "Starting concurrent checkout test...\n";
echo "Requests: $numRequests\n";
echo "Product: $productId\n";
echo "Quantity: $quantity\n";
echo "---\n\n";

$promises = [];
$results = [
    'success' => 0,
    'failed' => 0,
    'out_of_stock' => 0,
    'errors' => [],
];

// Generate requests with rotating user_ids (1-5)
for ($i = 1; $i <= $numRequests; $i++) {
    $userId = (($i - 1) % 5) + 1; // Rotate between user_id 1-5

    $promise = $client->postAsync('/api/test/checkout', [
        'json' => [
            'user_id' => $userId,
            'product_id' => $productId,
            'quantity' => $quantity,
        ],
    ])->then(
        function ($response) use (&$results, $userId) {
            $body = json_decode($response->getBody(), true);
            if ($response->getStatusCode() === 201) {
                $results['success']++;
            } elseif ($response->getStatusCode() === 422) {
                if (strpos($body['message'], 'Out of stock') !== false) {
                    $results['out_of_stock']++;
                } else {
                    $results['failed']++;
                }
            }
        },
        function ($reason) use (&$results) {
            $results['failed']++;
            $results['errors'][] = (string) $reason;
        }
    );

    $promises[$i] = $promise;
}

// Execute all promises concurrently
$responses = Promise\Utils::settle($promises)->wait();

// Final results
echo "\n=== RESULTS ===\n";
echo "Total Requests: " . $numRequests . "\n";
echo "Successful: " . $results['success'] . "\n";
echo "Out of Stock: " . $results['out_of_stock'] . "\n";
echo "Failed: " . $results['failed'] . "\n";

if (! empty($results['errors'])) {
    echo "\nErrors:\n";
    foreach (array_unique($results['errors']) as $err) {
        echo "  - " . substr($err, 0, 100) . "\n";
    }
}

echo "\n=== EXPECTED vs ACTUAL ===\n";
echo "Expected successful orders: 1 (only 1 stock available)\n";
echo "Actual successful orders: " . $results['success'] . "\n";

if ($results['success'] > 1) {
    echo "\n⚠️  RACE CONDITION DETECTED! Multiple checkouts succeeded with stock=1\n";
} else {
    echo "\nNo race condition detected in this run.\n";
}

echo "\nCheck product stock after test:\n";
echo "php artisan tinker --execute=\"echo 'Stock: '; Product::find($productId)->pluck('stock');\"\n";
