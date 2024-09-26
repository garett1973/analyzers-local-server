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

    public function processOrder($order_data): string
    {
        return 'Order sent to analyzer';
    }

    public function createOrderRecord($order)
    {
        $analyzer = Analyzer::where('analyzer_id', $order->analyzer_id)->first();

    }
}
