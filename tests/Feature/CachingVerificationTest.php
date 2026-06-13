<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Requirement 6 — Distributed Caching (Redis)
 *
 * Verifies that:
 *   1. Products listing and individual products are stored in cache after the first DB hit.
 *   2. Subsequent requests are served from cache (zero products SELECT to DB).
 *   3. Checkout invalidates the relevant cache keys so stale data is never returned.
 *
 * Note: phpunit.xml overrides CACHE_DRIVER=array so tests run without a Redis server.
 * The cache behaviour (remember / has / forget) is identical to Redis for logic purposes.
 * In production the driver is Redis (see CACHE_DRIVER=redis in .env).
 */
class CachingVerificationTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Configuration checks
    // -------------------------------------------------------------------------

    /** Redis is set as the production cache driver */
    public function test_redis_is_configured_as_production_cache_driver(): void
    {
        // Read the raw .env value directly — phpunit.xml overrides it to "array" for tests
        $envContent = file_get_contents(base_path('.env'));
        $this->assertStringContainsString('CACHE_DRIVER=redis', $envContent,
            'Production .env must set CACHE_DRIVER=redis');

        $this->assertStringContainsString('REDIS_CLIENT=predis', $envContent,
            'Production .env must set REDIS_CLIENT=predis');
    }

    /** predis package is installed */
    public function test_predis_package_is_installed(): void
    {
        $this->assertTrue(
            class_exists(\Predis\Client::class),
            'predis/predis must be installed via composer'
        );
    }

    // -------------------------------------------------------------------------
    // Products listing cache (GET /api/products)
    // -------------------------------------------------------------------------

    /** Cache miss on cold start, then cache is populated after first request */
    public function test_product_listing_cache_miss_then_hit(): void
    {
        $user = User::factory()->create();
        Product::factory()->count(5)->create();
        Sanctum::actingAs($user);

        Cache::forget('products:all');

        // MISS — cache should be empty before first request
        $this->assertFalse(Cache::has('products:all'), 'Cache should be empty before first request');

        $this->getJson('/api/products')->assertOk();

        // HIT — cache must be populated after first request
        $this->assertTrue(Cache::has('products:all'), 'Cache should be populated after first request');
    }

    /** Second request for product listing must not query the database */
    public function test_product_listing_second_request_does_not_hit_db(): void
    {
        $user = User::factory()->create();
        Product::factory()->count(5)->create();
        Sanctum::actingAs($user);

        Cache::forget('products:all');

        // First request — populates cache
        $this->getJson('/api/products')->assertOk();

        // Second request — measure DB queries
        DB::enableQueryLog();
        $this->getJson('/api/products')->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $productQueries = array_filter(
            $queries,
            fn ($q) => stripos($q['query'], 'from `products`') !== false
        );

        $this->assertCount(
            0,
            $productQueries,
            'Cache HIT: second request must not execute any SELECT on products table'
        );
    }

    /** Cold vs warm cache — DB query count comparison */
    public function test_db_queries_reduced_with_warm_cache(): void
    {
        $user = User::factory()->create();
        Product::factory()->count(10)->create();
        Sanctum::actingAs($user);

        Cache::forget('products:all');

        // Cold cache request
        DB::enableQueryLog();
        $this->getJson('/api/products')->assertOk();
        $coldProductQueries = count(array_filter(
            DB::getQueryLog(),
            fn ($q) => stripos($q['query'], 'from `products`') !== false
        ));
        DB::flushQueryLog();

        // Warm cache request
        $this->getJson('/api/products')->assertOk();
        $warmProductQueries = count(array_filter(
            DB::getQueryLog(),
            fn ($q) => stripos($q['query'], 'from `products`') !== false
        ));
        DB::disableQueryLog();

        $this->assertGreaterThan(0, $coldProductQueries, 'Cold cache must hit the DB');
        $this->assertEquals(0, $warmProductQueries,      'Warm cache must not hit the DB');
    }

    // -------------------------------------------------------------------------
    // Individual product cache (GET /api/products/{id})
    // -------------------------------------------------------------------------

    /** Cache miss then hit for a single product */
    public function test_individual_product_cache_miss_then_hit(): void
    {
        $user    = User::factory()->create();
        $product = Product::factory()->create();
        Sanctum::actingAs($user);

        $key = "product:{$product->id}";
        Cache::forget($key);

        $this->assertFalse(Cache::has($key), 'Cache should be empty before first request');

        $this->getJson("/api/products/{$product->id}")->assertOk();

        $this->assertTrue(Cache::has($key), 'Cache should be populated after first request');
    }

    /** Second request for single product must not query the database */
    public function test_individual_product_second_request_does_not_hit_db(): void
    {
        $user    = User::factory()->create();
        $product = Product::factory()->create();
        Sanctum::actingAs($user);

        $key = "product:{$product->id}";
        Cache::forget($key);

        // Warm up
        $this->getJson("/api/products/{$product->id}")->assertOk();

        DB::enableQueryLog();
        $this->getJson("/api/products/{$product->id}")->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $productQueries = array_filter(
            $queries,
            fn ($q) => stripos($q['query'], 'from `products`') !== false
        );

        $this->assertCount(
            0,
            $productQueries,
            'Cache HIT: second request must not SELECT from products table'
        );
    }

    /** Cached data must match what is stored in the database */
    public function test_cached_product_data_matches_database(): void
    {
        $user    = User::factory()->create();
        $product = Product::factory()->create(['name' => 'Redis Test Product', 'price' => 149.99]);
        Sanctum::actingAs($user);

        Cache::forget("product:{$product->id}");

        $response = $this->getJson("/api/products/{$product->id}")->assertOk();

        $this->assertEquals($product->name,  $response->json('data.name'));
        $this->assertEquals($product->price, $response->json('data.price'));
    }

    // -------------------------------------------------------------------------
    // Cache invalidation on checkout
    // -------------------------------------------------------------------------

    /** Checkout must clear both products:all and product:{id} cache keys */
    public function test_checkout_invalidates_product_cache(): void
    {
        $user    = User::factory()->create();
        $product = Product::factory()->create(['stock' => 10, 'price' => 50.00]);
        Sanctum::actingAs($user);

        // Warm up both cache keys
        $this->getJson('/api/products')->assertOk();
        $this->getJson("/api/products/{$product->id}")->assertOk();

        $this->assertTrue(Cache::has('products:all'),           'Listing cache should be warm');
        $this->assertTrue(Cache::has("product:{$product->id}"), 'Product cache should be warm');

        // Checkout
        $user->cartItems()->create(['product_id' => $product->id, 'quantity' => 1]);
        $this->postJson('/api/checkout')->assertCreated();

        // Both keys must be evicted
        $this->assertFalse(
            Cache::has('products:all'),
            'products:all cache must be cleared after checkout'
        );
        $this->assertFalse(
            Cache::has("product:{$product->id}"),
            "product:{$product->id} cache must be cleared after checkout"
        );
    }

    /** After cache eviction the next request re-populates cache from fresh DB data */
    public function test_cache_is_repopulated_after_invalidation(): void
    {
        $user    = User::factory()->create();
        $product = Product::factory()->create(['stock' => 5, 'price' => 30.00]);
        Sanctum::actingAs($user);

        // Warm up
        $this->getJson("/api/products/{$product->id}")->assertOk();
        $this->assertTrue(Cache::has("product:{$product->id}"));

        // Checkout clears the cache
        $user->cartItems()->create(['product_id' => $product->id, 'quantity' => 1]);
        $this->postJson('/api/checkout')->assertCreated();
        $this->assertFalse(Cache::has("product:{$product->id}"), 'Cache must be evicted after checkout');

        // Next read re-populates with updated stock
        $this->getJson("/api/products/{$product->id}")->assertOk();
        $this->assertTrue(Cache::has("product:{$product->id}"), 'Cache must be re-populated after next request');

        $cached = Cache::get("product:{$product->id}");
        $this->assertEquals(4, $cached['stock'] ?? $cached->stock, 'Cached stock must reflect the purchase');
    }
}
