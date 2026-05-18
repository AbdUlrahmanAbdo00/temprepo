<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateOrderSummaryJob;
use App\Services\CheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/**
 * @OA\Tag(
 *     name="Orders",
 *     description="Order and checkout operations"
 * )
 */

class OrderController extends Controller
{
    public function __construct(private readonly CheckoutService $checkoutService)
    {
    }

    /**
     * @OA\Post(
     *     path="/api/checkout",
     *     tags={"Orders"},
        *     security={{"sanctum":{}}},
        *     summary="Checkout the current cart",
     *     @OA\Response(response=201, description="Checkout completed"),
     *     @OA\Response(response=422, description="Validation/Out of stock")
     * )
     */
    public function checkout(Request $request): JsonResponse
    {
        try {
            $order = $this->checkoutService->checkout($request->user());

            try {
                dispatch(new GenerateOrderSummaryJob($order));
            } catch (Throwable $throwable) {
                report($throwable);
            }

            return response()->json([
                'message' => 'Checkout completed.',
                'data' => $order,
            ], 201);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/orders",
     *     tags={"Orders"},
        *     security={{"sanctum":{}}},
     *     @OA\Response(response=200, description="List orders")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $orders = $request->user()
            ->orders()
            ->with('items.product')
            ->latest()
            ->get();

        return response()->json([
            'data' => $orders,
        ]);
    }
}
