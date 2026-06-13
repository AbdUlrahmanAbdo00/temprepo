<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class HeavyQueueTask implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $taskNumber,
        public readonly int $workMilliseconds = 1500,
    ) {
    }

    public function handle(): void
    {
        Log::info('Heavy queue task started', [
            'task_number' => $this->taskNumber,
            'work_milliseconds' => $this->workMilliseconds,
            'connection' => $this->connection,
            'queue' => $this->queue,
        ]);

        usleep($this->workMilliseconds * 1000);

        Log::info('Heavy queue task finished', [
            'task_number' => $this->taskNumber,
        ]);
    }
}