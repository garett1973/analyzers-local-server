<?php

namespace App\Http\Services;

use App\Http\Repositories\Interfaces\OrderRepositoryInterface;
use App\Http\Services\Interfaces\OrderServiceInterface;
use App\Models\Analyzer;
use App\Models\AnalyzerType;

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

    public function createOrderRecord($order): void
    {
        $analyzer = Analyzer::where('analyzer_id', $order->analyzer_id)->first();
        $analyzer_type_name = AnalyzerType::where('id', $analyzer->type_id)->first()->name;
        $analyzer_library = 'App\\Libraries\\Analyzers\\' . $analyzer_type_name;

        $converter = new $analyzer_library();
        $order_record = $converter->createOrderRecord($order);

        $order->order_record = $order_record;
        $order->save();
    }
}
