<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * @OA\Tag(
 *     name="Testing",
 *     description="Testing endpoints (demo race condition)"
 * )
 */
class TestCheckoutController extends Controller
{
    /**
     * @OA\Post(
     *     path="/api/test/checkout",
     *     tags={"Testing"},
     *     summary="Direct checkout for testing (no auth needed)",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"user_id","product_id","quantity"},
     *             @OA\Property(property="user_id", type="integer", example=1),
     *             @OA\Property(property="product_id", type="integer", example=1),
     *             @OA\Property(property="quantity", type="integer", example=1)
     *         )
     *     ),
     *     @OA\Response(response=201, description="Checkout completed"),
     *     @OA\Response(response=422, description="Out of stock or invalid")
     * )
     */
    public function checkout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $user = User::findOrFail($validated['user_id']);
        $product = Product::findOrFail($validated['product_id']);

        try {
            Log::info('Test checkout started', [
                'user_id' => $user->id,
                'product_id' => $product->id,
                'stock_before' => $product->stock,
                'quantity' => $validated['quantity'],
            ]);

            // UNSAFE: Race condition happens here
            if ($product->stock < $validated['quantity']) {
                throw new RuntimeException('Out of stock for product: ' . $product->name);
            }

            $product->stock = $product->stock - $validated['quantity'];
            $product->save();

            Log::info('Stock updated during test checkout', [
                'user_id' => $user->id,
                'product_id' => $product->id,
                'stock_after' => $product->stock,
            ]);

            $order = Order::create([
                'user_id' => $user->id,
                'total_price' => $product->price * $validated['quantity'],
                'status' => 'pending',
            ]);

            $order->items()->create([
                'product_id' => $product->id,
                'quantity' => $validated['quantity'],
                'price_at_purchase' => $product->price,
            ]);

            Log::info('Order created in test checkout', [
                'user_id' => $user->id,
                'order_id' => $order->id,
                'product_id' => $product->id,
            ]);

            return response()->json([
                'message' => 'Checkout completed.',
                'data' => [
                    'order_id' => $order->id,
                    'user_id' => $user->id,
                    'product_id' => $product->id,
                    'quantity' => $validated['quantity'],
                    'stock_after' => $product->fresh()->stock,
                ],
            ], 201);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }
}
