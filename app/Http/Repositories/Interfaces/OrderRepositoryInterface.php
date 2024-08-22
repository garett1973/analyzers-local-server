<?php

namespace App\Http\Repositories\Interfaces;

interface OrderRepositoryInterface
{

    public function getOrderString(mixed $order_data);

    public function getAnalyzer(mixed $order);

    public function getAnalyzerType($analyzer);
}
