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
