<?php

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;

function formatMilliseconds(float $seconds): string
{
    return number_format($seconds * 1000, 2) . ' ms';
}

$appUrl = rtrim($_ENV['APP_URL'] ?? getenv('APP_URL') ?: 'http://localhost', '/');
$targetUrl = rtrim($argv[1] ?? $appUrl, '/');
$healthUrl = $targetUrl . '/health';

$client = new Client([
    'timeout' => 10,
    'connect_timeout' => 5,
    'http_errors' => false,
]);

echo str_repeat('=', 60) . PHP_EOL;
echo "Project Health Monitor" . PHP_EOL;
echo "Configured APP_URL: {$appUrl}" . PHP_EOL;
echo "Target URL: {$targetUrl}" . PHP_EOL;
echo "Health Endpoint: {$healthUrl}" . PHP_EOL;
echo "Time: " . date('Y-m-d H:i:s') . PHP_EOL;
echo str_repeat('=', 60) . PHP_EOL;

$start = microtime(true);

try {
    $response = $client->get($healthUrl);
    $duration = microtime(true) - $start;
    $statusCode = $response->getStatusCode();
    $body = json_decode((string) $response->getBody(), true);

    echo "Response Time: " . formatMilliseconds($duration) . PHP_EOL;
    echo "HTTP Status: {$statusCode}" . PHP_EOL;

    if (is_array($body)) {
        echo "Application: " . ($body['app'] ?? 'unknown') . PHP_EOL;
        echo "Environment: " . ($body['environment'] ?? 'unknown') . PHP_EOL;
        echo "Overall Status: " . ($body['status'] ?? 'unknown') . PHP_EOL;
        echo "Database: " . ($body['database'] ?? 'unknown') . PHP_EOL;
        echo "Cache: " . ($body['cache'] ?? 'unknown') . PHP_EOL;
    }

    echo PHP_EOL . "Quick Interpretation:" . PHP_EOL;
    if ($statusCode >= 500) {
        echo "- The project is returning server errors." . PHP_EOL;
    } elseif ($duration >= 1.5) {
        echo "- The project is responding slowly." . PHP_EOL;
    } else {
        echo "- The project response is healthy." . PHP_EOL;
    }
} catch (\Throwable $exception) {
    $duration = microtime(true) - $start;
    echo "Response Time: " . formatMilliseconds($duration) . PHP_EOL;
    echo "Error: " . $exception->getMessage() . PHP_EOL;
    echo PHP_EOL . "Quick Interpretation:" . PHP_EOL;
    echo "- The project health endpoint is not reachable." . PHP_EOL;
}

echo str_repeat('=', 60) . PHP_EOL;
