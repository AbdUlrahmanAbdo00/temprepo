<?php

namespace Tests\Feature;

use App\Jobs\GenerateOrderSummaryJob;
use App\Models\Order;
use App\Models\User;
use App\Services\CheckoutService;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CheckoutDispatchTest extends TestCase
{
    public function test_checkout_dispatches_summary_job_and_returns_success(): void
    {
        Queue::fake();

        $user = User::factory()->make();
        Sanctum::actingAs($user);

        $order = new Order([
            'user_id' => $user->id,
            'total_price' => 99.99,
            'status' => 'pending',
        ]);

        $order->setRelation('items', collect());

        $this->mock(CheckoutService::class, function ($mock) use ($order) {
            $mock->shouldReceive('checkout')
                ->once()
                ->andReturn($order);
        });

        $response = $this->postJson('/api/checkout');

        $response->assertCreated();
        $response->assertJsonPath('message', 'Checkout completed.');

        Queue::assertPushed(GenerateOrderSummaryJob::class);
    }
}