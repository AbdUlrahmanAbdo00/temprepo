<?php

namespace App\Jobs;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GenerateDailySalesReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying a failed job.
     */
    public int $backoff = 30;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        $this->onConnection('database');
        $this->onQueue('reports');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $report = $this->generateReport();

            $filename = "sales_report_" . now()->format('Y-m-d') . ".txt";
            Storage::disk('local')->put("reports/{$filename}", $report);

            Log::info('Daily sales report generated successfully', [
                'filename' => $filename,
                'generated_at' => now(),
            ]);
        } catch (\Exception $exception) {
            Log::error('Failed to generate daily sales report', [
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * Generate daily sales report using batch processing (chunking).
     */
    private function generateReport(): string
    {
        $today = now()->startOfDay();
        $tomorrow = now()->endOfDay();

        // Get summary statistics
        $totalOrders = Order::whereBetween('created_at', [$today, $tomorrow])->count();
        $totalSales = Order::whereBetween('created_at', [$today, $tomorrow])->sum('total_price');
        $averageOrderValue = $totalOrders > 0 ? $totalSales / $totalOrders : 0;

        Log::info("Starting Daily Sales Report generation. Total orders to process: {$totalOrders}");

        $report = "===============================================\n";
        $report .= "DAILY SALES REPORT\n";
        $report .= "Date: " . now()->format('Y-m-d') . "\n";
        $report .= "Generated: " . now()->format('Y-m-d H:i:s') . "\n";
        $report .= "===============================================\n\n";

        $report .= "SUMMARY STATISTICS:\n";
        $report .= "Total Orders: {$totalOrders}\n";
        $report .= "Total Sales: \${$totalSales}\n";
        $report .= "Average Order Value: \$" . number_format($averageOrderValue, 2) . "\n";
        $report .= "===============================================\n\n";

        $report .= "DETAILED ORDERS (Processed in Batches of 100):\n";
        $report .= "-----------------------------------------------\n";

        // Process orders in chunks of 100 (batch processing)
        $chunkNumber = 0;
        Order::whereBetween('created_at', [$today, $tomorrow])
            ->with('user', 'items.product')
            ->orderBy('created_at', 'desc')
            ->chunk(100, function ($orders) use (&$report, &$chunkNumber) {
                $chunkNumber++;
                
                Log::info("Processing Sales Report Batch - Chunk #{$chunkNumber} | Orders in this batch: " . $orders->count());

                $report .= "\nBatch #{$chunkNumber}:\n";

                foreach ($orders as $order) {
                    $report .= "Order ID: {$order->id} | ";
                    $report .= "Customer: {$order->user->name} | ";
                    $report .= "Total: \${$order->total_price} | ";
                    $report .= "Items: {$order->items->count()} | ";
                    $report .= "Status: {$order->status}\n";
                }
            });

        $report .= "\n===============================================\n";
        $report .= "End of Report\n";
        $report .= "===============================================\n";

        return $report;
    }
}