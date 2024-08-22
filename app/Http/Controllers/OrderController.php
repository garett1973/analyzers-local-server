<?php

namespace App\Http\Controllers;

use App\Http\Services\Interfaces\OrderServiceInterface;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    private OrderServiceInterface $orderService;

    public function __construct(OrderServiceInterface $orderService)
    {
        $this->orderService = $orderService;
    }

    public function importOrder(Request $request): JsonResponse
    {
        $order_data = $request->all();

        $order = new Order($order_data);
        $order->save();

        $processing_result = $this->orderService->processOrder($order_data);

        if ($processing_result) {
            return new JsonResponse([
                'status' => 'success',
                'message' => 'Order processed',
                'data' => $processing_result,
            ],
                200);
        } else {
            return new JsonResponse([
                'status' => 400,
                'error' => 'Order not processed',
            ],
                400);
        }
    }
}
