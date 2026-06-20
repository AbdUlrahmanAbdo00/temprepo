<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Req 10 instrumentation — counts DB queries per request, sums their time, and
 * records the slowest one. Exposed as response headers (no logic changed):
 *   X-Q-Count    — number of SQL queries in this request (N+1 detector)
 *   X-Q-Total-Ms — total SQL time (ms)
 *   X-Q-Max-Ms   — slowest single query (ms)
 */
class ProfileDbQueries
{
    public function handle(Request $request, Closure $next)
    {
        $count = 0;
        $total = 0.0;
        $max = 0.0;

        DB::listen(function ($query) use (&$count, &$total, &$max) {
            $count++;
            $total += $query->time;          // milliseconds
            if ($query->time > $max) {
                $max = $query->time;
            }
        });

        $response = $next($request);

        // $response->headers is a public ResponseHeaderBag property (not a method).
        $response->headers->set('X-Q-Count', (string) $count);
        $response->headers->set('X-Q-Total-Ms', (string) round($total, 1));
        $response->headers->set('X-Q-Max-Ms', (string) round($max, 1));

        return $response;
    }
}
