<?php

namespace App\Http\Services\Interfaces;

interface OrderServiceInterface
{
    public function getOrderString(mixed $order_data): string;

    public function processOrders();

    public function processOrder(mixed $order);
}
