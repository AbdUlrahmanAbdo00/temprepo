<?php

namespace Database\Seeders;

use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Prepares data for the Req 9 stress test (k6_stress_test.js).
 *
 * Three product tiers — each serves a different testing purpose:
 *
 *   ABUNDANT  (77%)  stock=100,000  → never runs out; baseline traffic
 *   SCARCE    (19%)  stock=20       → depletes mid-test; proves 422 at volume
 *   HOT       (4%)   stock=5        → depletes in first few iterations; proves
 *                                     early 422 and concurrent lock handling
 *
 * k6 weighted selection (deterministic, not random):
 *   10% of (VU, ITER) pairs → HOT
 *   20%                     → SCARCE
 *   70%                     → ABUNDANT
 *
 * With ~500 checkouts in a 90s run:
 *   50 hot-product hits → 10 products × stock=5 → all deplete → ~0-50 422s
 *   100 scarce hits     → 50 products × stock=20 → partial depletion
 *
 * Usage:
 *   php artisan db:seed --class=StressTestSeeder
 *   k6 run --env BASE_URL=http://localhost:8000 scripts/k6_stress_test.js
 */
class StressTestSeeder extends Seeder
{
    private const VU_COUNT = 100;
    private const PASSWORD = 'password123';

    // ── Product tiers ────────────────────────────────────────────────────────
    private const ABUNDANT_COUNT = 200;
    private const SCARCE_COUNT   =  50;
    private const HOT_COUNT      =  10;

    private const ABUNDANT_STOCK = 100000;
    private const SCARCE_STOCK   =  20;
    private const HOT_STOCK      =   5;

    public function run(): void
    {
        $this->command->info('=== StressTestSeeder ===');

        // ── 1. Users ──────────────────────────────────────────────────────────
        $vu = self::VU_COUNT;
        $this->command->info("Creating {$vu} users…");
        $users = [];

        for ($i = 1; $i <= self::VU_COUNT; $i++) {
            $email = "k6-user-{$i}@test.com";
            $user  = User::query()->updateOrCreate(
                ['email' => $email],
                ['name' => "K6 User {$i}", 'password' => Hash::make(self::PASSWORD)]
            );
            CartItem::query()->where('user_id', $user->id)->delete();
            $users[] = ['id' => $user->id, 'name' => $user->name, 'email' => $user->email];
        }

        $this->command->info('Users ready. Carts cleared.');

        // ── 2. Products ───────────────────────────────────────────────────────
        $products = [];

        // Abundant (77%)
        $a = self::ABUNDANT_COUNT;
        $this->command->info("Creating {$a} abundant products (stock=" . self::ABUNDANT_STOCK . ")…");
        for ($i = 1; $i <= self::ABUNDANT_COUNT; $i++) {
            $p = Product::query()->updateOrCreate(
                ['name' => "k6 abundant product {$i}"],
                [
                    'price'       => 50 + ($i % 50) * 5,
                    'stock'       => self::ABUNDANT_STOCK,
                    'description' => "Abundant #{$i} — high stock, baseline traffic.",
                ]
            );
            $products[] = [
                'id'    => $p->id,
                'name'  => $p->name,
                'price' => (float) $p->price,
                'type'  => 'abundant',
            ];
        }

        // Scarce (19%)
        $s  = self::SCARCE_COUNT;
        $ss = self::SCARCE_STOCK;
        $this->command->info("Creating {$s} scarce products (stock={$ss})…");
        for ($i = 1; $i <= self::SCARCE_COUNT; $i++) {
            $p = Product::query()->updateOrCreate(
                ['name' => "k6 scarce product {$i}"],
                [
                    'price'       => 100 + ($i % 10) * 10,
                    'stock'       => self::SCARCE_STOCK,
                    'description' => "Scarce #{$i} — limited stock, will deplete mid-test.",
                ]
            );
            $products[] = [
                'id'    => $p->id,
                'name'  => $p->name,
                'price' => (float) $p->price,
                'type'  => 'scarce',
            ];
        }

        // Hot (4%)
        $h  = self::HOT_COUNT;
        $hs = self::HOT_STOCK;
        $this->command->info("Creating {$h} hot products (stock={$hs})…");
        for ($i = 1; $i <= self::HOT_COUNT; $i++) {
            $p = Product::query()->updateOrCreate(
                ['name' => "k6 hot product {$i}"],
                [
                    'price'       => 200 + $i * 10,
                    'stock'       => self::HOT_STOCK,
                    'description' => "Hot #{$i} — very limited, depletes immediately under load.",
                ]
            );
            $products[] = [
                'id'    => $p->id,
                'name'  => $p->name,
                'price' => (float) $p->price,
                'type'  => 'hot',
            ];
        }

        // ── 3. Write k6-auth-data.json ─────────────────────────────────────────
        // NOT shuffled — k6 separates by type internally and applies weights.
        $outputFile = storage_path('app/k6-auth-data.json');
        $payload    = [
            'base_url' => env('K6_BASE_URL', 'http://localhost:8000'),
            'password' => self::PASSWORD,
            'products' => $products,
            'users'    => $users,
            '_meta'    => [
                'abundant_count' => self::ABUNDANT_COUNT,
                'scarce_count'   => self::SCARCE_COUNT,
                'hot_count'      => self::HOT_COUNT,
                'abundant_stock' => self::ABUNDANT_STOCK,
                'scarce_stock'   => self::SCARCE_STOCK,
                'hot_stock'      => self::HOT_STOCK,
                'k6_weights'     => '70% abundant / 20% scarce / 10% hot',
            ],
        ];
        file_put_contents($outputFile, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // ── 4. Summary ─────────────────────────────────────────────────────────
        $total = self::ABUNDANT_COUNT + self::SCARCE_COUNT + self::HOT_COUNT;

        $this->command->info('');
        $this->command->info("Done: {$total} products / {$vu} users");
        $this->command->info("  Abundant  : " . self::ABUNDANT_COUNT . " × stock=" . self::ABUNDANT_STOCK . "  (never runs out)");
        $this->command->info("  Scarce    : " . self::SCARCE_COUNT   . " × stock=" . self::SCARCE_STOCK   . "  (depletes mid-test → 422)");
        $this->command->info("  Hot       : " . self::HOT_COUNT      . " × stock=" . self::HOT_STOCK      . "  (depletes quickly  → 422)");
        $this->command->info("  JSON      : {$outputFile}");
        $this->command->info('');
        $this->command->info('Expected under Apache (~500 checkouts/90s):');
        $this->command->info('  503 (lock busy)    < 20   — 260 products → low contention');
        $this->command->info('  422 (out of stock) 30-50  — hot products deplete after ~50 hits');
        $this->command->info('');
        $this->command->info('Run:');
        $this->command->info('  k6 run --env BASE_URL=http://localhost:8000 scripts/k6_stress_test.js');
    }
}
