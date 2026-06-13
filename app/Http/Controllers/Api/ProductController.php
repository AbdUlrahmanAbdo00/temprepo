<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * @OA\Tag(
 *     name="Products",
 *     description="Product operations"
 * )
 */
class ProductController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/products",
     *     tags={"Products"},
     *     security={{"sanctum":{}}},
     *     @OA\Response(response=200, description="List products")
     * )
     */
    public function index(): JsonResponse
    {
        // Cache the full product listing for 5 minutes (Req 6 — Distributed Caching)
        $products = Cache::remember('products:all', now()->addMinutes(5), function () {
            return Product::query()->latest()->get();
        });

        return response()->json([
            'data' => $products,
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/products/{id}",
     *     tags={"Products"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Show product")
     * )
     */
    public function show(int $id): JsonResponse
    {
        // Cache individual product for 10 minutes (Req 6 — Distributed Caching)
        $product = Cache::remember("product:{$id}", now()->addMinutes(10), function () use ($id) {
            return Product::findOrFail($id);
        });

        return response()->json([
            'data' => $product,
        ]);
    }
}
