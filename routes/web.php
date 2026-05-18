<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

Route::get('/health', function () {
    $databaseStatus = 'ok';
    $cacheStatus = 'ok';

    try {
        DB::select('select 1');
    } catch (\Throwable $exception) {
        $databaseStatus = 'error';
    }

    try {
        Cache::put('health_check', now()->timestamp, 10);
        Cache::get('health_check');
    } catch (\Throwable $exception) {
        $cacheStatus = 'error';
    }

    return response()->json([
        'app' => config('app.name'),
        'status' => $databaseStatus === 'ok' && $cacheStatus === 'ok' ? 'ok' : 'degraded',
        'database' => $databaseStatus,
        'cache' => $cacheStatus,
        'environment' => config('app.env'),
        'timestamp' => now()->toIso8601String(),
    ]);
});

Route::get('/server-info', function () {
    return response()->json([
        'app' => config('app.name'),
        'environment' => config('app.env'),
        'php_sapi' => php_sapi_name(),
        'process_id' => function_exists('getmypid') ? getmypid() : null,
        'server_software' => request()->server('SERVER_SOFTWARE'),
        'server_name' => request()->server('SERVER_NAME'),
        'server_port' => request()->server('SERVER_PORT'),
        'host' => request()->getHost(),
        'scheme' => request()->getScheme(),
        'x_forwarded_for' => request()->header('X-Forwarded-For'),
        'x_forwarded_host' => request()->header('X-Forwarded-Host'),
        'x_forwarded_port' => request()->header('X-Forwarded-Port'),
        'x_forwarded_proto' => request()->header('X-Forwarded-Proto'),
        'timestamp' => now()->toIso8601String(),
    ]);
});
