<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class EcommerceDemoSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            ['name' => 'Test User 1', 'email' => 'user1@test.com'],
            ['name' => 'Test User 2', 'email' => 'user2@test.com'],
            ['name' => 'Test User 3', 'email' => 'user3@test.com'],
            ['name' => 'Test User 4', 'email' => 'user4@test.com'],
            ['name' => 'Test User 5', 'email' => 'user5@test.com'],
        ];

        foreach ($users as $userData) {
            User::query()->updateOrCreate(
                ['email' => $userData['email']],
                [
                    'name' => $userData['name'],
                    'password' => Hash::make('password123'),
                ]
            );
        }

        $products = [
            [
                'name' => 'Demo Product A',
                'price' => 99.99,
                'stock' => 1,
                'description' => 'Product with intentionally small stock for concurrency demo.',
            ],
            [
                'name' => 'Demo Product B',
                'price' => 149.99,
                'stock' => 2,
                'description' => 'Second product for checkout testing.',
            ],
            [
                'name' => 'Demo Product C',
                'price' => 59.50,
                'stock' => 8,
                'description' => 'General testing product.',
            ],
            [
                'name' => 'Demo Product D',
                'price' => 219.00,
                'stock' => 5,
                'description' => 'General testing product with medium stock.',
            ],
            [
                'name' => 'Demo Product E',
                'price' => 15.99,
                'stock' => 20,
                'description' => 'Low-cost item for cart and order flow testing.',
            ],
        ];

        foreach ($products as $productData) {
            Product::query()->updateOrCreate(
                ['name' => $productData['name']],
                $productData
            );
        }
    }
}
