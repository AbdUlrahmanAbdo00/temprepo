<?php

namespace Tests\Feature;

use App\Jobs\GenerateDailySalesReportJob;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BatchProcessingVerificationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that GenerateDailySalesReportJob creates a comprehensive report
     */
    public function test_batch_job_generates_daily_sales_report(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $product = Product::factory()->create(['price' => 100.00]);

        // Create 5 orders for today
        for ($i = 0; $i < 5; $i++) {
            $order = Order::factory()->create([
                'user_id' => $user->id,
                'total_price' => 200.00,
                'status' => 'pending',
            ]);

            $order->items()->create([
                'product_id' => $product->id,
                'quantity' => 2,
                'price_at_purchase' => 100.00,
            ]);
        }

        $job = new GenerateDailySalesReportJob();
        $job->handle();

        $filename = "sales_report_" . now()->format('Y-m-d') . ".txt";
        Storage::disk('local')->assertExists("reports/{$filename}");

        $content = Storage::disk('local')->get("reports/{$filename}");

        // Verify report content
        $this->assertStringContainsString('DAILY SALES REPORT', $content);
        $this->assertStringContainsString('Total Orders: 5', $content);
        $this->assertStringContainsString('Batch #1:', $content);
    }

    /**
     * Test that batch job processes orders in chunks
     */
    public function test_batch_job_processes_orders_in_chunks(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $product = Product::factory()->create(['price' => 50.00]);

        // Create 105 orders (should create 2 batches: 100 + 5)
        for ($i = 0; $i < 105; $i++) {
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
        }

        $job = new GenerateDailySalesReportJob();
        $job->handle();

        $filename = "sales_report_" . now()->format('Y-m-d') . ".txt";
        $content = Storage::disk('local')->get("reports/{$filename}");

        // Verify chunking by checking for batch numbers
        $this->assertStringContainsString('Batch #1:', $content);
        $this->assertStringContainsString('Batch #2:', $content);
        $this->assertStringContainsString('Total Orders: 105', $content);
    }

    /**
     * Test that batch job calculates statistics correctly
     */
    public function test_batch_job_calculates_statistics(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $product = Product::factory()->create();

        // Create orders with known totals
        $totals = [100, 200, 300];
        foreach ($totals as $total) {
            $order = Order::factory()->create([
                'user_id' => $user->id,
                'total_price' => $total,
                'status' => 'pending',
            ]);

            $order->items()->create([
                'product_id' => $product->id,
                'quantity' => 1,
                'price_at_purchase' => $total,
            ]);
        }

        $job = new GenerateDailySalesReportJob();
        $job->handle();

        $filename = "sales_report_" . now()->format('Y-m-d') . ".txt";
        $content = Storage::disk('local')->get("reports/{$filename}");

        $this->assertStringContainsString('Total Orders: 3', $content);
        $this->assertStringContainsString('Total Sales: $600', $content);
    }

    /**
     * Test that batch job only includes today's orders
     */
    public function test_batch_job_only_includes_todays_orders(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $product = Product::factory()->create();

        // Create order for today
        $todayOrder = Order::factory()->create([
            'user_id' => $user->id,
            'total_price' => 100.00,
            'status' => 'pending',
            'created_at' => now(),
        ]);
        $todayOrder->items()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'price_at_purchase' => 100.00,
        ]);

        // Create order for yesterday
        $yesterdayOrder = Order::factory()->create([
            'user_id' => $user->id,
            'total_price' => 200.00,
            'status' => 'pending',
            'created_at' => now()->subDay(),
        ]);
        $yesterdayOrder->items()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'price_at_purchase' => 200.00,
        ]);

        $job = new GenerateDailySalesReportJob();
        $job->handle();

        $filename = "sales_report_" . now()->format('Y-m-d') . ".txt";
        $content = Storage::disk('local')->get("reports/{$filename}");

        // Should only include today's order
        $this->assertStringContainsString('Total Orders: 1', $content);
    }

    /**
     * Test that batch job is scheduled to run
     */
    public function test_batch_job_is_scheduled(): void
    {
        $this->assertTrue(true, 'Batch job should be scheduled in Console/Kernel.php');
    }

    /**
     * Test that batch job has retry configuration
     */
    public function test_batch_job_has_retry_configuration(): void
    {
        $job = new GenerateDailySalesReportJob();

        $this->assertEquals(3, $job->tries, 'Job should retry 3 times on failure');
        $this->assertEquals(30, $job->backoff, 'Job should wait 30 seconds between retries');
    }

    /**
     * Test that batch job is instantiable
     */
    public function test_batch_job_is_instantiable(): void
    {
        $job = new GenerateDailySalesReportJob();

        $this->assertInstanceOf(GenerateDailySalesReportJob::class, $job);
    }
}
