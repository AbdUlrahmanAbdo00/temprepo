<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Jobs\HeavyQueueTask;

$jobCount = max(1, (int) ($argv[1] ?? 20));
$workMilliseconds = max(100, (int) ($argv[2] ?? 1500));
$queueConnection = $argv[3] ?? config('queue.default');
$queueName = $argv[4] ?? 'capacity-test';

echo "Queue capacity test\n";
echo "Jobs: {$jobCount}\n";
echo "Work per job: {$workMilliseconds} ms\n";
echo "Connection: {$queueConnection}\n";
echo "Queue: {$queueName}\n";
echo "---\n";

if ($queueConnection === 'sync') {
    echo "Warning: sync executes jobs inline, so this measures request blocking rather than background processing.\n\n";
}

$startedAt = microtime(true);

for ($i = 1; $i <= $jobCount; $i++) {
    HeavyQueueTask::dispatch($i, $workMilliseconds)
        ->onConnection($queueConnection)
        ->onQueue($queueName);
}

$dispatchSeconds = microtime(true) - $startedAt;

echo "Dispatch time: " . number_format($dispatchSeconds, 3) . " seconds\n";

if ($queueConnection !== 'sync') {
    echo "\nRun a worker in another terminal to process the queue:\n";
    echo "php artisan queue:work {$queueConnection} --queue={$queueName} --sleep=1 --tries=1\n";
    echo "\nSuggested comparison:\n";
    echo "- sync: dispatch time grows with job count because each job runs immediately\n";
    echo "- database/redis: dispatch time stays short while jobs are processed in background\n";
}

echo "\nTip: check storage/logs/laravel.log to see each job start and finish.\n";