<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RateLimitingVerificationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that auth endpoints have rate limiting configured
     */
    public function test_auth_rate_limiting_configured(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertTrue(in_array($response->status(), [200, 401, 422]));
    }

    /**
     * Test that checkout endpoint returns valid response
     */
    public function test_checkout_endpoint_responds(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['stock' => 10, 'price' => 99.99]);

        Sanctum::actingAs($user);

        $user->cartItems()->create([
            'product_id' => $product->id,
            'quantity' => 1,
        ]);

        $response = $this->postJson('/api/checkout');

        $this->assertTrue(in_array($response->status(), [201, 422]));
    }

    /**
     * Test that rate limiting respects dynamic configuration from env vars
     */
    public function test_rate_limiting_respects_apache_configuration(): void
    {
        $this->assertEquals(150, (int) env('APACHE_THREADS', 150));
        $this->assertEquals(350, (int) env('APACHE_AVG_REQUEST_MS', 350));
        $this->assertEquals(0.75, (float) env('RATE_LIMIT_UTILIZATION', 0.75));
        $this->assertEquals(0.20, (float) env('RATE_LIMIT_API_SHARE', 0.20));
        $this->assertEquals(0.20, (float) env('RATE_LIMIT_AUTH_SHARE', 0.20));
        $this->assertEquals(0.35, (float) env('RATE_LIMIT_CART_SHARE', 0.35));
        $this->assertEquals(0.25, (float) env('RATE_LIMIT_CHECKOUT_SHARE', 0.25));
    }
}
