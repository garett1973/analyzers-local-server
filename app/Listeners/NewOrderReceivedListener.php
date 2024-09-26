<?php

namespace App\Listeners;

use App\Http\Services\Interfaces\OrderServiceInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class NewOrderReceivedListener
{
    private OrderServiceInterface $orderService;


    /**
     * Create the event listener.
     */
    public function __construct(OrderServiceInterface $orderService)
    {
        $this->orderService = $orderService;
    }

    /**
     * Handle the event.
     */
    public function handle(object $event): void
    {
        $order = $event->order;
        $this->orderService->createOrderRecord($order);
    }
}
