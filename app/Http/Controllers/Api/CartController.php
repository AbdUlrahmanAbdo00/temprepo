<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @OA\Tag(
 *     name="Cart",
 *     description="Cart operations"
 * )
 */

class CartController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/cart",
     *     tags={"Cart"},
        *     security={{"sanctum":{}}},
     *     @OA\Response(response=200, description="List cart items")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $items = $request->user()
            ->cartItems()
            ->with('product')
            ->latest()
            ->get();

        return response()->json([
            'data' => $items,
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/cart/add",
     *     tags={"Cart"},
        *     security={{"sanctum":{}}},
        *     summary="Add a product to the cart",
        *     @OA\RequestBody(
        *         required=true,
        *         @OA\JsonContent(
        *             required={"product_id","quantity"},
        *             @OA\Property(property="product_id", type="integer", example=1),
        *             @OA\Property(property="quantity", type="integer", example=1)
        *         )
        *     ),
     *     @OA\Response(response=201, description="Added to cart")
     * )
     */
    public function add(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $product = Product::query()->findOrFail($validated['product_id']);

        $cartItem = CartItem::query()->firstOrNew([
            'user_id' => $request->user()->id,
            'product_id' => $product->id,
        ]);

        $cartItem->quantity = ($cartItem->quantity ?? 0) + $validated['quantity'];
        $cartItem->save();

        return response()->json([
            'message' => 'Item added to cart.',
            'data' => $cartItem->load('product'),
        ], 201);
    }

    /**
     * @OA\Delete(
     *     path="/api/cart/item",
     *     tags={"Cart"},
        *     security={{"sanctum":{}}},
        *     summary="Remove a product from the cart",
        *     @OA\RequestBody(
        *         required=true,
        *         @OA\JsonContent(
        *             required={"product_id"},
        *             @OA\Property(property="product_id", type="integer", example=1)
        *         )
        *     ),
     *     @OA\Response(response=200, description="Removed from cart")
     * )
     */
    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
        ]);

        $deleted = CartItem::query()
            ->where('user_id', $request->user()->id)
            ->where('product_id', $validated['product_id'])
            ->delete();

        return response()->json([
            'message' => $deleted > 0 ? 'Cart item removed.' : 'Cart item not found.',
        ]);
    }
}
