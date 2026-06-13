<?php

namespace App\Jobs;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class GenerateOrderSummary implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $orderId)
    {
    }

    public function handle(): void
    {
        $order = Order::query()->with(['user', 'items.product'])->findOrFail($this->orderId);

        usleep(750000);

        $lines = [
            'Order Summary',
            'Order ID: ' . $order->id,
            'User: ' . ($order->user?->name ?? 'Unknown'),
            'Status: ' . $order->status,
            'Total Price: ' . $order->total_price,
            'Created At: ' . $order->created_at,
            '',
            'Items:',
        ];

        foreach ($order->items as $item) {
            $lines[] = sprintf(
                '- %s x %d @ %s',
                $item->product?->name ?? 'Unknown product',
                $item->quantity,
                $item->price_at_purchase,
            );
        }

        $path = 'order-summaries/order-' . $order->id . '.txt';

        Storage::disk('local')->put($path, implode(PHP_EOL, $lines));
    }
}