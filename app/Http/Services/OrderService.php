<?php

namespace App\Http\Services;

use App\Http\Repositories\Interfaces\OrderRepositoryInterface;
use App\Http\Services\Interfaces\OrderServiceInterface;
use App\Libraries\Analyzers\Maglumi;
use App\Models\Analyzer;
use App\Models\AnalyzerType;
use App\Models\Order;

class OrderService implements OrderServiceInterface
{
    private OrderRepositoryInterface $orderRepository;

    public function __construct(OrderRepositoryInterface $orderRepository)
    {
        $this->orderRepository = $orderRepository;
    }


    public function getOrderString(mixed $order_data): string
    {
        return $this->orderRepository->getOrderString($order_data);
    }

    public function processOrders(): void
    {
        // get unprocessed orders not older than 3 days
        $unprocessed_orders = Order::where('status', 'unprocessed')
            ->where('created_at', '>=', now()->subDays(3))
            ->get();

        foreach ($unprocessed_orders as $order) {
            // todo: process order
        }
    }

    public function processOrder($order_data): string
    {
        return 'Order sent to analyzer';
    }
}
