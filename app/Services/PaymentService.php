<?php

namespace App\Services;

use App\Models\Order;

class PaymentService
{
    public function pay(Order $order): array
    {
        return [
            'status' => 'success',
            'transaction_id' => 'FAKE-' . $order->id,
        ];
    }
}
