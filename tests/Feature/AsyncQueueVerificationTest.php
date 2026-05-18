<?php

namespace Tests\Feature;

use App\Jobs\GenerateOrderSummaryJob;
use App\Jobs\ProcessOrderJob;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AsyncQueueVerificationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that checkout dispatches both ProcessOrderJob and GenerateOrderSummaryJob
     */
    public function test_checkout_dispatches_both_async_jobs(): void
    {
        Queue::fake();
        Storage::fake('local');

        $this->artisan('migrate --force');

        $user = User::factory()->create();
        $product = Product::factory()->create(['stock' => 100, 'price' => 99.99]);

        Sanctum::actingAs($user);

        $user->cartItems()->create([
            'product_id' => $product->id,
            'quantity' => 2,
        ]);

        $response = $this->postJson('/api/checkout');

        $response->assertCreated();
        $response->assertJsonPath('message', 'Checkout completed.');

        Queue::assertPushed(ProcessOrderJob::class);
        Queue::assertPushed(GenerateOrderSummaryJob::class);
    }

    /**
     * Test that queue connection is configured
     */
    public function test_queue_connection_is_configured(): void
    {
        $connection = config('queue.default');
        $this->assertIsString($connection);
        $this->assertNotNull($connection);
    }

    /**
     * Test that jobs table exists after migration
     */
    public function test_jobs_table_exists(): void
    {
        $this->artisan('migrate --force');

        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasTable('jobs'),
            'Jobs table should exist after migration'
        );
    }

    /**
     * Test that GenerateOrderSummaryJob creates a summary file
     */
    public function test_generate_order_summary_job_creates_file(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $product = Product::factory()->create(['price' => 50.00]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'total_price' => 100.00,
            'status' => 'pending',
        ]);

        $order->items()->create([
            'product_id' => $product->id,
            'quantity' => 2,
            'price_at_purchase' => 50.00,
        ]);

        $job = new GenerateOrderSummaryJob($order);
        $job->handle();

        Storage::disk('local')->assertExists("orders/order_{$order->id}_summary.txt");

        $content = Storage::disk('local')->get("orders/order_{$order->id}_summary.txt");
        $this->assertStringContainsString('ORDER SUMMARY', $content);
        $this->assertStringContainsString($order->id, $content);
    }

    /**
     * Test that ProcessOrderJob updates order status
     */
    public function test_process_order_job_updates_order_status(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
        ]);

        $order->items()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'price_at_purchase' => 99.99,
        ]);

        $this->assertEquals('pending', $order->status);

        $job = new ProcessOrderJob($order);
        $job->handle();

        $order->refresh();
        $this->assertEquals('processing', $order->status);
    }

    /**
     * Test that queue workers can be started (configuration check)
     */
    public function test_queue_worker_configuration(): void
    {
        $this->assertTrue(
            config('queue.connections.database') !== null,
            'Database queue connection should be configured'
        );

        $this->assertEquals('jobs', config('queue.connections.database.table'));
    }
}
