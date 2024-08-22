<?php

namespace App\Http\Repositories;

use App\Http\Repositories\Interfaces\OrderRepositoryInterface;
use App\Models\Analyzer;
use App\Models\AnalyzerType;
use App\Models\Order;

class OrderRepository implements OrderRepositoryInterface
{

    public function getOrderString(mixed $order_data)
    {
        return Order::where('c_order_id', $order_data['c_order_id'])
            ->where('external_id', $order_data['external_id'])
            ->where('order_barcode', $order_data['order_barcode'])
            ->first()
            ->order_record;
    }

    public function getAnalyzer(mixed $order)
    {
        return Analyzer::where('analyzer_id', $order['analyzer_id'])
            ->where('lab_id', $order['lab_id'])
            ->where('is_active', 1)
            ->first();
    }

    public function getAnalyzerType($analyzer)
    {
        return AnalyzerType::where('id', $analyzer->type_id)->first();
    }
}
