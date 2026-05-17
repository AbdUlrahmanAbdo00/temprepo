<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;

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
        return response()->json([
            'data' => Product::query()->latest()->get(),
        ]);
    }

    /**
     * @OA\Get(
        *     path="/api/products/{product}",
     *     tags={"Products"},
        *     security={{"sanctum":{}}},
        *     @OA\Parameter(name="product", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Show product")
     * )
     */
    public function show(Product $product): JsonResponse
    {
        return response()->json([
            'data' => $product,
        ]);
    }
}
