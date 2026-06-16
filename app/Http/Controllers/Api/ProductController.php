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
        // Paginate to keep each response small — fixes the large-payload bottleneck (Req 10).
        // Each page is cached independently with a short TTL (Req 6 — distributed caching).
        // No active invalidation of the listing: it is display-only, and the authoritative
        // stock check happens at checkout via an atomic decrement on the DB (Req 1),
        // so a few seconds of listing staleness can never cause overselling.
        $page = request()->integer('page', 1);

        $products = Cache::remember("products:page:{$page}", now()->addMinutes(5), function () {
            return Product::query()->latest()->paginate(20);
        });

        return response()->json($products);
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
        // Single query on cache miss — keep the cached path as light as possible (Req 6)
        $product = Cache::remember("product:{$id}", now()->addMinutes(10), function () use ($id) {
            return Product::findOrFail($id);
        });

        return response()->json([
            'data' => $product,
        ]);
    }
}
