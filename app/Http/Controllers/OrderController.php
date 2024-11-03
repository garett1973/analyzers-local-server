<?php

namespace App\Http\Controllers;

use App\Events\NewOrderReceivedEvent;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;

class OrderController extends Controller
{

    /**
     * @throws JsonException
     */
    public function importOrder(Request $request): JsonResponse
    {
        $order_data = $request->all();

        if (isset($order_data['test_ids'])) {
            $order_data['test_ids'] = json_encode($order_data['test_ids'], JSON_THROW_ON_ERROR);
        }

        $order = new Order($order_data);
        $order->save();

        event(new NewOrderReceivedEvent($order));

        return new JsonResponse([
            'status' => 'success',
            'message' => 'Order imported',
        ],
            200);
    }
}
