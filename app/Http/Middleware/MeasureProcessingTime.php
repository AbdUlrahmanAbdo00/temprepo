<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Req 9/10 instrumentation — measures the in-PHP processing time of each request
 * and exposes it as the `X-Process-Time-Ms` response header.
 *
 * The load tester (k6) already measures the TOTAL request duration. Comparing the
 * two pinpoints where time is spent:
 *   total ≈ X-Process-Time  → the work itself is slow (DB / Redis / app logic)
 *   total ≫ X-Process-Time  → the request waited in the web-server accept queue
 *                             (i.e., the bottleneck is Apache concurrency, not the work)
 */
class MeasureProcessingTime
{
    public function handle(Request $request, Closure $next)
    {
        $start = microtime(true);

        $response = $next($request);

        $ms = round((microtime(true) - $start) * 1000, 1);
        $response->headers->set('X-Process-Time-Ms', (string) $ms);

        return $response;
    }
}
