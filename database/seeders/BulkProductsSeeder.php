<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class BulkProductsSeeder extends Seeder
{
    public function run(): void
    {
        $batchSize = 500;
        $total     = 1500;

        for ($i = 0; $i < $total / $batchSize; $i++) {
            Product::factory($batchSize)->create();
            $this->command->info("Batch " . ($i + 1) . ": {$batchSize} products created.");
        }

        $this->command->info("Done — {$total} products created for cache benchmark.");
    }
}
