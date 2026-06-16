<?php

namespace App\Services;

use App\Jobs\ProcessOrderJob;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class CheckoutService
{
    public function checkout(User $user): Order
    {
        $cartItems = $user->cartItems()->with('product')->get();

        if ($cartItems->isEmpty()) {
            throw new RuntimeException('Cart is empty.');
        }

        $totalPrice = 0;
        $orderItemsPayload = [];

        // Req 7 — Distributed (pessimistic) lock via Redis (Cache::lock), one per product.
        // Product IDs are sorted so every request acquires locks in the SAME order, which
        // prevents deadlock when two carts share products. A Redis lock is shared across ALL
        // app servers — unlike a DB row lock or an in-process mutex — which is exactly what
        // makes mutual exclusion hold under the load balancer (Req 5).
        $productIds = $cartItems->pluck('product_id')->unique()->sort()->values();

        $locks = [];
        foreach ($productIds as $lockProductId) {
            $lock = Cache::lock("lock:product:{$lockProductId}", 10);

            if (! $lock->block(5)) {
                foreach ($locks as $acquired) {
                    $acquired->release();
                }
                throw new RuntimeException("System busy, please retry — product ID: {$lockProductId}");
            }

            $locks[] = $lock;
        }

        try {
            // Req 8 — single ACID transaction, executed WHILE the distributed locks are held.
            $order = DB::transaction(function () use ($user, $cartItems, &$totalPrice, &$orderItemsPayload) {
                foreach ($cartItems as $cartItem) {
                    Log::info('Checkout started for user', [
                        'user_id'    => $user->id,
                        'product_id' => $cartItem->product_id,
                        'quantity'   => $cartItem->quantity,
                    ]);

                    // Req 1 — conditional atomic decrement: merges check + decrement into one
                    // indivisible statement (lock-free), preventing overselling between concurrent requests.
                    $updated = Product::where('id', $cartItem->product_id)
                        ->where('stock', '>=', $cartItem->quantity)
                        ->decrement('stock', $cartItem->quantity);

                    if (! $updated) {
                        throw new RuntimeException(
                            'Out of stock or insufficient inventory for product ID: ' . $cartItem->product_id
                        );
                    }

                    Log::info('Product stock updated during checkout', [
                        'user_id'    => $user->id,
                        'product_id' => $cartItem->product_id,
                        'quantity'   => $cartItem->quantity,
                    ]);

                    $totalPrice += $cartItem->product->price * $cartItem->quantity;
                    $orderItemsPayload[] = [
                        'product_id'        => $cartItem->product->id,
                        'quantity'          => $cartItem->quantity,
                        'price_at_purchase' => $cartItem->product->price,
                    ];
                }

                $order = Order::query()->create([
                    'user_id'     => $user->id,
                    'total_price' => $totalPrice,
                    'status'      => 'pending',
                ]);

                $order->items()->createMany($orderItemsPayload);
                $user->cartItems()->delete();

                Log::info('Order created successfully', [
                    'user_id'     => $user->id,
                    'order_id'    => $order->id,
                    'total_price' => $order->total_price,
                ]);

                return $order;
            });
        } finally {
            // Always release the distributed locks — even if the transaction threw.
            foreach ($locks as $lock) {
                $lock->release();
            }
        }

        // Invalidate the individual product cache AFTER the transaction commits (Req 6).
        // The paginated listing is display-only and relies on its short TTL — the
        // authoritative stock check happens above via the atomic decrement (Req 1),
        // so a few seconds of listing staleness can never cause overselling.
        foreach ($cartItems as $cartItem) {
            Cache::forget("product:{$cartItem->product_id}");
        }

        try {
            ProcessOrderJob::dispatch($order);
        } catch (\Throwable $throwable) {
            Log::error('Failed to dispatch ProcessOrderJob', [
                'order_id' => $order->id,
                'error'    => $throwable->getMessage(),
            ]);
        }

        return $order->load('items.product');
    }
}
