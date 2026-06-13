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

        // Wrap all DB operations in a single atomic transaction (ACID - Req 8)
        $order = DB::transaction(function () use ($user, $cartItems, &$totalPrice, &$orderItemsPayload) {
            foreach ($cartItems as $cartItem) {
                Log::info('Checkout started for user', [
                    'user_id'    => $user->id,
                    'product_id' => $cartItem->product_id,
                    'quantity'   => $cartItem->quantity,
                ]);

                // Conditional atomic decrement — merges check + decrement into one
                // indivisible query, preventing race conditions between concurrent requests (Req 1 & 7)
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

        // Invalidate product cache AFTER the transaction commits successfully.
        // Cache::forget cannot participate in a DB transaction, so placing it here
        // ensures we only evict stale entries when the stock change is permanent (Req 6).
        foreach ($cartItems as $cartItem) {
            Cache::forget("product:{$cartItem->product_id}");
        }
        Cache::forget('products:all');

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
