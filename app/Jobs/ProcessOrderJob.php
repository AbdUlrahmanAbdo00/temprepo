<?php

namespace App\Jobs;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
// use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(protected Order $order)
    {
        $this->onQueue('orders');
    }

    public function handle(): void
    {
        try {
            $order = $this->order->loadMissing('items.product', 'user');

            Log::info('Processing order in background', [
                'order_id' => $order->id,
                'status_before' => $order->status,
            ]);

            if ($order->status === 'pending') {
                $order->status = 'processing';
                $order->save();
            }

            Log::info('Order processed successfully', [
                'order_id' => $order->id,
                'status_after' => $order->status,
            ]);
        } catch (\Throwable $throwable) {
            Log::error('Failed to process order', [
                'order_id' => $this->order->id,
                'error' => $throwable->getMessage(),
            ]);

            throw $throwable;
        }
    }
}