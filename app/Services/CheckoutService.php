<?php

namespace App\Services;

use App\Jobs\ProcessOrderJob;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class CheckoutService
{
    /** Per-request stage timings in ms (lock acquisition, transaction) — Req 10 instrumentation. */
    public array $timings = [];

    public function checkout(User $user, int $attempt = 0): Order
    {
        // === Req 10 instrumentation — measure each stage (no logic/queries changed) ===
        $tCart = microtime(true);
        $cartItems = $user->cartItems()->with('product')->get();
        $this->timings['cart_load_ms'] = round((microtime(true) - $tCart) * 1000, 2);

        if ($cartItems->isEmpty()) {
            throw new RuntimeException('Cart is empty.');
        }

        $totalPrice = 0;
        $orderItemsPayload = [];

        $productIds = $cartItems->pluck('product_id')->unique()->sort()->values();

        // Req 7 — distributed lock via Redis (sorted ids → no deadlock; works across servers).
        $lockStart = microtime(true);
        $locks = [];
        try {
            foreach ($productIds as $lockProductId) {
                $lock = Cache::lock("lock:product:{$lockProductId}", 10);
                $lock->block(1);
                $locks[] = $lock;
            }
        } catch (LockTimeoutException $exception) {
            foreach ($locks as $acquired) {
                $acquired->release();
            }
            // Retry up to 2 times with random jitter (50–200ms) to break thundering herd.
            if ($attempt < 2) {
                usleep(random_int(50_000, 200_000));
                return $this->checkout($user, $attempt + 1);
            }
            throw $exception;
        }
        $this->timings['lock_ms'] = round((microtime(true) - $lockStart) * 1000, 2);

        $decrementMs = 0.0;
        $orderCreateMs = 0.0;
        $itemsCreateMs = 0.0;
        $cartDeleteMs = 0.0;

        $txnStart = microtime(true);
        try {
            // Req 8 — single ACID transaction, executed WHILE the distributed locks are held.
            $order = DB::transaction(function () use ($user, $cartItems, &$totalPrice, &$orderItemsPayload, &$decrementMs, &$orderCreateMs, &$itemsCreateMs, &$cartDeleteMs) {
                foreach ($cartItems as $cartItem) {
                    $product = $cartItem->product;

                    // Req 1 — conditional atomic decrement (lock-free), prevents overselling.
                    $ds = microtime(true);
                    $updated = Product::where('id', $cartItem->product_id)
                        ->where('stock', '>=', $cartItem->quantity)
                        ->decrement('stock', $cartItem->quantity);
                    $decrementMs += (microtime(true) - $ds) * 1000;

                    if (! $updated) {
                        throw new RuntimeException(
                            'Out of stock or insufficient inventory for product ID: ' . $cartItem->product_id
                        );
                    }

                    $totalPrice += $product->price * $cartItem->quantity;
                    $orderItemsPayload[] = [
                        'product_id'        => $product->id,
                        'quantity'          => $cartItem->quantity,
                        'price_at_purchase' => $product->price,
                    ];
                }

                $os = microtime(true);
                $order = Order::query()->create([
                    'user_id'     => $user->id,
                    'total_price' => $totalPrice,
                    'status'      => 'pending',
                ]);
                $orderCreateMs = (microtime(true) - $os) * 1000;

                $is = microtime(true);
                $order->items()->createMany($orderItemsPayload);
                $itemsCreateMs = (microtime(true) - $is) * 1000;

                $del = microtime(true);
                $user->cartItems()->delete();
                $cartDeleteMs = (microtime(true) - $del) * 1000;

                return $order;
            });
        } finally {
            foreach ($locks as $lock) {
                $lock->release();
            }
        }
        $this->timings['txn_ms'] = round((microtime(true) - $txnStart) * 1000, 2);
        $this->timings['decrement_ms'] = round($decrementMs, 2);
        $this->timings['order_create_ms'] = round($orderCreateMs, 2);
        $this->timings['items_create_ms'] = round($itemsCreateMs, 2);
        $this->timings['cart_delete_ms'] = round($cartDeleteMs, 2);
        // commit_overhead = transaction time minus all measured sub-operations
        $this->timings['commit_overhead_ms'] = round(
            $this->timings['txn_ms'] - $decrementMs - $orderCreateMs - $itemsCreateMs - $cartDeleteMs,
            2
        );

        // Req 6 — invalidate product cache for all cart items in one batch.
        $tCache = microtime(true);
        Cache::deleteMultiple(
            $cartItems->pluck('product_id')->map(fn($id) => "product:{$id}")->all()
        );
        $this->timings['cache_forget_ms'] = round((microtime(true) - $tCache) * 1000, 2);

        // Load relations before dispatch so the response is ready independently of dispatch latency.
        $tLoad = microtime(true);
        $result = $order->load('items.product');
        $this->timings['order_load_ms'] = round((microtime(true) - $tLoad) * 1000, 2);

        $this->timings['log_ms'] = 0.0;
        $this->timings['log_count'] = 0;

        // Req 3 — dispatch heavy work to the queue.
        $tDisp = microtime(true);
        try {
            ProcessOrderJob::dispatch($order);
        } catch (\Throwable $throwable) {
            Log::error('Failed to dispatch ProcessOrderJob', [
                'order_id' => $order->id,
                'error'    => $throwable->getMessage(),
            ]);
        }
        $this->timings['dispatch_ms'] = round((microtime(true) - $tDisp) * 1000, 2);

        return $result;
    }
}
