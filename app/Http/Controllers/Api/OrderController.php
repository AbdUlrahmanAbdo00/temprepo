<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateOrderSummaryJob;
use App\Services\CheckoutService;
use Illuminate\Contracts\Cache\LockTimeoutException;
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

            $tSummary = microtime(true);
            try {
                dispatch(new GenerateOrderSummaryJob($order));
            } catch (Throwable $throwable) {
                report($throwable);
            }

            // Merge the controller-level summary-job dispatch into the profile (Req 10)
            $timings = $this->checkoutService->timings;
            $timings['dispatch2_ms'] = round((microtime(true) - $tSummary) * 1000, 2);

            return response()->json([
                'message' => 'Checkout completed.',
                'data' => $order,
            ], 201, [
                // Req 10: full per-stage timing breakdown (ms) of the checkout
                'X-Checkout-Profile' => json_encode($timings),
            ]);
        } catch (LockTimeoutException $exception) {
            // High contention on a product's distributed lock (Req 7) — shed load
            // gracefully with 503 + Retry-After, NOT a 500 crash signal.
            return response()->json([
                'message' => 'System busy due to high demand — please retry shortly.',
            ], 503, ['Retry-After' => 3]);
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
