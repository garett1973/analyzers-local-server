<?php

namespace App\Http\Services\Interfaces;

use App\Models\Order;

interface OrderServiceInterface
{
    public function getOrderString(mixed $order_data): string;

    public function processOrder($order_data): string;

    public function createOrderRecord($order);
}
